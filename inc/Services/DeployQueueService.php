<?php

namespace CBSNorthStar\Services;

use CBSNorthStar\Logger\CBSLogger;

/**
 * DeployQueueService — last-write-wins pending slot for deploy requests.
 *
 * Holds at most one pending deploy at a time. Any new incoming request
 * overwrites the previous pending entry so only the most recent trigger
 * is executed when the current deploy finishes.
 *
 * Storage: wp_options (not autoloaded) — survives cache flushes and
 * process restarts, which is important because the pending entry must
 * outlive the current deploy's PHP process.
 *
 * Flow:
 *   1. DeployLockService writes the pending slot when a request is blocked
 *      by an active deploy.
 *   2. DeployController::runBackgroundDeploy() calls firePending() after
 *      the current deploy finishes and the lock is released.
 *   3. firePending() clears the slot atomically, re-acquires the lock,
 *      initialises progress, and schedules the next WP-Cron event.
 */
class DeployQueueService
{
    const OPTION_KEY = 'cbs_deploy_pending';

    private static ?self $instance = null;

    public static function create(): self
    {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Write (or overwrite) the pending slot with the given trigger details.
     * Last caller wins for trigger metadata — any previously pending entry is
     * replaced — but the deploy SCOPE only ever widens: site lists from the
     * previous pending entry and this request are merged, and a full deploy
     * (null scope) on either side makes the merged entry a full deploy. A
     * narrower later request must never drop sites an earlier request needed.
     *
     * @param array|null $siteIds Deploy scope for this request. Null = full deploy.
     */
    public function set( string $triggerType, ?int $userId, ?string $triggerSource, ?array $siteIds = null ): void
    {
        $existing = $this->get();

        if ( $existing !== null ) {
            $existingScope = $existing['site_ids'] ?? null;
            if ( $existingScope === null || $siteIds === null ) {
                $siteIds = null; // Either request wanted everything — full deploy wins.
            } else {
                $siteIds = array_values( array_unique( array_merge( $existingScope, $siteIds ) ) );
            }
        }

        update_option( self::OPTION_KEY, [
            'trigger_type'   => $triggerType,
            'user_id'        => $userId,
            'trigger_source' => $triggerSource ?? 'queue',
            'requested_at'   => current_time( 'mysql' ),
            'site_ids'       => $siteIds,
        ], false ); // false = do not autoload

        CBSLogger::general()->info( 'Deploy queued in pending slot', [
            'trigger_type'   => $triggerType,
            'trigger_source' => $triggerSource,
            'site_ids'       => $siteIds === null ? 'full' : implode( ',', $siteIds ),
        ] );
    }

    /**
     * Return the current pending entry, or null if the slot is empty.
     *
     * @return array{trigger_type:string,user_id:int|null,trigger_source:string,requested_at:string}|null
     */
    public function get(): ?array
    {
        $data = get_option( self::OPTION_KEY, null );
        return is_array( $data ) ? $data : null;
    }

    /**
     * Delete the pending slot.
     */
    public function clear(): void
    {
        delete_option( self::OPTION_KEY );
    }

    /**
     * True when a pending deploy is waiting to run.
     */
    public function hasPending(): bool
    {
        return $this->get() !== null;
    }
}
