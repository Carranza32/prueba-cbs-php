<?php

namespace CBSNorthStar\Services;

use CBSNorthStar\Logger\CBSLogger;
use CBSNorthStar\Logger\DeployRunLogger;
use CBSNorthStar\Repositories\DeployRunRepository;
use CBSNorthStar\Services\DeployQueueService;

/**
 * DeployLockService — concurrency control for the Northstar product deploy process.
 *
 * Prevents overlapping deploy runs regardless of trigger source (manual button,
 * webhook, cron). Detects and records all conflict scenarios for the Product
 * Report Deploy admin page.
 *
 * ## Storage strategy
 *
 * Two layers work together:
 *
 *   1. WordPress Object Cache (Memcached when available)
 *      Used as the fast atomic gate. wp_cache_add() succeeds only when the key
 *      does not exist — this is the Memcached ADD command, which is atomic at
 *      the server level. Two concurrent PHP processes cannot both win.
 *
 *   2. Database (cbs_product_run_log via DeployRunRepository)
 *      The source of truth. Every lock event is mirrored to the DB so the
 *      audit trail and admin report are complete even if the cache is flushed,
 *      evicted, or the server is restarted.
 *
 * ## Memcached caveat
 *
 * If no external object cache is configured, WordPress falls back to a
 * per-process in-memory cache. In that case the lock only prevents duplicate
 * runs within the SAME PHP process — it does NOT protect against two separate
 * PHP workers (e.g. an AJAX request and a webhook arriving simultaneously).
 * Call isUsingExternalCache() to detect this condition. The DB-based stale
 * check provides a secondary guard in that scenario.
 *
 * ## Race-condition transparency
 *
 * There is a narrow window between reading the existing lock payload and
 * deleting it during stale recovery. Two processes could both decide a lock
 * is stale simultaneously. The second wp_cache_add() call after the delete
 * re-establishes atomicity: only one process can win that ADD. The loser will
 * see blocked = true and log a conflict event. No run will be lost.
 *
 * Usage:
 *   $result = DeployLockService::create()->tryAcquire(
 *       DeployRunRepository::TRIGGER_MANUAL,
 *       get_current_user_id(),
 *       'admin_button'
 *   );
 *   if ( $result->wasBlocked() ) { ... }
 *   $runId = $result->active_run_id;
 *   // ... do work ...
 *   DeployLockService::create()->release( $runId );
 */
class DeployLockService
{
    // -------------------------------------------------------------------------
    // Lock configuration constants
    // -------------------------------------------------------------------------

    /** Cache key used for the global deploy lock. */
    const LOCK_KEY = 'cbs_deploy_lock';

    /**
     * Cache group. Use a dedicated group so the lock is never evicted by
     * unrelated cache operations and is easy to inspect with cache tooling.
     */
    const LOCK_GROUP = 'cbs_deploy';

    /**
     * Default lock TTL in seconds (10 minutes).
     * Override via: add_filter( 'cbs_deploy_lock_ttl', fn() => 900 );
     */
    const DEFAULT_TTL = 600;

    /**
     * Default stale threshold in seconds (5 minutes).
     * A running run whose last_heartbeat_at (or started_at) is older than this
     * is considered stale and may be force-released by the next incoming run.
     * Override via: add_filter( 'cbs_deploy_lock_stale_threshold', fn() => 180 );
     */
    const DEFAULT_STALE_THRESHOLD = 300;

    /**
     * Seconds after lock acquisition with no heartbeat before the worker is
     * considered to have never started. Used exclusively in handleProgress()
     * where the JS is actively polling and confirming zero visual progress —
     * a stronger signal than the raw epoch comparison used in tryAcquire().
     *
     * 90 s rather than 60 s: on Pressable the prior run's doing_cron transient
     * (60 s TTL) can block the new loopback until it expires, after which the
     * next organic page load re-fires spawn_cron. Observed worst-case: run
     * 68348675 (2026-06-19) had its cron fire at T=62 s — a race the 60 s
     * threshold made inevitable. 90 s gives 28 s of headroom past that while
     * still detecting a truly dead cron 30 s faster than WORKER_CRASH_THRESHOLD.
     */
    const WORKER_NEVER_STARTED_THRESHOLD = 90;

    /**
     * Seconds after worker_started_epoch with no heartbeat before a started-
     * but-hung worker is reaped. Applied only when worker_started_epoch is
     * present (the process reached runBackgroundDeploy) but heartbeat_epoch
     * has not advanced beyond it (no site has completed). 120 s gives enough
     * grace for slow pre-site setup (OLO API calls, snapshot init) without
     * masking genuine hangs.
     */
    const WORKER_CRASH_THRESHOLD = 120;

    /**
     * wp_options option_name used as the DB-based cross-process lock.
     *
     * MySQL's UNIQUE constraint on option_name makes INSERT IGNORE atomic
     * across all PHP-FPM workers — only one process can win the INSERT,
     * regardless of whether a persistent object cache is active.
     */
    const DB_LOCK_KEY = 'cbs_deploy_db_lock';

    // -------------------------------------------------------------------------
    // Singleton
    // -------------------------------------------------------------------------

    /** @var self|null */
    private static ?self $instance = null;

    /** @var DeployRunLogger */
    private DeployRunLogger $logger;

    /** @var DeployRunRepository */
    private DeployRunRepository $repository;

    private function __construct()
    {
        $this->logger     = DeployRunLogger::create();
        $this->repository = DeployRunRepository::create();
    }

