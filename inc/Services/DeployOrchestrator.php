<?php

namespace CBSNorthStar\Services;

use CBSNorthStar\Logger\CBSLogger;
use CBSNorthStar\Logger\DeployRunLogger;
use CBSNorthStar\Repositories\DeployRunRepository;
use CBSNorthStar\SaveProduct;

/**
 * DeployOrchestrator — single entry point for all product deploy runs.
 *
 * Isolates the run lifecycle (lock, log, execute, close, release) from both
 * the business logic inside SaveProduct and the transport layer (AJAX, webhook).
 *
 * Callers pass a trigger type and optional user/source context. The orchestrator
 * handles everything else and returns a DeployRunResult the caller can inspect
 * without knowing anything about locking or logging internals.
 *
 *   $result = DeployOrchestrator::create()->run(
 *       DeployRunRepository::TRIGGER_MANUAL,
 *       get_current_user_id(),
 *       'admin_button'
 *   );
 *
 *   if ( $result->wasBlocked() )    { // show "already running" notice }
 *   if ( $result->wasSuccessful() ) { // show success notice }
 *   if ( $result->wasFailed() )     { // show error notice }
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Execution flow
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  run()
 *   │
 *   ├─ 1. acquireLock()       → DeployLockService::tryAcquire()
 *   │      ├─ blocked         → log + return DeployRunResult::blocked()   [STOP]
 *   │      └─ acquired        → $runId is now set
 *   │
 *   ├─ 2. executeStore()      → new SaveProduct()->store($runId)
 *   │      ├─ returns array   → normalizeStoreResult()
 *   │      ├─ returns WP_Error → normalizeStoreResult()
 *   │      ├─ returns other   → normalizeStoreResult()
 *   │      └─ throws Throwable → caught in run(), failRun() called
 *   │
 *   ├─ 3. interpretResult()   → completeRun() or failRun()
 *   │
 *   └─ 4. finally             → DeployLockService::release()  [ALWAYS]
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * What this class deliberately does NOT do
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  - Does not rewrite SaveProduct business logic.
 *  - Does not know about HTTP responses (no wp_send_json_* calls).
 *  - Does not send emails — that remains in SaveProduct for now.
 *  - Does not manage per-product granular logging (SaveProduct can call
 *    DeployRunLogger::recordProductResult() directly when ready).
 */
class DeployOrchestrator
{
    // -------------------------------------------------------------------------
    // Singleton
    // -------------------------------------------------------------------------

    /** @var self|null */
    private static ?self $instance = null;

    /** @var DeployLockService */
    private DeployLockService $lockService;

    /** @var DeployRunLogger */
    private DeployRunLogger $logger;

