<?php

namespace CBSNorthStar\Services;

use CBSNorthStar\Logger\CBSLogger;

/**
 * DeployProgress — lightweight progress tracking for async deploy runs.
 *
 * Stores a structured progress payload in wp_options (autoload=false) keyed
 * by runId. Using update_option() instead of set_transient() ensures that
 * writes are always committed to the database, regardless of whether an
 * external object cache (Redis / Memcached / APCu) is active. This makes the
 * progress record visible across all PHP processes — the WP-Cron worker that
 * runs the deploy AND the REST-API worker that handles polling requests.
 *
 * Cleanup: call DeployProgress::delete( $runId ) once the UI has read the
 * final state (or from a periodic maintenance job). Without the TTL safety
 * net of transients, callers are responsible for cleanup.
 *
 * Usage inside SaveProduct:
 *   DeployProgress::update( $runId, [ 'currentStep' => 'Saving categories', 'processedCategories' => 3 ] );
 *
 * Usage from REST endpoint:
 *   $progress = DeployProgress::get( $runId );
 */
class DeployProgress
{

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Initialise a fresh progress record for a new deploy run.
     * Call this immediately after the lock is acquired, before any work starts.
     *
     * @param string $runId         UUID of the run.
     * @param int    $totalSites    Number of sites that will be processed.
     * @param string $startedAt     MySQL datetime string (current_time('mysql')).
     */
    public static function init( string $runId, int $totalSites, string $startedAt ): void
    {
        $payload = [
            'runId'                    => $runId,
            'status'                   => 'running',
            'currentSiteId'            => null,
            'currentSiteName'          => null,
            'currentStep'              => 'Initialising',
            'totalSites'               => $totalSites,
            'processedSites'           => 0,
            'totalMenus'               => 0,
            'processedMenus'           => 0,
            'totalCategories'          => 0,
            'processedCategories'      => 0,
            'totalProducts'            => 0,
            'processedProducts'        => 0,
            'skippedCount'             => 0,
            'failedCount'              => 0,
            'message'                  => 'Deploy started',
            'startedAt'                => $startedAt,
            'updatedAt'                => $startedAt,
            'finishedAt'               => null,
            'estimatedSecondsRemaining' => null,
            'percent'                  => 0,
            'errors'                   => [],
            'cancelRequested'          => false,
        ];

        update_option( self::key( $runId ), $payload, false );

        // Pre-create the cancel key so WordPress never adds it to the notoptions
        // cache. IMPORTANT: update_option(key, false) is a no-op when the option
        // does not exist — WordPress compares new value (false) with the default
        // return of get_option (also false) and skips the write entirely. The key
        // is never created, so the cron process marks it as non-existent in its
        // notoptions cache on first check, and all subsequent get_option calls
        // return false without querying the DB.
        // Use add_option instead — it always INSERTs without comparing values.
        add_option( self::cancelKey( $runId ), '0', '', false );
    }

    /**
     * Merge $data into the stored progress payload and recalculate percent + ETA.
     *
     * Only keys present in the stored payload are accepted — unknown keys are ignored.
     * Always safe to call; if the transient has expired it writes a new one.
     *
     * @param string $runId
     * @param array  $data  Partial progress fields to merge.
     */
    public static function update( string $runId, array $data ): void
    {
        $current = self::get( $runId );

        if ( $current === null ) {
            // Transient expired or never created — reconstruct a minimal shell
            // so callers do not need to guard against null.
            $current = [ 'runId' => $runId, 'status' => 'running', 'errors' => [], 'cancelRequested' => false ];
            \CBSNorthStar\Logger\CBSLogger::general()->warning( 'DeployProgress::update — transient was null, rebuilt shell', [
                'run_id'           => $runId,
                'ext_object_cache' => wp_using_ext_object_cache() ? 'yes' : 'no',
                'data_keys'        => array_keys( $data ),
            ] );
        }

        // Merge only known keys.
        $allowedKeys = [
            'status', 'currentSiteId', 'currentSiteName', 'currentStep',
            'totalSites', 'processedSites', 'totalMenus', 'processedMenus',
            'totalCategories', 'processedCategories', 'totalProducts', 'processedProducts',
            'skippedCount', 'failedCount', 'message', 'finishedAt', 'errors', 'cancelRequested',
            'percent', 'nextRunId', 'nextRunStartedBy',
        ];

        $prevTotal = $current['totalProducts'] ?? 0;

        foreach ( $allowedKeys as $key ) {
            if ( array_key_exists( $key, $data ) ) {
                $current[ $key ] = $data[ $key ];
            }
        }

        // Diagnostic: log every time totalProducts changes so we can trace the source.
        if ( array_key_exists( 'totalProducts', $data ) && (int) $data['totalProducts'] !== (int) $prevTotal ) {
            $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 4 );
            $caller = isset( $trace[1] ) ? ( $trace[1]['class'] ?? '' ) . '::' . ( $trace[1]['function'] ?? '' ) . ':' . ( $trace[1]['line'] ?? '' ) : 'unknown';
            \CBSNorthStar\Logger\CBSLogger::general()->debug( 'DeployProgress totalProducts changed', [
                'run_id'    => $runId,
                'from'      => $prevTotal,
                'to'        => $data['totalProducts'],
                'caller'    => $caller,
            ] );
        }