    /**
     * Return (or create) the singleton instance.
     *
     * @return self
     */
    public static function create(): self
    {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Attempt to acquire the global deploy lock for an incoming run.
     *
     * This is the single entry point for ALL deploy triggers. It handles:
     *   - Clean acquisition when no run is active.
     *   - Conflict detection and logging when a run is already in progress.
     *   - Stale lock detection and recovery when an old run has gone silent.
     *   - Orphaned cache key cleanup (Memcached evicted the key mid-run).
     *
     * A structured DeployLockResult is always returned — never throws.
     * The run row is created inside this method regardless of outcome so
     * every attempt (including blocked ones) appears in the deploy report.
     *
     * @param string      $triggerType   One of DeployRunRepository::TRIGGER_* constants.
     * @param int|null    $userId        WP user ID if this is a manual trigger.
     * @param string|null $triggerSource Human-readable origin label (hook name, button slug, etc.).
     * @param array|null  $siteIds       Optional deploy scope (site IDs). Null = full deploy.
     *                                   Only used to carry the scope into the pending queue
     *                                   when this request gets queued behind an active run.
     * @return DeployLockResult
     */
    public function tryAcquire(
        string  $triggerType,
        ?int    $userId        = null,
        ?string $triggerSource = null,
        ?array  $siteIds       = null
    ): DeployLockResult {
        $runId   = wp_generate_uuid4();
        $ttl     = $this->getLockTtl();
        $payload = $this->buildLockPayload( $runId, $triggerType, $userId );

        // ------------------------------------------------------------------
        // Step 1: DB-based cross-process lock (primary gate).
        //
        // MySQL's UNIQUE constraint on wp_options.option_name makes
        // INSERT IGNORE atomic across ALL PHP-FPM workers — only one process
        // can win the INSERT, regardless of whether an external object cache
        // (Redis/Memcached) is active. Without this gate, two simultaneous
        // webhook deliveries on separate PHP workers would both succeed
        // wp_cache_add() (each worker has its own in-process cache) and kick
        // off concurrent deploys.
        //
        // Flow:
        //   - Stale DB lock (no heartbeat within $ttl)? Conditionally delete it.
        //   - INSERT IGNORE wins → continue to cache + startRun.
        //   - INSERT IGNORE loses → queue and return immediately.
        //
        // Staleness is judged on the lock's heartbeat (refreshed throughout a
        // running deploy by heartbeat()), NOT on lock age: deploys routinely
        // run longer than the TTL, and judging on acquired_at force-stole the
        // lock from healthy long runs — starting a second concurrent deploy
        // that wiped daypart tables the first run was mid-way rebuilding.
        // ------------------------------------------------------------------
        $existingDbLockRaw = $this->readDbLockRaw();
        $existingDbLock    = $this->decodeDbLock( $existingDbLockRaw );

        CBSLogger::general()->info( 'DeployLockService::tryAcquire — DB lock state', [
            'run_id'          => $runId,
            'trigger_type'    => $triggerType,
            'existing_db_lock' => $existingDbLock,
            'ext_cache'       => $this->isUsingExternalCache() ? 'yes' : 'no',
        ] );

        if ( $existingDbLock !== null ) {
            // Epoch fields are authoritative (immune to WP timezone offsets);
            // strtotime() on the mysql string is a legacy-payload fallback only.
            $lastActiveEpoch = (int) ( $existingDbLock['heartbeat_epoch']
                ?? $existingDbLock['acquired_epoch']
                ?? strtotime( $existingDbLock['acquired_at'] ?? '' ) );
            $dbLockElapsed = $lastActiveEpoch > 0 ? ( time() - $lastActiveEpoch ) : PHP_INT_MAX;

            // Two-phase stale detection mirroring handleProgress():
            //
            // Phase 1 (epoch absent): no worker_started_epoch means the cron loopback
            // never reached runBackgroundDeploy. Use DEFAULT_STALE_THRESHOLD (300 s)
            // here — tryAcquire() lacks the JS-polling signal, so 300 s avoids
            // false-evicting slow-starting workers that haven't written the epoch yet.
            //
            // Phase 2 (epoch present, heartbeat stalled): worker reached runBackgroundDeploy
            // but no heartbeat has advanced past worker_started_epoch. Apply
            // WORKER_CRASH_THRESHOLD (120 s) — enough for any legitimate setup phase.
            //
            // Healthy worker: heartbeat_epoch > worker_started_epoch → nothing triggers.
            $hbEpoch          = (int) ( $existingDbLock['heartbeat_epoch']     ?? 0 );
            $acqEpoch         = (int) ( $existingDbLock['acquired_epoch']       ?? 0 );
            $workerStartedEp  = (int) ( $existingDbLock['worker_started_epoch'] ?? 0 );
            $workerStarted    = $workerStartedEp > 0;
            $neverHeartbeated = $hbEpoch > 0 && $acqEpoch > 0 && $hbEpoch === $acqEpoch;

            if ( $workerStarted && $hbEpoch <= $workerStartedEp ) {
                // Epoch present but heartbeat hasn't advanced past it.
                $workerStartedElapsed = time() - $workerStartedEp;
                if ( $workerStartedElapsed > self::WORKER_CRASH_THRESHOLD ) {
                    CBSLogger::general()->warning( 'DeployLockService::tryAcquire — worker-started-no-heartbeat lock force-deleted', [
                        'run_id'                 => $runId,
                        'stale_run_id'           => $existingDbLock['run_id'] ?? '',
                        'worker_started_elapsed' => $workerStartedElapsed,
                        'stale_reason'           => 'worker_started_no_heartbeat',
                    ] );
                    $staleRunId = $existingDbLock['run_id'] ?? '';
                    if ( $this->deleteDbLockIfValue( $existingDbLockRaw ) ) {
                        $this->reapStaleRun( $staleRunId, 'worker_started_no_heartbeat', false );
                        $existingDbLock = null;
                    }
                }

            } elseif ( ! $workerStarted && $neverHeartbeated && $dbLockElapsed > self::DEFAULT_STALE_THRESHOLD ) {
                // Epoch absent: cron loopback was blocked — worker never launched.
                CBSLogger::general()->warning( 'DeployLockService::tryAcquire — worker-never-started lock force-deleted', [
                    'run_id'          => $runId,
                    'stale_run_id'    => $existingDbLock['run_id'] ?? '',
                    'elapsed_seconds' => $dbLockElapsed,
                    'stale_reason'    => 'worker_never_started',
                ] );
                $staleRunId = $existingDbLock['run_id'] ?? '';
                // Reap the orphaned run row ONLY when our conditional delete
                // actually removed the lock. If the value changed in the
                // read→delete window (the owner heartbeated or a newer run
                // acquired), the delete is a no-op and the run is still alive —
                // marking it stale here would clobber a healthy run, defeating
                // the compare-and-delete guard. Skip the release (releaseLock =
                // false): the lock is already gone when we get here.
                if ( $this->deleteDbLockIfValue( $existingDbLockRaw ) ) {
                    $this->reapStaleRun( $staleRunId, 'worker_never_started', false );
                    $existingDbLock = null;
                }

            } elseif ( $dbLockElapsed > $ttl ) {
                // No heartbeat within TTL — the owning process started but died
                // without releasing the lock. Delete it conditionally on the exact
                // value we read: if the owner heartbeats or a new run acquires
                // between our read and this delete, the value no longer
                // matches and we delete nothing (no healthy-lock kill).
                CBSLogger::general()->warning( 'DeployLockService::tryAcquire — stale DB lock detected, force-deleting', [
                    'run_id'          => $runId,
                    'stale_run_id'    => $existingDbLock['run_id'] ?? '',
                    'elapsed_seconds' => $dbLockElapsed,
                    'ttl'             => $ttl,
                ] );
                $staleRunId = $existingDbLock['run_id'] ?? '';
                // Reap the orphaned run row ONLY when our conditional delete
                // actually removed the lock (see worker_never_started branch).
                // A no-op delete means the owner heartbeated in the window and
                // is still alive — do not reap it. Skip the release
                // (releaseLock = false): the lock is already gone when we get here.
                if ( $this->deleteDbLockIfValue( $existingDbLockRaw ) ) {
                    $this->reapStaleRun( $staleRunId, 'crashed_mid_run', false );
                    $existingDbLock = null;
                }
            }
        }

        $dbLockAcquired = $this->tryAcquireDbLock( $runId, $triggerType );

        CBSLogger::general()->info( 'DeployLockService::tryAcquire — DB lock attempt result', [
            'run_id'          => $runId,
            'db_lock_acquired' => $dbLockAcquired ? 'yes' : 'no',
        ] );

        if ( ! $dbLockAcquired ) {
            // Another PHP-FPM worker holds the DB lock. Queue this run so it
            // fires automatically after the active deploy finishes.
            $dbLockData       = $existingDbLock ?? $this->readDbLock();
            $incumbentRunId   = $dbLockData['run_id']       ?? '';
            $incumbentTrigger = $dbLockData['trigger_type'] ?? 'unknown';

            $message = sprintf(
                'Deploy queued. A %s run (%s) is already in progress — will run after it finishes.',
                $incumbentTrigger,
                $incumbentRunId ?: 'unknown'
            );

            $this->logger->appendEvent(
                $runId,
                DeployRunRepository::EVENT_LOCK_CONFLICT,
                DeployRunRepository::SEVERITY_WARNING,
                'Deploy blocked by DB lock — another PHP-FPM worker holds the cross-process lock.',
                [
                    'trigger_type'      => $triggerType,
                    'incumbent_run_id'  => $incumbentRunId,
                    'incumbent_trigger' => $incumbentTrigger,
                ]
            );

            DeployQueueService::create()->set( $triggerType, $userId, $triggerSource, $siteIds );

            return DeployLockResult::queued( $incumbentRunId, $incumbentTrigger, $message );
        }

        // ------------------------------------------------------------------
        // Step 2: DB lock acquired — we are the sole owner across all
        // PHP-FPM workers. Populate the cache for same-process fast-reads,
        // then start the run.
        //
        // Note: the old wp_cache_add()-based blocking logic (Steps 3–6) has
        // been superseded by the DB lock. Worker B is blocked at Step 1 and
        // never reaches here, so cache-based conflict detection is no longer
        // needed on this path.
        // ------------------------------------------------------------------
        if ( ! $this->isUsingExternalCache() ) {
            $this->logger->appendEvent(
                $runId,
                DeployRunRepository::EVENT_LOCK_CONFLICT,
                DeployRunRepository::SEVERITY_WARNING,
                'No external object cache detected. Using DB lock as cross-process gate.',
                [ 'trigger_type' => $triggerType ]
            );
        }

        // Populate the cache (best-effort — result is intentionally ignored).
        // This keeps the cache layer consistent for code that reads wp_cache_get().
        wp_cache_add( self::LOCK_KEY, $payload, self::LOCK_GROUP, $ttl );

        $this->logger->startRun( $triggerType, $userId, $triggerSource, $runId );

        return DeployLockResult::acquired( $runId );
    }

    /**
     * Release the deploy lock held by the given run.
     *
     * Verifies ownership before deleting the cache key — prevents a slow run
     * from accidentally releasing a lock that has already been acquired by a
     * newer run after stale recovery.
     *
     * Should always be called in a try/finally block so it runs even when
     * an exception is thrown mid-deploy.
     *
     * @param string $runId UUID of the run releasing the lock.
     * @return void
     */
    public function release( string $runId ): void
    {
        // Check DB lock ownership before releasing. If the lock is already gone
        // or belongs to a different run, log a warning and bail — we must not
        // delete a lock held by a newer run.
        $existingLock   = $this->readDbLock();
        $existingRunId  = $existingLock['run_id'] ?? null;

        if ( $existingLock === null || $existingRunId !== $runId ) {
            CBSLogger::general()->warning( 'DeployLockService::release — skipped: lock already cleared or belongs to different run', [
                'run_id'          => $runId,
                'found_run_id'    => $existingRunId,
            ] );
            return;
        }

        // Always release the DB lock first — it is the cross-process gate and
        // must be freed regardless of cache state. releaseDbLock() verifies
        // ownership before deleting, so it is safe to call unconditionally.
        CBSLogger::general()->info( 'DeployLockService::release — releasing DB lock', [ 'run_id' => $runId ] );
        $this->releaseDbLock( $runId );

        // Clean up the worker-started transient now that the run has ended normally.
        delete_transient( 'cbs_wse_' . $runId );

        // Cache release: with no external cache the key lives only in the worker
        // that acquired it, so wp_cache_get() may return false in this worker.
        // Treat that as "already gone" rather than a warning.
        $current = wp_cache_get( self::LOCK_KEY, self::LOCK_GROUP );

        if ( $current === false ) {
            return;
        }

        $lockOwner = $current['run_id'] ?? '';

        if ( $lockOwner !== $runId ) {
            // Cache key belongs to a different run (stale recovery scenario).
            // The DB lock was already released above; leave the cache key alone.
            $this->logger->appendEvent(
                $runId,
                DeployRunRepository::EVENT_LOCK_CONFLICT,
                DeployRunRepository::SEVERITY_WARNING,
                'Cache lock owner mismatch during release — cache key belongs to a different run. Not deleted.',
                [
                    'lock_owner_run_id' => $lockOwner,
                    'releasing_run_id'  => $runId,
                ]
            );
            return;
        }

        wp_cache_delete( self::LOCK_KEY, self::LOCK_GROUP );
    }

    /**
     * Finalize a run whose worker died: optionally release its lock, then flip
     * the run row to STALE — but only while it is still RUNNING, so a clean
     * completion that raced this call is never clobbered.
     *
     * Single source of truth for stale-reaping, shared by handleProgress()
     * (UI poll), tryAcquire() (next-deploy recovery) and the watchdog cron.
     * Staleness is judged by the caller on epoch fields; finished_at is written
     * in GMT (current_time('mysql', true)) so it never depends on the WP
     * timezone offset.
     *
     * @param string $runId       Run to reap (no-op when empty).
     * @param string $reason      Short machine reason recorded in error_message.
     * @param bool   $releaseLock Release the DB/cache lock first. tryAcquire()
     *                            passes false because it already conditionally
     *                            deleted the exact lock value (race-safe).
     * @return void
     */
    public function reapStaleRun( string $runId, string $reason, bool $releaseLock = true ): void
    {
        if ( $runId === '' ) {
            return;
        }

        if ( $releaseLock ) {
            $this->release( $runId );
        }

        // Guard: only finalize a row still RUNNING. A completion or failure that
        // landed between the staleness read and here must win.
        $run = $this->repository->findByRunId( $runId );
        if ( $run === null || $run['status'] !== DeployRunRepository::STATUS_RUNNING ) {
            return;
        }

        $this->repository->updateRun( $runId, [
            'status'        => DeployRunRepository::STATUS_STALE,
            'finished_at'   => current_time( 'mysql', true ),
            'error_message' => sprintf( 'Reaped as stale (%s) — worker died without releasing the lock.', $reason ),
        ] );

        // Clean up the worker-started transient so it does not persist after the run ends.
        delete_transient( 'cbs_wse_' . $runId );

        CBSLogger::general()->warning( 'DeployLockService::reapStaleRun — run finalized as stale', [
            'run_id'       => $runId,
            'stale_reason' => $reason,
        ] );
    }

    /**
     * Return true when an external persistent object cache (Memcached, Redis)
     * is active. When false, the lock is process-local only.
     *
     * @return bool
     */
    public function isUsingExternalCache(): bool
    {
        return (bool) wp_using_ext_object_cache();
    }

    /**
     * Force-clear the deploy lock for a specific run, initiated from the admin UI.
     *
     * This is the only public path that allows forcibly terminating a running lock
     * outside the normal try/finally release flow. It should only be called when the
     * admin has manually confirmed the run is stuck.
     *
     * Safety model:
     *  - The run MUST be status=running in the DB. A completed, failed, or blocked
     *    run has no lock to clear, so the call is rejected.
     *  - If the run is NOT yet stale by threshold, the clear still proceeds but a
     *    SEVERITY_ERROR audit event is written so the operator knows a potentially
     *    live run was force-killed. This lets admins unblock a genuinely hung process
     *    while keeping a clear paper trail.
     *  - The Memcached key is only deleted when the payload's run_id matches — prevents
     *    accidentally clearing a newer run's lock that re-acquired after stale recovery.
     *
     * @param string $runId       UUID of the stuck run.
     * @param int    $adminUserId WP user ID of the admin performing this action.
     * @return bool True if cleared; false when the DB run is not in running state.
     */
    public function adminForceClearLock( string $runId, int $adminUserId ): bool
    {
        $run = $this->repository->findByRunId( $runId );

        // Only allow clearing locks for runs the DB still considers running.
        if ( $run === null || $run['status'] !== DeployRunRepository::STATUS_RUNNING ) {
            return false;
        }

        $adminUser  = get_userdata( $adminUserId );
        $adminLogin = $adminUser ? $adminUser->user_login : "user#{$adminUserId}";

        // Determine staleness by threshold to calibrate log severity.
        $lastActive = ! empty( $run['last_heartbeat_at'] )
            ? $run['last_heartbeat_at']
            : ( $run['started_at'] ?? '' );

        $elapsed   = $lastActive ? ( time() - strtotime( $lastActive ) ) : PHP_INT_MAX;
        $threshold = $this->getStaleThreshold();
        $isStale   = $elapsed > $threshold;

        // Mark the run stale in the DB and record finished_at so duration calculates.
        $this->repository->updateRun( $runId, [
            'status'      => DeployRunRepository::STATUS_STALE,
            'finished_at' => current_time( 'mysql', true ),
        ] );

        // Severity escalates when the run might still have been live.
        $severity = $isStale
            ? DeployRunRepository::SEVERITY_WARNING
            : DeployRunRepository::SEVERITY_ERROR;

        $message = $isStale
            ? sprintf( 'Stale lock force-cleared by admin "%s".', $adminLogin )
            : sprintf(
                'Lock force-cleared by admin "%s" — run may not be stale yet (elapsed: %ds < threshold: %ds). Treat as potential data loss.',
                $adminLogin, $elapsed, $threshold
            );

        $this->repository->appendEvent(
            $runId,
            DeployRunRepository::EVENT_ADMIN_LOCK_CLEARED,
            $severity,
            $message,
            [
                'cleared_by_user_id' => $adminUserId,
                'cleared_by_login'   => $adminLogin,
                'elapsed_seconds'    => $elapsed,
                'stale_threshold_s'  => $threshold,
                'was_stale'          => $isStale,
            ]
        );

        CBSLogger::general()->warning( 'Deploy lock force-cleared by admin.', [
            'run_id'          => $runId,
            'cleared_by'      => $adminLogin,
            'elapsed_seconds' => $elapsed,
            'was_stale'       => $isStale,
        ] );

        // Delete the cache key only when this run owns it.
        $lockPayload = wp_cache_get( self::LOCK_KEY, self::LOCK_GROUP );
        if ( is_array( $lockPayload ) && ( $lockPayload['run_id'] ?? '' ) === $runId ) {
            wp_cache_delete( self::LOCK_KEY, self::LOCK_GROUP );
        }
        // If the cache key is already gone (evicted/expired), the DB update is
        // the authoritative record — still return true.

        // Always clear the DB lock for this run — admin force-clear is authoritative.
        $this->releaseDbLock( $runId );

        return true;
    }

    /**
     * Return elapsed seconds since the last heartbeat for the given run.
     *
     * Reads the DB lock directly (bypassing the object cache) and uses
     * heartbeat_epoch — a raw time() value immune to WP timezone offsets.
     * Returns PHP_INT_MAX when the lock is absent or belongs to a different run.
     *
     * Used by handleProgress() to detect a frozen background process without
     * needing access to the private readDbLock() helper.
     *
     * @param string $runId UUID of the run to inspect.
     * @return int Elapsed seconds, or PHP_INT_MAX if the lock is absent/foreign.
     */
    public function getHeartbeatElapsed( string $runId ): int
    {
        $lock = $this->readDbLock();

        if ( $lock === null || ( $lock['run_id'] ?? '' ) !== $runId ) {
            return PHP_INT_MAX;
        }

        $epoch = (int) ( $lock['heartbeat_epoch'] ?? $lock['acquired_epoch'] ?? 0 );

        return $epoch > 0 ? ( time() - $epoch ) : PHP_INT_MAX;
    }

    /**
     * Seconds elapsed since the lock was first acquired.
     *
     * Used alongside getHeartbeatElapsed() to detect "worker never started":
     * when both values are equal the heartbeat_epoch has never advanced past
     * acquired_epoch, meaning no heartbeat() call has ever fired.
     *
     * @param string $runId UUID of the run to inspect.
     * @return int Elapsed seconds since acquisition, or PHP_INT_MAX if the lock is absent/foreign.
     */
    public function getAcquiredElapsed( string $runId ): int
    {
        $lock = $this->readDbLock();

        if ( $lock === null || ( $lock['run_id'] ?? '' ) !== $runId ) {
            return PHP_INT_MAX;
        }

        $epoch = (int) ( $lock['acquired_epoch'] ?? 0 );

        return $epoch > 0 ? ( time() - $epoch ) : PHP_INT_MAX;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Determine whether the incumbent run should be considered stale.
     *
     * A run is stale when any of the following are true:
     *   1. Its DB row does not exist.
     *   2. Its DB status is not STATUS_RUNNING.
     *   3. Its last_heartbeat_at (or started_at) is older than the stale threshold.
     *
     * @param array $payload Lock payload read from the cache.
     * @return bool
     */
    private function isStale( array $payload ): bool
    {
        $incumbentRunId = $payload['run_id'] ?? '';

        if ( empty( $incumbentRunId ) ) {
            return true;
        }

        $run = $this->repository->findByRunId( $incumbentRunId );

        // No DB row — definitively stale.
        if ( $run === null ) {
            return true;
        }

        // Row exists but is no longer in a running state — stale.
        if ( $run['status'] !== DeployRunRepository::STATUS_RUNNING ) {
            return true;
        }

        // Use heartbeat if available; fall back to started_at.
        $lastActiveAt = ! empty( $run['last_heartbeat_at'] )
            ? $run['last_heartbeat_at']
            : $run['started_at'];

        if ( empty( $lastActiveAt ) ) {
            return true;
        }

        $threshold  = $this->getStaleThreshold();
        $lastActive = strtotime( $lastActiveAt );
        $elapsed    = time() - $lastActive;

        return $elapsed > $threshold;
    }

    /**
     * Attempt to force-release a stale lock and immediately re-acquire it.
     *
     * Race condition note:
     * Two concurrent processes can both decide a lock is stale and call this
     * method simultaneously. Both will call wp_cache_delete() (idempotent).
     * Only one will win the subsequent wp_cache_add() — that is the new
     * atomic gate. The loser returns false and falls through to blocked.
     *
     * @param array  $incumbentPayload The stale lock's cache payload.
     * @param string $newRunId         UUID of the incoming run.
     * @param array  $newPayload       Cache payload for the incoming run.
     * @param int    $ttl              Lock TTL for the new run.
     * @return bool True if the force-release and re-acquisition both succeeded.
     */
    private function tryForceRelease(
        array  $incumbentPayload,
        string $newRunId,
        array  $newPayload,
        int    $ttl
    ): bool {
        $incumbentRunId = $incumbentPayload['run_id'] ?? '';

        // Mark the stale run in the DB before touching the cache, so any
        // concurrent observer sees a consistent state.
        if ( ! empty( $incumbentRunId ) ) {
            $this->repository->updateRun( $incumbentRunId, [
                'status'      => DeployRunRepository::STATUS_STALE,
                'finished_at' => current_time( 'mysql', true ),
            ] );

            $this->logger->appendEvent(
                $incumbentRunId,
                DeployRunRepository::EVENT_LOCK_CONFLICT,
                DeployRunRepository::SEVERITY_WARNING,
                'Run marked stale — no heartbeat within threshold. Force-released by incoming run.',
                [
                    'detected_by_run_id' => $newRunId,
                    'stale_threshold_s'  => $this->getStaleThreshold(),
                ]
            );
        }

        // Delete the stale cache key and DB lock, then race to re-acquire.
        wp_cache_delete( self::LOCK_KEY, self::LOCK_GROUP );
        $this->forceDeleteDbLock();

        $cacheAcquired = (bool) wp_cache_add( self::LOCK_KEY, $newPayload, self::LOCK_GROUP, $ttl );

        if ( $cacheAcquired ) {
            // Re-acquire the DB lock for the new run.
            $this->tryAcquireDbLock( $newRunId, $newPayload['trigger_type'] ?? '' );
        }

        return $cacheAcquired;
    }

    /**
     * Create a run row and immediately mark it blocked.
     * Used for all denied acquisition paths.
     *
     * The run is registered with status = blocked so every conflict appears
     * in the Product Report Deploy admin page, even when the run never started.
     *
     * @param string      $runId
     * @param string      $triggerType
     * @param int|null    $userId
     * @param string|null $triggerSource
     * @param string      $conflictType  One of DeployRunRepository::CONFLICT_* constants.
     * @param string      $incumbentRunId UUID of the blocking run (empty string if unknown).
     * @param string      $incumbentTrigger trigger_type of the blocking run.
     * @param string      $message       Human-readable conflict description.
     * @return DeployLockResult
     */
    private function createAndBlockRun(
        string  $runId,
        string  $triggerType,
        ?int    $userId,
        ?string $triggerSource,
        string  $conflictType,
        string  $incumbentRunId,
        string  $incumbentTrigger,
        string  $message
    ): DeployLockResult {
        // Create the run row (status = running, will be overwritten to blocked).
        $this->repository->createRun( $runId, $triggerType, $userId, $triggerSource );

        // Transition to blocked and record the conflict event.
        $this->logger->blockRun(
            $runId,
            $conflictType,
            $incumbentRunId ?: null
        );

        return DeployLockResult::blocked( $incumbentRunId, $incumbentTrigger, $message );
    }

    /**
     * Map the pair of trigger types to the correct CONFLICT_* constant.
     *
     * @param string $incumbentTrigger trigger_type of the run currently holding the lock.
     * @param string $incomingTrigger  trigger_type of the run being denied.
     * @return string One of DeployRunRepository::CONFLICT_* constants.
     */
    private function detectConflictType( string $incumbentTrigger, string $incomingTrigger ): string
    {
        $map = [
            DeployRunRepository::TRIGGER_MANUAL . '+' . DeployRunRepository::TRIGGER_MANUAL
                => DeployRunRepository::CONFLICT_DUPLICATE_MANUAL,

            DeployRunRepository::TRIGGER_MANUAL . '+' . DeployRunRepository::TRIGGER_HOOK
                => DeployRunRepository::CONFLICT_HOOK_BLOCKED_BY_MANUAL,

            DeployRunRepository::TRIGGER_HOOK . '+' . DeployRunRepository::TRIGGER_MANUAL
                => DeployRunRepository::CONFLICT_MANUAL_BLOCKED_BY_HOOK,

            DeployRunRepository::TRIGGER_BACKGROUND . '+' . DeployRunRepository::TRIGGER_MANUAL
                => DeployRunRepository::CONFLICT_MANUAL_BLOCKED_BY_BACKGROUND,

            DeployRunRepository::TRIGGER_MANUAL . '+' . DeployRunRepository::TRIGGER_BACKGROUND
                => DeployRunRepository::CONFLICT_BACKGROUND_BLOCKED_BY_MANUAL,
        ];

        $key = $incumbentTrigger . '+' . $incomingTrigger;

        return $map[ $key ] ?? DeployRunRepository::CONFLICT_DUPLICATE_MANUAL;
    }

    /**
     * Build the cache payload stored under the lock key.
     * Includes run_id, trigger_type, acquired_at, and user_id so that
     * any process reading the lock can identify the incumbent run.
     *
     * @param string   $runId
     * @param string   $triggerType
     * @param int|null $userId
     * @return array
     */
    private function buildLockPayload( string $runId, string $triggerType, ?int $userId ): array
    {
        return [
            'run_id'       => $runId,
            'trigger_type' => $triggerType,
            'acquired_at'  => current_time( 'mysql' ),
            'user_id'      => $userId,
        ];
    }

    /**
     * Return the lock TTL in seconds.
     * Filterable: add_filter( 'cbs_deploy_lock_ttl', fn() => 900 );
     *
     * @return int
     */
    private function getLockTtl(): int
    {
        return (int) apply_filters( 'cbs_deploy_lock_ttl', self::DEFAULT_TTL );
    }

    /**
     * Return the stale threshold in seconds.
     * Filterable: add_filter( 'cbs_deploy_lock_stale_threshold', fn() => 180 );
     *
     * @return int
     */
    private function getStaleThreshold(): int
    {
        return (int) apply_filters( 'cbs_deploy_lock_stale_threshold', self::DEFAULT_STALE_THRESHOLD );
    }

    // -------------------------------------------------------------------------
    // DB lock helpers
    // -------------------------------------------------------------------------

    /**
     * Attempt to acquire the DB-based cross-process lock via INSERT IGNORE.
     *
     * MySQL's UNIQUE constraint on wp_options.option_name guarantees that only
     * one INSERT can succeed across all concurrent PHP-FPM workers. Returns true
     * when this process wins the race, false when another process already holds
     * the lock.
     *
     * @param string $runId       UUID of the incoming run.
     * @param string $triggerType Trigger type stored in the lock row for diagnostics.
     * @return bool
     */
    private function tryAcquireDbLock( string $runId, string $triggerType ): bool
    {
        global $wpdb;

        // *_epoch fields are used for all staleness math — current_time('mysql')
        // is WP-local time, and comparing it against time() via strtotime()
        // mis-evaluates by the site's UTC offset. The mysql strings are kept
        // for human-readable diagnostics only.
        $now   = time();
        $value = wp_json_encode( [
            'run_id'          => $runId,
            'trigger_type'    => $triggerType,
            'acquired_at'     => current_time( 'mysql' ),
            'acquired_epoch'  => $now,
            'heartbeat_at'    => current_time( 'mysql' ),
            'heartbeat_epoch' => $now,
        ] );

        $rows = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                self::DB_LOCK_KEY,
                $value
            )
        );

        return ( (int) $rows ) === 1;
    }

