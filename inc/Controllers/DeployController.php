<?php

namespace CBSNorthStar\Controllers;

use CBSNorthStar\Repositories\DeployRunRepository;
use CBSNorthStar\Services\DeployProgress;
use CBSNorthStar\Services\DeployLockService;
use CBSNorthStar\Services\DeployQueueService;
use CBSNorthStar\Logger\CBSLogger;
use CBSNorthStar\Logger\DeployRunLogger;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * DeployController — REST endpoints for the live deploy progress UI.
 *
 * Registers three routes under northstaronlineordering/v1/deploy/:
 *
 *   POST /deploy/start
 *     Acquires the deploy lock, initialises DeployProgress, schedules the
 *     deploy via WP-Cron (so the HTTP response returns immediately), and
 *     returns the runId. The button screen polls /deploy/progress with that runId.
 *
 *   GET  /deploy/progress?runId=xxx
 *     Returns the current DeployProgress transient payload. Also checks the
 *     DeployRunRepository for the authoritative run status when the transient
 *     is expired but the DB row exists.
 *
 *   POST /deploy/cancel
 *     Sets the cancelRequested flag in the progress transient. SaveProduct
 *     checks this flag between batches and stops cleanly.
 *
 * Registration:
 *   add_action( 'rest_api_init', [ DeployController::create(), 'registerRoutes' ] );
 *
 * The WP-Cron action must also be registered:
 *   add_action( 'cbs_run_deploy_background', [ DeployController::class, 'runBackgroundDeploy' ] );
 */
class DeployController
{
    const NAMESPACE  = 'northstaronlineordering/v1';
    const CRON_HOOK  = 'cbs_run_deploy_background';

    // -------------------------------------------------------------------------
    // Singleton
    // -------------------------------------------------------------------------

    private static ?self $instance = null;