    private function __construct()
    {
        $this->lockService = DeployLockService::create();
        $this->logger      = DeployRunLogger::create();
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
    // Public API — single entry point
    // -------------------------------------------------------------------------

    /**
     * Run the full deploy lifecycle for any trigger source.
     *
     * This method is safe to call from AJAX handlers, webhook listeners, WP-CLI
     * commands, and cron events. It never throws — all exceptions are caught,
     * logged, and converted to a DeployRunResult::failed() value.
     *
     * @param string      $triggerType   One of DeployRunRepository::TRIGGER_* constants.
     * @param int|null    $userId        WP user ID for manual triggers; null for background.
     * @param string|null $triggerSource Human-readable origin label for the deploy report
     *                                   (e.g. 'admin_button', 'listener_menuitemchanged').
     * @return DeployRunResult
     */
    public function run(
        string  $triggerType,
        ?int    $userId        = null,
        ?string $triggerSource = null
    ): DeployRunResult {

        // ── Step 1: Acquire lock ─────────────────────────────────────────────
        $lockResult = $this->acquireLock( $triggerType, $userId, $triggerSource );

        if ( $lockResult->wasBlocked() ) {
            if ( $lockResult->wasQueued() ) {
                // Request was written to the pending queue — will run automatically.
                return DeployRunResult::queued(
                    $lockResult->active_run_id,
                    $lockResult->message
                );
            }
            // Hard blocked (cache unavailable, race condition, etc.).
            return DeployRunResult::blocked(
                $lockResult->active_run_id,
                $lockResult->message
            );
        }

        $runId = $lockResult->active_run_id;

        // ── Steps 2-3: Execute and close ─────────────────────────────────────
        // The finally block runs regardless of whether try or catch exits.
        try {

            $storeResult = $this->executeStore( $runId );
            return $this->interpretResult( $runId, $storeResult );

        } catch ( \Throwable $e ) {

            return $this->handleException( $runId, $e );

        } finally {

            // ── Step 4: Release lock — ALWAYS ────────────────────────────────
            // This runs after the return in either try or catch.
            $this->lockService->release( $runId );
        }
    }

    // -------------------------------------------------------------------------
    // Step 1 — Lock acquisition
    // -------------------------------------------------------------------------

    /**
     * Delegate lock acquisition to DeployLockService.
     * Isolated here so it can be swapped in tests or subclasses.
     *
     * @param string      $triggerType
     * @param int|null    $userId
     * @param string|null $triggerSource
     * @return DeployLockResult
     */
    private function acquireLock(
        string  $triggerType,
        ?int    $userId,
        ?string $triggerSource
    ): DeployLockResult {
        return $this->lockService->tryAcquire( $triggerType, $userId, $triggerSource );
    }

    // -------------------------------------------------------------------------
    // Step 2 — Execute and normalize
    // -------------------------------------------------------------------------

    /**
     * Instantiate SaveProduct, call store(), and return a normalized result array.
     *
     * This method ONLY normalizes the return value — it does not decide success
     * or failure. That is interpretResult()'s responsibility.
     *
     * Handles the following return shapes from store():
     *   array  {'success': bool, 'message': string|array}  — normal
     *   WP_Error                                            — treated as failure
     *   null / false / empty                                — treated as failure
     *   any other scalar                                    — treated as failure
     *
     * @param string $runId UUID of the active run (passed through to store()).
     * @return array{success: bool, message: string|array, raw: mixed}
     */
    private function executeStore( string $runId ): array
    {
        $this->logger->appendEvent(
            $runId,
            DeployRunRepository::EVENT_RUN_STARTED,
            DeployRunRepository::SEVERITY_INFO,
            'SaveProduct::store() called.',
            [ 'run_id' => $runId ]
        );

        $raw = ( new SaveProduct() )->store( $runId );

        return $this->normalizeStoreResult( $raw, $runId );
    }

    /**
     * Convert any return value from store() to a predictable array shape.
     *
     * Logs a warning event when the return type is unexpected so the issue
     * is visible in the deploy report without needing to check server logs.
     *
     * @param mixed  $raw   Whatever store() returned.
     * @param string $runId UUID of the active run (for event logging).
     * @return array{success: bool, message: string|array, raw: mixed}
     */
    private function normalizeStoreResult( $raw, string $runId ): array
    {
        // ── WP_Error ─────────────────────────────────────────────────────────
        if ( is_wp_error( $raw ) ) {
            /** @var \WP_Error $raw */
            $this->logger->appendEvent(
                $runId,
                DeployRunRepository::EVENT_RUN_FAILED,
                DeployRunRepository::SEVERITY_ERROR,
                'store() returned a WP_Error.',
                [
                    'wp_error_code'    => $raw->get_error_code(),
                    'wp_error_message' => $raw->get_error_message(),
                ]
            );

            return [
                'success' => false,
                'message' => $raw->get_error_message(),
                'raw'     => $raw,
            ];
        }

        // ── Null / false / empty ──────────────────────────────────────────────
        if ( $raw === null || $raw === false || $raw === '' ) {
            $this->logger->appendEvent(
                $runId,
                DeployRunRepository::EVENT_RUN_FAILED,
                DeployRunRepository::SEVERITY_WARNING,
                'store() returned an empty value — treated as failure.',
                [ 'return_type' => gettype( $raw ) ]
            );

            return [
                'success' => false,
                'message' => 'Deploy returned no result.',
                'raw'     => $raw,
            ];
        }

        // ── Non-array scalar ─────────────────────────────────────────────────
        if ( ! is_array( $raw ) ) {
            $this->logger->appendEvent(
                $runId,
                DeployRunRepository::EVENT_RUN_FAILED,
                DeployRunRepository::SEVERITY_WARNING,
                'store() returned an unexpected type — treated as failure.',
                [ 'return_type' => gettype( $raw ), 'return_value' => substr( (string) $raw, 0, 200 ) ]
            );

            return [
                'success' => false,
                'message' => 'Unexpected return type from store().',
                'raw'     => $raw,
            ];
        }

        // ── Standard array — ensure required keys exist ──────────────────────
        return [
            'success' => (bool) ( $raw['success'] ?? false ),
            'message' => $raw['message'] ?? '',
            'raw'     => $raw,
        ];
    }

    // -------------------------------------------------------------------------
    // Step 3 — Interpret and close the run
    // -------------------------------------------------------------------------

    /**
     * Read the normalized result, call the appropriate logger method,
     * and return a DeployRunResult the caller can use.
     *
     * Decision table:
     *   success = true  → completeRun()  → DeployRunResult::completed()
     *   success = false → failRun()      → DeployRunResult::failed()
     *
     * Note: partial_success (some products failed, run did not throw) requires
     * per-product counts from store(). When store() starts returning
     * 'products_failed', add that case here. For now, success=false covers it.
     *
     * @param string $runId      UUID of the active run.
     * @param array  $normalized Output of normalizeStoreResult().
     * @return DeployRunResult
     */
    private function interpretResult( string $runId, array $normalized ): DeployRunResult
    {
        $success     = $normalized['success'];
        $message     = $normalized['message'];
        $raw         = is_array( $normalized['raw'] ?? null ) ? $normalized['raw'] : [];
        $rawMessages = is_array( $message ) ? $message : [];

        // Extract product counts from store()'s return value when present.
        // SaveProduct::store() populates these keys; older or custom implementations
        // may not — the (int) cast and null-coalescing default safely to 0.
        $counts = [
            'products_attempted' => (int) ( $raw['products_attempted'] ?? 0 ),
            'products_succeeded' => (int) ( $raw['products_succeeded'] ?? 0 ),
            'products_failed'    => (int) ( $raw['products_failed']    ?? 0 ),
            'products_skipped'   => (int) ( $raw['products_skipped']   ?? 0 ),
        ];

        if ( $success ) {
            $this->logger->completeRun( $runId, $counts );

            return DeployRunResult::completed( $runId, $message );
        }

        // Flatten array messages to a single string for the run log.
        $errorString = is_array( $message )
            ? implode( '; ', array_filter( array_map( 'strval', $message ) ) )
            : (string) $message;

        $errorString = $errorString ?: 'Deploy failed without a specific error message.';

        $this->logger->failRun( $runId, $errorString, null, null, $counts );

        return DeployRunResult::failed( $runId, $errorString, $rawMessages );
    }

    // -------------------------------------------------------------------------
    // Exception handler
    // -------------------------------------------------------------------------

    /**
     * Handle any Throwable that escaped from executeStore().
     *
     * Logs the failure with exception context and returns a failed result.
     * Does NOT re-throw — the orchestrator is a boundary; exceptions are
     * absorbed and converted to structured results.
     *
     * @param string     $runId UUID of the active run.
     * @param \Throwable $e     The caught exception or error.
     * @return DeployRunResult
     */
    private function handleException( string $runId, \Throwable $e ): DeployRunResult
    {
        $message = sprintf(
            'Unhandled %s: %s',
            get_class( $e ),
            $e->getMessage()
        );

        // failRun() writes to both the DB run log and CBSLogger::general()
        // (which routes errors to ELK and the AI analyzer).
        $this->logger->failRun( $runId, $message, null, $e );

        // Also log directly to the webhooks/general channel so the exception
        // appears in the WooCommerce log file visible under WC > Status > Logs.
        CBSLogger::general()->error( $message, [
            'run_id'         => $runId,
            'exception_class' => get_class( $e ),
            'exception_file'  => $e->getFile(),
            'exception_line'  => $e->getLine(),
        ] );

        return DeployRunResult::failed( $runId, $e->getMessage(), [], $e );
    }
}
