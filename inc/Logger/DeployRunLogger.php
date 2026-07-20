<?php

namespace CBSNorthStar\Logger;

use CBSNorthStar\Repositories\DeployRunRepository;

/**
 * DeployRunLogger — public-facing service for all deploy run lifecycle tracking.
 *
 * All code that triggers or monitors a product deploy calls this class.
 * It delegates every DB write to DeployRunRepository and mirrors key events to
 * CBSLogger::general() so they also appear in the WC log file and reach ELK/AI.
 *
 * Usage:
 *   $logger = DeployRunLogger::create();
 *   $runId  = $logger->startRun(DeployRunRepository::TRIGGER_MANUAL, get_current_user_id());
 *   $logger->recordProductResult($runId, 42, 'SKU-001', 'succeeded', null, 320);
 *   $logger->completeRun($runId, ['products_attempted' => 1, 'products_succeeded' => 1]);
 */
class DeployRunLogger
{
    // -------------------------------------------------------------------------
    // Singleton
    // -------------------------------------------------------------------------

    /** @var self|null */
    private static ?self $instance = null;

    /** @var DeployRunRepository */
    private DeployRunRepository $repository;

    private function __construct()
    {
        $this->repository = DeployRunRepository::create();
    }

    /**
     * Return (or create) the singleton instance.
     *
     * @return self
     */
    public static function create(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // -------------------------------------------------------------------------
    // Run lifecycle — write
    // -------------------------------------------------------------------------

    /**
     * Start a new deploy run and return its UUID.
     *
     * Inserts the run row, appends the run.started event, and mirrors to the
     * WC products log so the event appears in ELK / AI tooling.
     *
     * @param string      $triggerType   One of DeployRunRepository::TRIGGER_* constants.
     * @param int|null    $userId        WP user ID that initiated the run, or null.
     * @param string|null $triggerSource Human-readable label for the originating source.
     * @return string UUID (run_id) for the newly created run.
     */
    public function startRun(
        string  $triggerType,
        ?int    $userId        = null,
        ?string $triggerSource = null,
        ?string $runId         = null
    ): string {
        // Allow an externally generated UUID (e.g. from DeployLockService which
        // generates the run_id before acquiring the lock, so the ID is consistent
        // across both the cache payload and the DB row).
        $runId = $runId ?? wp_generate_uuid4();

        $this->repository->createRun($runId, $triggerType, $userId, $triggerSource);

        $this->repository->appendEvent(
            $runId,
            DeployRunRepository::EVENT_RUN_STARTED,
            DeployRunRepository::SEVERITY_INFO,
            'Deploy run started.',
            ['trigger' => $triggerType, 'trigger_source' => $triggerSource]
        );

        CBSLogger::general()->info('Deploy run started', [
            'run_id'  => $runId,
            'trigger' => $triggerType,
        ]);

        return $runId;
    }

    /**
     * Mark a run as successfully completed.
     *
     * Calculates wall-clock duration from the run's started_at timestamp,
     * writes all count columns, and appends a run.completed event.
     *
     * @param string $runId  UUID of the run to complete.
     * @param array  $counts Optional keys: products_attempted, products_succeeded,
     *                       products_failed, products_skipped.
     * @return void
     */
    public function completeRun(string $runId, array $counts = []): void
    {
        $finishedAt = current_time('mysql', true);
        $durationMs = $this->calcDurationMs($runId, $finishedAt);

        $attempted  = (int) ($counts['products_attempted']  ?? 0);
        $succeeded  = (int) ($counts['products_succeeded']  ?? 0);
        $failed     = (int) ($counts['products_failed']     ?? 0);
        $skipped    = (int) ($counts['products_skipped']    ?? 0);

        $this->repository->updateRun($runId, [
            'status'               => DeployRunRepository::STATUS_COMPLETED,
            'finished_at'          => $finishedAt,
            'duration_ms'          => $durationMs,
            'products_attempted'   => $attempted,
            'products_succeeded'   => $succeeded,
            'products_failed'      => $failed,
            'products_skipped'     => $skipped,
        ]);

        $message = sprintf(
            'Deploy completed. %d of %d sites succeeded.',
            $succeeded,
            $attempted
        );

        $this->repository->appendEvent(
            $runId,
            DeployRunRepository::EVENT_RUN_COMPLETED,
            DeployRunRepository::SEVERITY_INFO,
            $message,
            [
                'sites_attempted' => $attempted,
                'sites_succeeded' => $succeeded,
                'sites_failed'    => $failed,
                'sites_skipped'   => $skipped,
                'duration_ms'     => $durationMs,
            ]
        );

        CBSLogger::general()->info($message, [
            'run_id'          => $runId,
            'sites_attempted' => $attempted,
            'sites_succeeded' => $succeeded,
            'sites_failed'    => $failed,
            'sites_skipped'   => $skipped,
            'duration_ms'     => $durationMs,
        ]);
    }

    /**
     * Mark a run as failed due to an unrecoverable error.
     *
     * The error_message is truncated to 1 000 characters before storage.
     * When an exception is provided, its class, message, file, and line are
     * captured in the event context (no full stack trace — too large for DB).
     *
     * @param string          $runId        UUID of the run.
     * @param string          $errorMessage Human-readable error description.
     * @param string|null     $errorCode    Optional machine-readable error code.
     * @param \Throwable|null $exception    Optional caught exception for context.
     * @param array           $counts       Optional product count keys (same as completeRun).
     * @return void
     */
    public function failRun(
        string      $runId,
        string      $errorMessage,
        ?string     $errorCode  = null,
        ?\Throwable $exception  = null,
        array       $counts     = []
    ): void {
        $finishedAt = current_time('mysql', true);
        $durationMs = $this->calcDurationMs($runId, $finishedAt);

        $attempted = (int) ($counts['products_attempted'] ?? 0);
        $succeeded = (int) ($counts['products_succeeded'] ?? 0);
        $failed    = (int) ($counts['products_failed']    ?? 0);
        $skipped   = (int) ($counts['products_skipped']   ?? 0);

        $this->repository->updateRun($runId, [
            'status'             => DeployRunRepository::STATUS_FAILED,
            'finished_at'        => $finishedAt,
            'duration_ms'        => $durationMs,
            'error_message'      => substr($errorMessage, 0, 1000),
            'error_code'         => $errorCode,
            'products_attempted' => $attempted,
            'products_succeeded' => $succeeded,
            'products_failed'    => $failed,
            'products_skipped'   => $skipped,
        ]);

        $context = [
            'error_code'      => $errorCode,
            'sites_attempted' => $attempted,
            'sites_succeeded' => $succeeded,
            'sites_failed'    => $failed,
            'sites_skipped'   => $skipped,
            'duration_ms'     => $durationMs,
        ];

        if ($exception !== null) {
            $context['exception_class']   = get_class($exception);
            $context['exception_message'] = $exception->getMessage();
            $context['exception_file']    = $exception->getFile();
            $context['exception_line']    = $exception->getLine();
        }

        $this->repository->appendEvent(
            $runId,
            DeployRunRepository::EVENT_RUN_FAILED,
            DeployRunRepository::SEVERITY_ERROR,
            substr($errorMessage, 0, 1000),
            $context
        );

        CBSLogger::general()->error($errorMessage, array_merge(['run_id' => $runId], $context));
    }

    /**
     * Mark a run as blocked because another run is already in progress.
     *
     * Appends both a run.blocked and a lock.conflict event so the admin report
     * can surface both the run-level outcome and the specific conflict detail.
     *
     * @param string      $runId         UUID of the run that was blocked.
     * @param string      $conflictType  One of DeployRunRepository::CONFLICT_* constants.
     * @param string|null $blockedByRunId UUID of the run that caused the block, if known.
     * @return void
     */
    public function blockRun(
        string  $runId,
        string  $conflictType,
        ?string $blockedByRunId = null
    ): void {
        $now = current_time('mysql', true);

        $this->repository->updateRun($runId, [
            'status'           => DeployRunRepository::STATUS_BLOCKED,
            'finished_at'      => $now,
            'conflict_type'    => $conflictType,
            'blocked_by_run_id' => $blockedByRunId,
        ]);

        $context = [
            'conflict_type'     => $conflictType,
            'blocked_by_run_id' => $blockedByRunId,
        ];

        $this->repository->appendEvent(
            $runId,
            DeployRunRepository::EVENT_RUN_BLOCKED,
            DeployRunRepository::SEVERITY_WARNING,
            'Deploy run blocked by an in-progress run.',
            $context
        );

        $this->repository->appendEvent(
            $runId,
            DeployRunRepository::EVENT_LOCK_CONFLICT,
            DeployRunRepository::SEVERITY_WARNING,
            sprintf('Lock conflict detected: %s.', $conflictType),
            $context
        );

        CBSLogger::general()->warning('Deploy run blocked.', array_merge(['run_id' => $runId], $context));
    }

    /**
     * Mark a run as partially successful (some products failed, some succeeded).
     *
     * Used when the run completed its full iteration but recorded at least one
     * product failure that does not warrant a full STATUS_FAILED designation.
     *
     * @param string $runId  UUID of the run.
     * @param array  $counts Required keys: products_attempted, products_succeeded,
     *                       products_failed, products_skipped.
     * @return void
     */
    public function markPartialSuccess(string $runId, array $counts): void
    {
        $finishedAt = current_time('mysql', true);
        $durationMs = $this->calcDurationMs($runId, $finishedAt);

        $attempted = (int) ($counts['products_attempted'] ?? 0);
        $succeeded = (int) ($counts['products_succeeded'] ?? 0);
        $failed    = (int) ($counts['products_failed']    ?? 0);
        $skipped   = (int) ($counts['products_skipped']   ?? 0);

        $this->repository->updateRun($runId, [
            'status'             => DeployRunRepository::STATUS_PARTIAL_SUCCESS,
            'finished_at'        => $finishedAt,
            'duration_ms'        => $durationMs,
            'products_attempted' => $attempted,
            'products_succeeded' => $succeeded,
            'products_failed'    => $failed,
            'products_skipped'   => $skipped,
        ]);

        $message = sprintf(
            'Deploy finished with errors. %d of %d sites failed.',
            $failed,
            $attempted
        );

        $this->repository->appendEvent(
            $runId,
            DeployRunRepository::EVENT_RUN_PARTIAL_SUCCESS,
            DeployRunRepository::SEVERITY_WARNING,
            $message,
            [
                'sites_attempted' => $attempted,
                'sites_succeeded' => $succeeded,
                'sites_failed'    => $failed,
                'sites_skipped'   => $skipped,
                'duration_ms'     => $durationMs,
            ]
        );

        CBSLogger::general()->warning($message, [
            'run_id'          => $runId,
            'sites_attempted' => $attempted,
            'sites_succeeded' => $succeeded,
            'sites_failed'    => $failed,
            'sites_skipped'   => $skipped,
            'duration_ms'     => $durationMs,
        ]);
    }

    /**
     * Direct pass-through to DeployRunRepository::appendEvent().
     *
     * Use this for custom/ad-hoc events. Mirroring to CBSLogger is the caller's
     * responsibility — this method intentionally does NOT mirror.
     *
     * @param string      $runId      UUID of the parent run.
     * @param string      $eventType  One of DeployRunRepository::EVENT_* constants.
     * @param string      $severity   One of DeployRunRepository::SEVERITY_* constants.
     * @param string      $message    Human-readable event description.
     * @param array       $context    Optional structured payload.
     * @param int|null    $productId  WC product ID when event is product-scoped.
     * @param string|null $productSku Product SKU when event is product-scoped.
     * @return void
     */
    public function appendEvent(
        string  $runId,
        string  $eventType,
        string  $severity,
        string  $message,
        array   $context    = [],
        ?int    $productId  = null,
        ?string $productSku = null
    ): void {
        $this->repository->appendEvent(
            $runId,
            $eventType,
            $severity,
            $message,
            $context,
            $productId,
            $productSku
        );
    }

    /**
     * Record the outcome of a single product save attempt.
     *
     * Maps $action to the correct EVENT_PRODUCT_* constant and chooses severity
     * automatically (debug for succeeded/skipped, error for failed).
     * Only failures are mirrored to CBSLogger::general() to avoid log noise.
     *
     * @param string      $runId        UUID of the parent run.
     * @param int         $productId    WC product ID.
     * @param string      $sku          Product SKU.
     * @param string      $action       One of: 'succeeded', 'failed', 'skipped'.
     * @param string|null $errorMessage Error description (required when $action = 'failed').
     * @param int         $durationMs   Time taken to process this product in milliseconds.
     * @return void
     */
    public function recordProductResult(
        string  $runId,
        int     $productId,
        string  $sku,
        string  $action,
        ?string $errorMessage = null,
        int     $durationMs   = 0
    ): void {
        switch ($action) {
            case 'succeeded':
                $eventType = DeployRunRepository::EVENT_PRODUCT_SUCCESS;
                $severity  = DeployRunRepository::SEVERITY_DEBUG;
                $message   = sprintf('Product %s (%s) saved successfully.', $productId, $sku);
                $context   = ['sku' => $sku, 'product_id' => $productId, 'duration_ms' => $durationMs];
                break;

            case 'failed':
                $eventType = DeployRunRepository::EVENT_PRODUCT_FAILED;
                $severity  = DeployRunRepository::SEVERITY_ERROR;
                $message   = sprintf('Product %s (%s) failed to save.', $productId, $sku);
                $context   = ['sku' => $sku, 'product_id' => $productId, 'error_message' => $errorMessage];
                break;

            case 'skipped':
                $eventType = DeployRunRepository::EVENT_PRODUCT_SKIPPED;
                $severity  = DeployRunRepository::SEVERITY_DEBUG;
                $message   = sprintf('Product %s (%s) skipped.', $productId, $sku);
                $context   = ['sku' => $sku, 'product_id' => $productId, 'reason' => $errorMessage];
                break;

            default:
                return;
        }

        $this->repository->appendEvent(
            $runId,
            $eventType,
            $severity,
            $message,
            $context,
            $productId,
            $sku
        );

        if ($action === 'failed') {
            CBSLogger::general()->error($message, array_merge(['run_id' => $runId], $context));
        }
    }

    /**
     * Record a heartbeat tick for a long-running deploy.
     *
     * Updates last_heartbeat_at on the run row so stale-lock detection can
     * distinguish an active run from one that died silently.
     * Does NOT mirror to CBSLogger — heartbeats are too frequent to be useful in logs.
     *
     * @param string $runId UUID of the run.
     * @return void
     */
    public function heartbeat(string $runId): void
    {
        $this->repository->updateRun($runId, [
            'last_heartbeat_at' => current_time('mysql', true),
        ]);

        $this->repository->appendEvent(
            $runId,
            DeployRunRepository::EVENT_RUN_HEARTBEAT,
            DeployRunRepository::SEVERITY_DEBUG,
            'Heartbeat.'
        );
    }

    /**
     * Return the run_id of the currently active (running) deploy, or null.
     *
     * @return string|null UUID of the active run, or null if none is in progress.
     */
    public function getActiveRunId(): ?string
    {
        $run = $this->repository->findActiveRun();
        return $run['run_id'] ?? null;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Calculate the wall-clock duration in milliseconds from a run's started_at.
     *
     * Fetches the run row to read started_at. Returns 0 when the run cannot be
     * found or the timestamp is missing/malformed.
     *
     * @param string $runId UUID of the run.
     * @param string $now   Current MySQL timestamp (avoids a second current_time() call).
     * @return int Duration in milliseconds.
     */
    private function calcDurationMs(string $runId, string $now): int
    {
        $run = $this->repository->findByRunId($runId);

        if (empty($run['started_at'])) {
            return 0;
        }

        $start = new \DateTime($run['started_at']);
        $end   = new \DateTime($now);

        return (int) (($end->getTimestamp() - $start->getTimestamp()) * 1000);
    }
}