    /**
     * Refresh the lock heartbeat for the active run. Called periodically during
     * a long deploy (per completed site and, throttled, per product batch) so
     * the stale check in tryAcquire() never steals the lock from a healthy run.
     *
     * The UPDATE is conditional on the exact option_value we read: if another
     * run took over between our read and the write, nothing matches and the
     * new owner's payload is left untouched.
     *
     * @param string $runId UUID of the run refreshing its heartbeat.
     * @return void
     */
    public function heartbeat( string $runId ): void
    {
        global $wpdb;

        $raw     = $this->readDbLockRaw();
        $current = $this->decodeDbLock( $raw );

        if ( $current === null || ( $current['run_id'] ?? '' ) !== $runId ) {
            return;
        }

        $current['heartbeat_at']    = current_time( 'mysql' );
        $current['heartbeat_epoch'] = time();

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                wp_json_encode( $current ),
                self::DB_LOCK_KEY,
                $raw
            )
        );

        CBSLogger::general()->info( 'DeployLockService::heartbeat', [
            'run_id'          => $runId,
            'memory_peak_mb'  => round( memory_get_peak_usage( true ) / 1048576, 1 ),
            'heartbeat_epoch' => $current['heartbeat_epoch'],
        ] );
    }

    /**
     * Record that the background deploy process has actually started executing.
     *
     * Called as the very first operation inside runBackgroundDeploy(), before
     * any per-site or per-product work. Writes worker_started_epoch to a
     * standalone WordPress transient keyed "cbs_wse_{$runId}" with a 1-hour TTL.
     * The transient write is unconditional and does not depend on the lock CAS,
     * so the epoch is reliably stored even on environments where concurrent
     * writes to wp_options cause the CAS to fail silently.
     *
     * A best-effort CAS is still attempted to refresh heartbeat_epoch inside the
     * lock payload for Phase 2 tracking, but its failure does not affect Phase 1.
     *
     * @param string $runId UUID of the current deploy run.
     * @return void
     */
    public function markWorkerStarted( string $runId ): void
    {
        global $wpdb;

        // Write the epoch to a standalone transient first — unconditional, no CAS.
        // This is the authoritative source for getWorkerStartedElapsed().
        $now = time();
        set_transient( 'cbs_wse_' . $runId, $now, 3600 );

        CBSLogger::general()->info( 'DeployLockService::markWorkerStarted — worker_started_epoch written', [
            'run_id'               => $runId,
            'worker_started_epoch' => $now,
        ] );

        // Best-effort: also refresh heartbeat_epoch in the lock payload so
        // Phase 2 has an accurate baseline. If the CAS fails (e.g., the lock was
        // already reaped or another process wrote to it), the transient above still
        // guarantees Phase 1 will not fire for this run.
        $raw     = $this->readDbLockRaw();
        $current = $this->decodeDbLock( $raw );

        if ( $current === null || ( $current['run_id'] ?? '' ) !== $runId ) {
            CBSLogger::general()->warning( 'DeployLockService::markWorkerStarted — lock missing or foreign, heartbeat_epoch CAS skipped', [
                'run_id'       => $runId,
                'found_run_id' => $current['run_id'] ?? null,
            ] );
            return;
        }

        $current['heartbeat_at']        = current_time( 'mysql' );
        $current['heartbeat_epoch']     = $now;
        $current['worker_started_epoch'] = $now;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                wp_json_encode( $current ),
                self::DB_LOCK_KEY,
                $raw
            )
        );
    }

    /**
     * Seconds elapsed since worker_started_epoch was written.
     *
     * Reads from the standalone transient "cbs_wse_{$runId}" set by
     * markWorkerStarted(). Returns PHP_INT_MAX when the transient is absent
     * (worker never reached runBackgroundDeploy, or transient expired/was deleted).
     * Returns elapsed seconds otherwise, regardless of whether heartbeat has advanced.
     *
     * @param string $runId UUID of the run to inspect.
     * @return int Elapsed seconds, or PHP_INT_MAX if epoch absent.
     */
    public function getWorkerStartedElapsed( string $runId ): int
    {
        $epoch = get_transient( 'cbs_wse_' . $runId );

        if ( $epoch === false ) {
            return PHP_INT_MAX;
        }

        return max( 0, time() - (int) $epoch );
    }

    /**
     * Read the current DB lock row's raw option_value.
     *
     * Reads via $wpdb directly (never get_option) so the value is not served
     * from a stale object cache.
     *
     * @return string|null Raw JSON string, or null if no lock exists.
     */
    private function readDbLockRaw(): ?string
    {
        global $wpdb;

        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                self::DB_LOCK_KEY
            )
        );

        return $raw === null ? null : (string) $raw;
    }

    /**
     * Decode a raw lock payload.
     *
     * @param string|null $raw Raw JSON string from readDbLockRaw().
     * @return array|null Decoded payload array, or null when absent/corrupt.
     */
    private function decodeDbLock( ?string $raw ): ?array
    {
        if ( $raw === null ) {
            return null;
        }

        $decoded = json_decode( $raw, true );

        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * Read the current DB lock row.
     *
     * @return array|null Decoded payload array, or null if no lock exists.
     */
    private function readDbLock(): ?array
    {
        return $this->decodeDbLock( $this->readDbLockRaw() );
    }

    /**
     * Release the DB lock only when the given run_id matches the stored owner.
     *
     * The delete is conditional on the exact value read, so a lock acquired by
     * a newer run between our read and the delete is never removed.
     *
     * @param string $runId UUID of the run releasing the lock.
     * @return void
     */
    private function releaseDbLock( string $runId ): void
    {
        $raw     = $this->readDbLockRaw();
        $current = $this->decodeDbLock( $raw );

        if ( $current === null || ( $current['run_id'] ?? '' ) !== $runId ) {
            return;
        }

        $this->deleteDbLockIfValue( $raw );
    }

    /**
     * Delete the DB lock row only if its value still equals $raw — an atomic
     * compare-and-delete that closes the read→delete race window.
     *
     * @param string|null $raw The exact option_value previously read.
     * @return bool True when this call actually deleted the lock (value still
     *              matched); false when the value had changed (a heartbeat or a
     *              newer acquisition landed in the window) so nothing was deleted.
     */
    private function deleteDbLockIfValue( ?string $raw ): bool
    {
        global $wpdb;

        if ( $raw === null ) {
            return false;
        }

        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                self::DB_LOCK_KEY,
                $raw
            )
        ) > 0;
    }

    /**
     * Unconditionally delete the DB lock row.
     * Used only by admin force-clear and explicit stale recovery, where the
     * operator (or recovery logic) has already decided the lock must go.
     *
     * @return void
     */
    private function forceDeleteDbLock(): void
    {
        global $wpdb;

        $wpdb->delete( $wpdb->options, [ 'option_name' => self::DB_LOCK_KEY ], [ '%s' ] );
    }
}