    public static function create(): self
    {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // -------------------------------------------------------------------------
    // Route registration
    // -------------------------------------------------------------------------

    public function registerRoutes(): void
    {
        register_rest_route( self::NAMESPACE, '/deploy/start', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handleStart' ],
            'permission_callback' => [ $this, 'checkPermission' ],
            'args'                => [
                'skip_images' => [
                    'type'    => 'boolean',
                    'default' => false,
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/deploy/progress', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handleProgress' ],
            'permission_callback' => [ $this, 'checkPermission' ],
            'args'                => [
                'runId' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/deploy/cancel', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handleCancel' ],
            'permission_callback' => [ $this, 'checkPermission' ],
        ] );
    }

    /**
     * Only users with manage_options capability may trigger or inspect deploys.
     */
    public function checkPermission(): bool
    {
        return current_user_can( 'manage_options' );
    }

    // -------------------------------------------------------------------------
    // POST /deploy/start
    // -------------------------------------------------------------------------

    /**
     * Acquire the deploy lock, initialise progress, schedule background cron,
     * and return the runId immediately so the UI can start polling.
     */
    public function handleStart( WP_REST_Request $request ): WP_REST_Response
    {
        $lockService = DeployLockService::create();
        $lockResult  = $lockService->tryAcquire(
            DeployRunRepository::TRIGGER_MANUAL,
            get_current_user_id(),
            'admin_button_rest'
        );

        if ( $lockResult->wasBlocked() ) {
            if ( $lockResult->wasQueued() ) {
                // Request added to the pending queue — will run automatically.
                return new WP_REST_Response( [
                    'success'     => true,
                    'status'      => 'queued',
                    'activeRunId' => $lockResult->active_run_id,
                    'message'     => $lockResult->message,
                ], 202 );
            }

            return new WP_REST_Response( [
                'success'          => false,
                'status'           => 'locked',
                'activeRunId'      => $lockResult->active_run_id,
                'message'          => $lockResult->message ?? 'A deploy is already running.',
            ], 409 );
        }

        $runId     = $lockResult->active_run_id;
        $startedAt = current_time( 'mysql' );

        // Count Default sites so the UI can show totalSites immediately.
        $configuration = \CBSNorthStar\Repositories\ConfigurationRepository::create();
        $configDetails = $configuration->getDetails();
        $sites         = $configuration->getSiteDetails( $configDetails->id );
        $totalSites    = 0;
        if ( is_array( $sites ) || is_object( $sites ) ) {
            foreach ( $sites as $site ) {
                if ( ( $site->menu_type ?? '' ) === 'Default' ) {
                    $totalSites++;
                }
            }
        }

        // Initialise progress transient.
        DeployProgress::init( $runId, $totalSites, $startedAt );

        $jsonParams = $request->get_json_params() ?? [];
        $skipImages = isset( $jsonParams['skip_images'] ) ? (bool) $jsonParams['skip_images'] : false;

        CBSLogger::general()->info( 'Deploy start: skip_images param', [
            'skip_images' => $skipImages,
            'raw'         => $jsonParams['skip_images'] ?? '(not set)',
        ] );

        // Schedule background execution via WP-Cron.
        // spawn_cron() sends a non-blocking loopback request to wp-cron.php,
        // freeing this worker immediately so JavaScript poll requests can be
        // served throughout the deploy.
        // Args: runId, scope (null = all sites), force-full (admin deploys
        // always bypass the menu-hash skip so the button stays a true resync),
        // skip_images (UI checkbox overrides the Carbon Fields default).
        wp_schedule_single_event( time(), self::CRON_HOOK, [ $runId, null, true, $skipImages ] );
        $spawnResult = spawn_cron();
        // spawn_cron() returns false when the doing_cron transient is still fresh from the
        // previous (now-completed) cron run. Since we hold the deploy lock, clear the stale
        // transient and retry once so the loopback actually fires.
        $spawnRetry = null;
        if ( false === $spawnResult && ! defined( 'DOING_CRON' ) ) {
            // Intentional: the deploy lock is already held, so no other deploy is running.
            // spawn_cron() returned false only because the doing_cron transient is still
            // "fresh" from a now-completed cron run — WP never clears it after a job ends.
            // The !defined('DOING_CRON') guard ensures we are not inside wp-cron.php, so
            // there is no live cron process in this PHP context to race with.
            delete_transient( 'doing_cron' );
            $spawnRetry = spawn_cron();
        }
        $cronScheduled = wp_next_scheduled( self::CRON_HOOK, [ $runId, null, true, $skipImages ] ) !== false;
        $disableWpCron = ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON )
                         || ( defined( 'WP_DISABLE_CRON' ) && WP_DISABLE_CRON );

        $formatSpawn = static function ( $result ): string {
            if ( is_wp_error( $result ) ) {
                return $result->get_error_message();
            }
            return false === $result ? 'false' : 'ok';
        };

        CBSLogger::general()->info( 'Deploy started via REST', [
            'run_id'             => $runId,
            'user_id'            => get_current_user_id(),
            'total_sites'        => $totalSites,
            'spawn_result'       => $formatSpawn( $spawnResult ),
            'spawn_result_retry' => null !== $spawnRetry ? $formatSpawn( $spawnRetry ) : null,
            'cron_scheduled'     => $cronScheduled,
            'disable_wp_cron'    => $disableWpCron,
        ] );

        // Include the most recently finished run so the UI can notify the user
        // that the previous deploy failed or did not complete.
        $lastRun    = null;
        $lastRunRow = DeployRunRepository::create()->findLastFinishedRun( $runId );
        if ( $lastRunRow !== null ) {
            $lastRun = [
                'status'     => $lastRunRow['status'],
                'startedBy'  => self::resolveTriggerLabel(
                    $lastRunRow['user_login']     ?? null,
                    $lastRunRow['trigger_type']   ?? '',
                    $lastRunRow['trigger_source'] ?? null
                ),
                'message'    => $lastRunRow['error_message'] ?? '',
                'finishedAt' => $lastRunRow['finished_at']   ?? null,
            ];
        }

        return new WP_REST_Response( [
            'success'    => true,
            'runId'      => $runId,
            'totalSites' => $totalSites,
            'startedAt'  => $startedAt,
            'lastRun'    => $lastRun,
        ], 200 );
    }

    // -------------------------------------------------------------------------
    // GET /deploy/progress?runId=xxx
    // -------------------------------------------------------------------------