        $current['updatedAt'] = current_time( 'mysql' );

        // Recalculate percent and ETA.
        $previousPercent = (int) ( $current['percent'] ?? 0 );
        $current = self::recalculate( $current );

        // Never let the progress bar go backward — once a percentage is shown,
        // keep it until we exceed it (totalProducts growing mid-deploy can
        // momentarily lower the ratio; the watermark prevents visible regression).
        if ( $current['percent'] < $previousPercent ) {
            $current['percent'] = $previousPercent;
        }

        update_option( self::key( $runId ), $current, false );
    }

    /**
     * Mark the run as completed with final counts.
     *
     * @param string $runId
     * @param array  $finalCounts  Keys: processedProducts, processedSites, failedCount, skippedCount
     */
    public static function complete( string $runId, array $finalCounts = [] ): void
    {
        self::update( $runId, array_merge( $finalCounts, [
            'status'                   => 'completed',
            'currentStep'              => 'Deploy completed',
            'percent'                  => 100,
            'estimatedSecondsRemaining' => 0,
            'finishedAt'               => current_time( 'mysql' ),
        ] ) );
        delete_option( self::cancelKey( $runId ) );
    }

    /**
     * Mark the run as completed and include queue-handoff fields in the same write.
     * Using a single update_option() call eliminates the race window where a polling
     * request could see 'completed' without nextRunId.
     *
     * @param string      $runId
     * @param array       $finalCounts       Keys: processedProducts, processedSites, failedCount, skippedCount
     * @param string|null $nextRunId
     * @param string|null $nextRunStartedBy
     */
    public static function completeWithHandoff(
        string  $runId,
        array   $finalCounts      = [],
        ?string $nextRunId        = null,
        ?string $nextRunStartedBy = null
    ): void {
        $data = array_merge( $finalCounts, [
            'status'                    => 'completed',
            'currentStep'               => 'Deploy completed',
            'percent'                   => 100,
            'estimatedSecondsRemaining' => 0,
            'finishedAt'                => current_time( 'mysql' ),
        ] );
        if ( $nextRunId !== null ) {
            $data['nextRunId']        = $nextRunId;
            $data['nextRunStartedBy'] = $nextRunStartedBy ?? 'system';
        }
        self::update( $runId, $data );
        delete_option( self::cancelKey( $runId ) );
    }

    /**
     * Mark the run as failed.
     *
     * @param string $runId
     * @param string $errorMessage
     */
    public static function fail( string $runId, string $errorMessage ): void
    {
        $current = self::get( $runId );
        $errors  = $current['errors'] ?? [];
        $errors[] = $errorMessage;

        self::update( $runId, [
            'status'      => 'failed',
            'currentStep' => 'Deploy failed',
            'message'     => $errorMessage,
            'finishedAt'  => current_time( 'mysql' ),
            'errors'      => $errors,
        ] );
        delete_option( self::cancelKey( $runId ) );
    }

    /**
     * Mark the run as failed and include queue-handoff fields in the same write.
     * Using a single update_option() call eliminates the race window where a polling
     * request could see 'failed' without nextRunId.
     *
     * @param string      $runId
     * @param string      $errorMessage
     * @param string|null $nextRunId
     * @param string|null $nextRunStartedBy
     */
    public static function failWithHandoff(
        string  $runId,
        string  $errorMessage,
        ?string $nextRunId        = null,
        ?string $nextRunStartedBy = null
    ): void {
        $current = self::get( $runId );
        $errors  = $current['errors'] ?? [];
        $errors[] = $errorMessage;

        $data = [
            'status'      => 'failed',
            'currentStep' => 'Deploy failed',
            'message'     => $errorMessage,
            'finishedAt'  => current_time( 'mysql' ),
            'errors'      => $errors,
        ];
        if ( $nextRunId !== null ) {
            $data['nextRunId']        = $nextRunId;
            $data['nextRunStartedBy'] = $nextRunStartedBy ?? 'system';
        }
        self::update( $runId, $data );
        delete_option( self::cancelKey( $runId ) );
    }

    /**
     * Append an error message to the errors array without changing run status.
     *
     * @param string $runId
     * @param string $errorMessage
     */
    public static function appendError( string $runId, string $errorMessage ): void
    {
        $current = self::get( $runId );
        $errors  = $current['errors'] ?? [];
        $errors[] = $errorMessage;
        self::update( $runId, [ 'errors' => $errors ] );
    }

    /**
     * Read the current progress payload.
     *
     * @param string $runId
     * @return array|null Null when the transient does not exist or has expired.
     */
    public static function get( string $runId ): ?array
    {
        $data = get_option( self::key( $runId ), null );
        return is_array( $data ) ? $data : null;
    }

    /**
     * Check whether a cancel was requested for this run.
     * Call this between batches inside SaveProduct to allow graceful cancellation.
     *
     * @param string $runId
     * @return bool
     */
    public static function isCancelRequested( string $runId ): bool
    {
        // Read from the isolated cancel key — never touched by update(), so it
        // cannot be overwritten by per-product progress writes in the cron worker.
        //
        // Two-step cache bypass:
        // 1. Delete the individual option cache entry so get_option re-reads from DB.
        // 2. Delete the notoptions cache entry. If the cancel key was ever looked up
        //    before it existed in DB, WordPress added it to notoptions and all future
        //    get_option calls return false without hitting the DB — even after
        //    requestCancel() has written true. Clearing notoptions forces a fresh DB
        //    check for any option that was previously absent.
        wp_cache_delete( self::cancelKey( $runId ), 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        return (bool) get_option( self::cancelKey( $runId ), false );
    }

    /**
     * Request cancellation of the given run.
     * The running process checks this flag between batches.
     *
     * @param string $runId
     */
    public static function requestCancel( string $runId ): void
    {
        // Write the authoritative cancel signal to its own isolated key so that
        // DeployProgress::update() (called per product in the cron worker) can
        // never overwrite it — update() does not know this key exists.
        update_option( self::cancelKey( $runId ), true, false );

        // Also update the main progress payload for UI display (currentStep text,
        // cancelRequested field shown in polling responses).
        self::update( $runId, [
            'cancelRequested' => true,
            'currentStep'     => 'Cancellation requested',
        ] );
    }

    /**
     * Mark the run as cancelled (called when store() detects the flag early).
     *
     * @param string $runId
     */
    public static function cancel( string $runId ): void
    {
        self::update( $runId, [
            'status'      => 'cancelled',
            'currentStep' => 'Deploy cancelled',
            'finishedAt'  => current_time( 'mysql' ),
            'percent'     => 100,
            'estimatedSecondsRemaining' => 0,
        ] );
        delete_option( self::cancelKey( $runId ) );
    }

    /**
     * Delete the progress transient (cleanup after UI has read the final state).
     *
     * @param string $runId
     */
    public static function delete( string $runId ): void
    {
        delete_option( self::key( $runId ) );
        delete_option( self::cancelKey( $runId ) );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static function key( string $runId ): string
    {
        return 'cbs_deploy_progress_' . $runId;
    }

    private static function cancelKey( string $runId ): string
    {
        return 'cbs_deploy_cancel_' . $runId;
    }

    /**
     * Recalculate percent and estimatedSecondsRemaining from current payload.
     *
     * Estimate logic:
     *   - totalUnits = totalProducts + totalCategories + totalMenus
     *   - processedUnits = processedProducts + processedCategories + processedMenus
     *   - percent = processedUnits / totalUnits * 100
     *   - estimatedSecondsRemaining = elapsedSeconds / processedUnits * (totalUnits - processedUnits)
     *   - Only calculated when processedUnits > 0 and startedAt is known.
     *   - When totalUnits is 0 (still counting), percent stays at 0 and ETA is null.
     *
     * @param array $payload
     * @return array
     */
    private static function recalculate( array $payload ): array
    {
        $totalUnits     = (int) ( $payload['totalProducts'] ?? 0 )
                        + (int) ( $payload['totalCategories'] ?? 0 )
                        + (int) ( $payload['totalMenus'] ?? 0 );

        $processedUnits = (int) ( $payload['processedProducts'] ?? 0 )
                        + (int) ( $payload['processedCategories'] ?? 0 )
                        + (int) ( $payload['processedMenus'] ?? 0 );

        if ( $totalUnits > 0 ) {
            $payload['percent'] = (int) round( min( $processedUnits / $totalUnits * 100, 99 ) );
        } else {
            $payload['percent'] = 0;
        }

        // ETA — only meaningful once we have measured throughput.
        if ( $processedUnits > 0 && ! empty( $payload['startedAt'] ) ) {
            $elapsed = time() - strtotime( $payload['startedAt'] );
            $remaining = $totalUnits - $processedUnits;

            if ( $elapsed > 0 && $remaining > 0 ) {
                $payload['estimatedSecondsRemaining'] = (int) round( $elapsed / $processedUnits * $remaining );
            } else {
                $payload['estimatedSecondsRemaining'] = 0;
            }
        }

        return $payload;
    }
}