    /**
     * Return the current progress payload for the given runId.
     *
     * Falls back to the DeployRunRepository DB row when the transient has
     * expired, so the UI always gets a final status even after completion.
     */
    public function handleProgress( WP_REST_Request $request ): WP_REST_Response
    {
        $runId    = $request->get_param( 'runId' );
        $progress = DeployProgress::get( $runId );

        if ( $progress !== null ) {
            if ( $progress['status'] === 'running' ) {
                $lockService = DeployLockService::create();
                $runRepo     = DeployRunRepository::create();

                // DB-first check: if the run is already terminal in the DB, sync
                // the transient status and skip stale evaluation entirely. This
                // prevents false-positive stale marks when the transient cache
                // lags behind a clean completion (Pressable Redis propagation).
                $dbRun    = $runRepo->findByRunId( $runId );
                $dbStatus = $dbRun['status'] ?? null;

                $terminalStatuses = [ 'completed', 'partial_success', 'failed', 'stale', 'cancelled' ];

                if ( $dbStatus !== null && in_array( $dbStatus, $terminalStatuses, true ) ) {
                    $progress['status'] = $dbStatus;
                    CBSLogger::general()->debug( 'handleProgress: skipping stale check — run already terminal', [
                        'run_id'    => $runId,
                        'db_status' => $dbStatus,
                    ] );
                } else {
                    // DB is still running (or row not found) — proceed with staleness checks.
                    $heartbeatElapsed     = $lockService->getHeartbeatElapsed( $runId );
                    $acquiredElapsed      = $lockService->getAcquiredElapsed( $runId );
                    $workerStartedElapsed = $lockService->getWorkerStartedElapsed( $runId );

                    // Two-phase stale detection keyed on worker_started_epoch:
                    //
                    // Phase 1 — epoch absent (PHP_INT_MAX): the cron loopback never
                    // reached runBackgroundDeploy. After WORKER_NEVER_STARTED_THRESHOLD
                    // (60 s) with no heartbeat the worker is definitively gone.
                    //
                    // Phase 2 — epoch present: the process reached runBackgroundDeploy
                    // but no heartbeat has fired since (heartbeatElapsed ≈ workerStartedElapsed,
                    // meaning no site has completed yet). After WORKER_CRASH_THRESHOLD
                    // (120 s) the process is considered hung in setup.
                    //
                    // Healthy worker: heartbeat_epoch has advanced beyond worker_started_epoch
                    // → heartbeatElapsed < workerStartedElapsed → neither branch triggers.
                    if ( $workerStartedElapsed === PHP_INT_MAX
                         && $acquiredElapsed !== PHP_INT_MAX
                         && $heartbeatElapsed === $acquiredElapsed
                         && $acquiredElapsed > DeployLockService::WORKER_NEVER_STARTED_THRESHOLD ) {

                        // Epoch absent: cron loopback was blocked — worker never launched.
                        $lockService->reapStaleRun( $runId, 'worker_never_started' );
                        $progress['status']       = 'stale';
                        $progress['stale_reason'] = 'worker_never_started';
                        CBSLogger::general()->warning( 'handleProgress: run marked stale — worker never started', [
                            'run_id'           => $runId,
                            'acquired_elapsed' => $acquiredElapsed,
                        ] );
                        CBSLogger::general()->debug( 'handleProgress: stale recovery triggering pending queue drain', [
                            'run_id'       => $runId,
                            'stale_reason' => 'worker_never_started',
                        ] );
                        $fired = self::firePendingDeploy();
                        if ( $fired !== null ) {
                            $progress['nextRunId']        = $fired['runId'];
                            $progress['nextRunStartedBy'] = $fired['startedBy'];
                        }

                    } elseif ( $workerStartedElapsed !== PHP_INT_MAX
                               && $heartbeatElapsed >= $workerStartedElapsed
                               && $workerStartedElapsed > DeployLockService::WORKER_CRASH_THRESHOLD ) {

                        // Epoch present but heartbeat hasn't advanced past it: process reached
                        // runBackgroundDeploy but hung before completing any site.
                        $lockService->reapStaleRun( $runId, 'worker_started_no_heartbeat' );
                        $progress['status']       = 'stale';
                        $progress['stale_reason'] = 'worker_started_no_heartbeat';
                        CBSLogger::general()->warning( 'handleProgress: run marked stale — worker started but hung in setup', [
                            'run_id'                => $runId,
                            'worker_started_elapsed' => $workerStartedElapsed,
                        ] );
                        CBSLogger::general()->debug( 'handleProgress: stale recovery triggering pending queue drain', [
                            'run_id'       => $runId,
                            'stale_reason' => 'worker_started_no_heartbeat',
                        ] );
                        $fired = self::firePendingDeploy();
                        if ( $fired !== null ) {
                            $progress['nextRunId']        = $fired['runId'];
                            $progress['nextRunStartedBy'] = $fired['startedBy'];
                        }

                    } elseif ( $heartbeatElapsed > DeployLockService::DEFAULT_STALE_THRESHOLD ) {
                        // Regular path: heartbeat went silent mid-run (after at least one site completed).
                        $lockService->reapStaleRun( $runId, 'crashed_mid_run' );
                        $progress['status']       = 'stale';
                        $progress['stale_reason'] = 'crashed_mid_run';
                        CBSLogger::general()->warning( 'handleProgress: run marked stale — heartbeat frozen', [
                            'run_id'          => $runId,
                            'elapsed_seconds' => $heartbeatElapsed,
                        ] );
                        CBSLogger::general()->debug( 'handleProgress: stale recovery triggering pending queue drain', [
                            'run_id'       => $runId,
                            'stale_reason' => 'crashed_mid_run',
                        ] );
                        $fired = self::firePendingDeploy();
                        if ( $fired !== null ) {
                            $progress['nextRunId']        = $fired['runId'];
                            $progress['nextRunStartedBy'] = $fired['startedBy'];
                        }
                    }
                }
            }

            // Resolve startedBy for every response that has a live transient.
            // $dbRun is already set in the 'running' branch above; for non-running
            // statuses (terminal values already in the transient) fetch it now.
            if ( ! isset( $dbRun ) ) {
                $dbRun = DeployRunRepository::create()->findByRunId( $runId );
            }
            $progress['startedBy'] = self::resolveTriggerLabel(
                $dbRun['user_login']    ?? null,
                $dbRun['trigger_type']  ?? '',
                $dbRun['trigger_source'] ?? null
            );

            return new WP_REST_Response( $progress, 200 );
        }

        // Transient expired — check DB for final status.
        $run = DeployRunRepository::create()->findByRunId( $runId );

        if ( $run === null ) {
            return new WP_REST_Response( [
                'success' => false,
                'status'  => 'not_found',
                'message' => 'No deploy run found for the given runId.',
            ], 404 );
        }

        // Reconstruct a minimal payload from the DB row so the UI can show
        // final results even after the transient has been cleaned up.
        $payload = [
            'runId'                    => $run['run_id'],
            'status'                   => $run['status'],
            'startedBy'                => self::resolveTriggerLabel(
                $run['user_login']    ?? null,
                $run['trigger_type']  ?? '',
                $run['trigger_source'] ?? null
            ),
            'currentStep'              => ucfirst( $run['status'] ),
            'processedProducts'        => (int) ( $run['products_succeeded'] ?? 0 ),
            'failedCount'              => (int) ( $run['products_failed']    ?? 0 ),
            'skippedCount'             => (int) ( $run['products_skipped']   ?? 0 ),
            'percent'                  => in_array( $run['status'], [ 'completed', 'failed', 'stale' ], true ) ? 100 : 0,
            'estimatedSecondsRemaining' => 0,
            'startedAt'                => $run['started_at']  ?? null,
            'finishedAt'               => $run['finished_at'] ?? null,
            'updatedAt'                => $run['finished_at'] ?? $run['started_at'] ?? null,
            'message'                  => $run['error_message'] ?? '',
            'errors'                   => [],
        ];

        return new WP_REST_Response( $payload, 200 );
    }

    // -------------------------------------------------------------------------
    // POST /deploy/cancel
    // -------------------------------------------------------------------------

    /**
     * Set the cancelRequested flag. SaveProduct checks this between batches.
     */
    public function handleCancel( WP_REST_Request $request ): WP_REST_Response
    {
        $runId = sanitize_text_field( $request->get_param( 'runId' ) );

        if ( empty( $runId ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'runId is required.' ], 400 );
        }

        $progress = DeployProgress::get( $runId );
        if ( $progress === null ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Run not found or already finished.' ], 404 );
        }

        DeployProgress::requestCancel( $runId );
        CBSLogger::general()->info( 'Cancel requested via REST', [ 'run_id' => $runId ] );

        return new WP_REST_Response( [ 'success' => true, 'message' => 'Cancellation requested.' ], 200 );
    }

    // -------------------------------------------------------------------------
    // Background cron callback
    // -------------------------------------------------------------------------

    /**
     * Executes the deploy in the background cron context.
     *
     * This is called by WP-Cron after handleStart() schedules it.
     * The lock was already acquired and the runId already has a DB row.
     * We pass $runId directly into SaveProduct::store() to bypass the
     * legacy lock check inside store().
     *
     * Note: DeployOrchestrator::run() acquires the lock itself, so for the
     * REST-initiated background path we call SaveProduct::store() directly
     * to avoid a double-lock. The lock release happens in the finally block.
     *
     * @param string     $runId     UUID of the run, passed as cron argument.
     * @param array|null $siteIds   Optional deploy scope (site IDs) from a scoped
     *                              webhook trigger. Null = full deploy.
     * @param bool       $forceFull Bypass the menu-hash skip (admin/manual runs).
     */
    public static function runBackgroundDeploy( string $runId, ?array $siteIds = null, bool $forceFull = false, bool $skipImages = false ): void
    {
        // The spawn_cron() loopback request that runs this handler inherits the
        // web PHP execution limits. A deploy killed mid-site by max_execution_time
        // (or by the client disconnecting) is one of the "process cancelled"
        // paths that used to leave daypart tables half-written. The atomic swap
        // makes that safe, but the run should still get to finish.
        ignore_user_abort( true );
        $timeLimitBefore  = ini_get( 'max_execution_time' );
        $setTimeLimitOk   = false;
        if ( function_exists( 'set_time_limit' ) ) {
            $setTimeLimitOk = @set_time_limit( 0 ) !== false;
        }

        CBSLogger::general()->info( 'Background deploy started', [
            'run_id'            => $runId,
            'skip_images'       => $skipImages,
            'deploy_scope'      => $siteIds === null ? 'full' : implode( ',', $siteIds ),
            'pid'               => function_exists( 'getmypid' ) ? getmypid() : 'unavailable',
            'memory_limit'      => ini_get( 'memory_limit' ),
            'memory_usage_mb'   => round( memory_get_usage( true ) / 1048576, 1 ),
            'time_limit_before' => $timeLimitBefore,
            'set_time_limit_ok' => $setTimeLimitOk,
            'ext_object_cache'  => wp_using_ext_object_cache() ? 'yes' : 'no',
        ] );

        $lockService = DeployLockService::create();

        // Write worker_started_epoch immediately — proves the cron process reached
        // this point and resets heartbeat_epoch so the 60 s poller threshold only
        // fires for runs where the loopback truly never delivered to wp-cron.php.
        $lockService->markWorkerStarted( $runId );

        // Guard: if another trigger (e.g. the shutdown hook) already completed
        // this run, skip silently. This prevents double-execution when both the
        // shutdown hook and the WP-Cron fallback fire for the same runId.
        $existingProgress = DeployProgress::get( $runId );
        if ( $existingProgress !== null &&
             in_array( $existingProgress['status'], [ 'completed', 'failed', 'cancelled' ], true ) ) {
            CBSLogger::general()->info( 'Deploy skipped — already finished by another trigger', [ 'run_id' => $runId ] );
            if ( $existingProgress['status'] === 'completed' ) {
                DeployRunLogger::create()->completeRun( $runId );
            } else {
                $skipMsg = $existingProgress['message'] ?? 'Deploy skipped — already finished by another trigger';
                DeployRunLogger::create()->failRun( $runId, $skipMsg );
            }
            return;
        }

        // If cancel was requested before the trigger even fired, abort immediately.
        if ( DeployProgress::isCancelRequested( $runId ) ) {
            CBSLogger::general()->info( 'Background deploy aborted — cancel was requested before cron fired', [ 'run_id' => $runId ] );
            DeployProgress::cancel( $runId );
            DeployRunLogger::create()->failRun( $runId, 'Deploy cancelled before cron fired' );
            $lockService->release( $runId );
            return;
        }

        // Variables initialized before try so they remain accessible in finally
        // even when an exception is thrown before they would otherwise be set.
        $success         = false;
        $failMsg         = null;
        $liveProgress    = [];
        $progressCounts  = [];
        $logCounts       = [];
        $caughtThrowable = null;

        try {
            $result  = ( new \CBSNorthStar\SaveProduct() )->store( $runId, $siteIds, $forceFull, $skipImages );
            $success = is_array( $result ) ? (bool) ( $result['success'] ?? false ) : false;

            // Snapshot the live product counts now, before the terminal write
            // in finally. This ensures processedProducts is captured from the
            // running progress option before it is overwritten.
            $liveProgress = DeployProgress::get( $runId ) ?? [];

            CBSLogger::general()->debug( 'Background deploy — liveProgress snapshot', [
                'run_id'             => $runId,
                'processedProducts'  => $liveProgress['processedProducts'] ?? 'missing',
                'totalProducts'      => $liveProgress['totalProducts']     ?? 'missing',
                'transient_status'   => $liveProgress['status']            ?? 'null (transient missing)',
                'ext_object_cache'   => wp_using_ext_object_cache() ? 'yes' : 'no',
            ] );

            // Counts for DeployProgress (uses progress-style keys).
            $progressCounts = [
                'processedSites'    => (int) ( $result['products_attempted'] ?? 0 ),
                'processedProducts' => (int) ( $liveProgress['processedProducts'] ?? 0 ),
                'failedCount'       => (int) ( $liveProgress['failedCount']       ?? 0 ),
                'skippedCount'      => (int) ( $liveProgress['skippedCount']      ?? 0 ),
            ];

            // Counts for DeployRunLogger / DB (uses products_* keys from store()).
            $logCounts = [
                'products_attempted' => (int) ( $result['products_attempted'] ?? 0 ),
                'products_succeeded' => (int) ( $result['products_succeeded'] ?? 0 ),
                'products_failed'    => (int) ( $result['products_failed']    ?? 0 ),
                'products_skipped'   => (int) ( $result['products_skipped']   ?? 0 ),
            ];

            if ( ! $success ) {
                // Capture the failure message for the finally block.
                // If store() returned due to a cancel, liveStatus is 'cancelled' and
                // the cancel write already happened — failMsg stays null for that path.
                $liveStatus = $liveProgress['status'] ?? '';
                if ( $liveStatus !== 'cancelled' ) {
                    $failMsg = is_array( $result['message'] ?? null )
                        ? implode( '; ', $result['message'] )
                        : (string) ( $result['message'] ?? 'Unknown error' );
                }
            }
        } catch ( \Throwable $e ) {
            $caughtThrowable = $e;
            $failMsg         = $e->getMessage();
            CBSLogger::general()->error( 'Background deploy threw exception', [
                'run_id'  => $runId,
                'message' => $e->getMessage(),
            ] );
        } finally {
            $lockService->release( $runId );

            // Clear wp-cron.php's internal CAS lock so the next loopback can pass its
            // compare-and-swap check immediately, even on Redis with read-replica lag
            // (Pressable staging). firePendingDeploy() handles this for the queued path;
            // this covers the no-handoff path where no pending deploy follows.
            delete_option( 'doing_wp_cron' );

            // Fire the next queued deploy (if any) now that the lock is free.
            $fired            = self::firePendingDeploy();
            $nextRunId        = $fired ? $fired['runId']     : null;
            $nextRunStartedBy = $fired ? $fired['startedBy'] : null;

            // Write the terminal status and nextRunId in one atomic update_option()
            // call. This eliminates the race window between "fail written" and
            // "nextRunId written" that caused queued users to see finaliseDeploy()
            // instead of the handoff message.
            if ( $caughtThrowable !== null ) {
                DeployProgress::failWithHandoff( $runId, $failMsg ?? 'Unknown error', $nextRunId, $nextRunStartedBy );
                DeployRunLogger::create()->failRun( $runId, $failMsg ?? 'Unknown error', null, $caughtThrowable );
            } elseif ( $success ) {
                DeployProgress::completeWithHandoff( $runId, $progressCounts, $nextRunId, $nextRunStartedBy );
                DeployRunLogger::create()->completeRun( $runId, $logCounts );
            } else {
                $liveStatus = $liveProgress['status'] ?? '';
                if ( $liveStatus === 'cancelled' ) {
                    // Cancel was already written by store() — append nextRunId if a
                    // queued deploy fired so the queued user sees the handoff message.
                    if ( $nextRunId !== null ) {
                        DeployProgress::update( $runId, [
                            'nextRunId'        => $nextRunId,
                            'nextRunStartedBy' => $nextRunStartedBy,
                        ] );
                    }
                    DeployRunLogger::create()->failRun( $runId, 'Deploy cancelled' );
                } else {
                    DeployProgress::failWithHandoff( $runId, $failMsg ?? 'Unknown error', $nextRunId, $nextRunStartedBy );
                    DeployRunLogger::create()->failRun( $runId, $failMsg ?? 'Unknown error', null, null, $logCounts );
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Pending queue — fire the next deploy after the current one finishes
    // -------------------------------------------------------------------------

    /**
     * Check the pending queue and, if an entry exists, clear it and start a
     * new deploy immediately. Called in the finally block of runBackgroundDeploy()
     * so it always runs after the lock is released.
     *
     * Last-write-wins: the pending slot holds only the most recent queued
     * request. Any intermediate requests that arrived while the current deploy
     * was running have already been overwritten.
     *
     * @return array{runId:string,startedBy:string}|null Non-null when a pending deploy was fired.
     */
    private static function firePendingDeploy(): ?array
    {
        $queue   = DeployQueueService::create();
        $pending = $queue->get();

        if ( $pending === null ) {
            return null;
        }

        // Discard stale queue entries — anything older than the lock TTL
        // is from a previous deploy cycle and should not fire again.
        $requestedAt = strtotime( $pending['requested_at'] ?? '' );
        $queueAge    = $requestedAt ? ( time() - $requestedAt ) : PHP_INT_MAX;
        $lockTtl     = (int) apply_filters( 'cbs_deploy_lock_ttl', DeployLockService::DEFAULT_TTL );

        if ( $queueAge > $lockTtl ) {
            CBSLogger::general()->warning( 'Stale pending deploy discarded — queue entry is older than lock TTL', [
                'queued_at'      => $pending['requested_at'] ?? '',
                'age_seconds'    => $queueAge,
                'ttl_seconds'    => $lockTtl,
                'trigger_type'   => $pending['trigger_type'],
            ] );
            $queue->clear();
            return null;
        }

        // Clear the slot BEFORE acquiring the lock to prevent two concurrent
        // finishers from both reading and firing the same pending entry.
        $queue->clear();

        // Deploy scope carried from the queued request(s). Null = full deploy.
        $pendingSiteIds = $pending['site_ids'] ?? null;
        $pendingSiteIds = is_array( $pendingSiteIds ) && ! empty( $pendingSiteIds ) ? $pendingSiteIds : null;

        $lockService = DeployLockService::create();
        $lockResult  = $lockService->tryAcquire(
            $pending['trigger_type'],
            $pending['user_id'] ?? null,
            $pending['trigger_source'] ?? 'queue',
            $pendingSiteIds
        );

        if ( $lockResult->wasBlocked() ) {
            // Another run beat us to it — DeployLockService already re-queued
            // this pending entry if it was a genuine block.
            CBSLogger::general()->warning( 'Pending deploy could not acquire lock after queue fire', [
                'pending_trigger' => $pending['trigger_type'],
                'active_run_id'   => $lockResult->active_run_id,
            ] );
            return null;
        }

        $runId     = $lockResult->active_run_id;
        $startedAt = current_time( 'mysql' );

        $userLogin = null;
        if ( ! empty( $pending['user_id'] ) ) {
            $userData  = get_userdata( (int) $pending['user_id'] );
            $userLogin = $userData ? $userData->user_login : null;
        }
        $startedBy = self::resolveTriggerLabel(
            $userLogin,
            $pending['trigger_type']  ?? '',
            $pending['trigger_source'] ?? null
        );

        // Count Default sites for the progress bar total, restricted to the
        // queued scope so a scoped run's progress still reaches 100%.
        $configuration = \CBSNorthStar\Repositories\ConfigurationRepository::create();
        $configDetails = $configuration->getDetails();
        $sites         = $configuration->getSiteDetails( $configDetails->id );
        $totalSites    = 0;
        $scopeLower    = $pendingSiteIds === null ? null : array_map( 'strtolower', $pendingSiteIds );
        if ( is_array( $sites ) || is_object( $sites ) ) {
            foreach ( $sites as $site ) {
                if ( ( $site->menu_type ?? '' ) !== 'Default' ) {
                    continue;
                }
                if ( $scopeLower !== null && ! in_array( strtolower( (string) $site->siteid ), $scopeLower, true ) ) {
                    continue;
                }
                $totalSites++;
            }
        }

        DeployProgress::init( $runId, $totalSites, $startedAt );

        // Webhook-triggered queue entries may use the menu-hash skip; anything
        // manual/admin-originated stays a full resync.
        $pendingForceFull = ( $pending['trigger_type'] ?? '' ) !== \CBSNorthStar\Repositories\DeployRunRepository::TRIGGER_HOOK;

        // skip_images is intentionally omitted — the queue entry does not store the
        // originating button preference, so queued deploys always include images (default false).
        wp_schedule_single_event( time(), self::CRON_HOOK, [ $runId, $pendingSiteIds, $pendingForceFull ] );

        // spawn_cron() is always a no-op here because DOING_CRON is defined inside
        // wp-cron.php. Clear both wp-cron.php locks (this run is finished) then fire
        // a direct non-blocking loopback — identical to what spawn_cron() does internally
        // but without the DOING_CRON / doing_cron transient guards that would block it.
        //
        // Intentional: we are in the finally block — the current deploy is complete.
        // doing_wp_cron is wp-cron.php's internal CAS lock (microtime + 60). On Redis
        // environments with read replicas (Pressable staging), the CAS check in the
        // incoming wp-cron.php reads the stale lock value and exits without running hooks,
        // causing a 60-second dead zone. Deleting it here lets the incoming process start
        // immediately. doing_cron is spawn_cron()'s dispatch lock; clearing it releases
        // our own transient so the next queued deploy's loopback is not suppressed.
        delete_option( 'doing_wp_cron' );
        delete_transient( 'doing_cron' );
        $loopbackResult = wp_remote_post(
            site_url( 'wp-cron.php' ),
            [
                'timeout'   => 0.01,
                'blocking'  => false,
                'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
                'body'      => [],
            ]
        );

        $loopbackContext = [
            'run_id'         => $runId,
            'trigger_type'   => $pending['trigger_type'],
            'trigger_source' => $pending['trigger_source'] ?? 'queue',
            'queued_at'      => $pending['requested_at'] ?? '',
            'deploy_scope'   => $pendingSiteIds === null ? 'full' : implode( ',', $pendingSiteIds ),
            'loopback_fired' => true,
        ];

        if ( is_wp_error( $loopbackResult ) ) {
            CBSLogger::general()->warning( 'Pending deploy fired from queue — direct loopback failed', [
                ...$loopbackContext,
                'loopback_error' => $loopbackResult->get_error_message(),
            ] );
        } else {
            CBSLogger::general()->info( 'Pending deploy fired from queue', $loopbackContext );
        }

        return [ 'runId' => $runId, 'startedBy' => $startedBy ];
    }

    // -------------------------------------------------------------------------
    // Attribution helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve a human-readable attribution label for a deploy trigger.
     *
     * Priority chain:
     *   1. Non-empty $userLogin  → return $userLogin (manual deploy by a known user)
     *   2. $triggerType = 'hook' → map $triggerSource:
     *        'listener_menuitemchanged' → 'menu webhook'
     *        other / null              → 'webhook'
     *   3. $triggerType = 'background' → 'background job'
     *   4. Fallback → 'system'
     *
     * Empty string $userLogin is treated identically to null (falls through).
     */
    private static function resolveTriggerLabel( ?string $userLogin, string $triggerType, ?string $triggerSource ): string
    {
        if ( ! empty( $userLogin ) ) {
            return $userLogin;
        }

        if ( $triggerType === 'hook' ) {
            return $triggerSource === 'listener_menuitemchanged' ? 'menu webhook' : 'webhook';
        }

        if ( $triggerType === 'background' ) {
            return 'background job';
        }

        return 'system';
    }
}
