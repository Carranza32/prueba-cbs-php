<?php

namespace CBSNorthStar\Admin;

use CBSNorthStar\Logger\CBSLogger;
use CBSNorthStar\Migrations\FixOrphanCategories;
use CBSNorthStar\Repositories\DeployRunRepository;
use CBSNorthStar\Services\DeployLockService;
use CBSNorthStar\Services\DeployOrchestrator;

/**
 * DeployRunActionsHandler — processes mutating admin actions on deploy runs.
 *
 * Hooks into WordPress's admin_post_{action} system so each action is a
 * standard POST-redirect-GET cycle: the form POSTs to admin-post.php, the
 * handler runs, stores a notice transient, and redirects back to the detail
 * page. No custom AJAX is required.
 *
 * Actions
 * ───────
 *   cbs_deploy_retry      — Re-run a failed or partial_success deploy.
 *   cbs_deploy_requeue    — Re-run a blocked deploy (the blocking run finished).
 *   cbs_deploy_clear_lock — Force-mark a stuck run as stale and clear its lock.
 *
 * Security model (same for all three handlers)
 * ─────────────────────────────────────────────
 *   1. current_user_can('manage_woocommerce')  — capability gate
 *   2. check_admin_referer($nonceAction)        — nonce + referrer check
 *      Nonce action is scoped to the specific run: 'cbs_retry_{runId}' etc.
 *      This prevents a valid nonce for run A from being replayed against run B.
 *   3. UUID v4 validation on run_id from $_POST — never passed raw to the DB
 *   4. Run status eligibility check             — no-op prevention
 *   5. Active-run pre-flight check              — prevents duplicate deploy
 *      (the orchestrator lock is the true guard; this gives a friendlier error)
 *
 * ⚠ Retry risks (see also: inline comments in handleRetry)
 * ──────────────────────────────────────────────────────────
 *   - Deploys run SYNCHRONOUSLY. A long sync may hit PHP max_execution_time.
 *     Future work: replace with wp_schedule_single_event for async dispatch.
 *   - A successful retry does not prevent a subsequent webhook from firing
 *     another deploy. The lock service serialises them; the second one is
 *     merely blocked, not lost.
 *   - Clicking retry while a deploy is in progress is blocked server-side.
 *     The form JS also disables the button on submit to prevent double-POST.
 *
 * Notice flow
 * ───────────
 *   setNotice() → stores a user-scoped transient (60 s TTL)
 *   popNotice() → ProductReportPage::renderPage() calls this to display + clear
 */
class DeployRunActionsHandler
{
    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /** admin_post action slug for retry. */
    const ACTION_RETRY      = 'cbs_deploy_retry';

    /** admin_post action slug for requeue. */
    const ACTION_REQUEUE    = 'cbs_deploy_requeue';

    /** admin_post action slug for stale lock clear. */
    const ACTION_CLEAR_LOCK = 'cbs_deploy_clear_lock';

    /** WordPress action hook for programmatic orphan-category migration. */
    const ACTION_FIX_ORPHAN_CATS = 'northstar_run_fix_orphan_categories';

    /**
     * Prefix for the per-user notice transient.
     * Full key: NOTICE_TRANSIENT_PREFIX . get_current_user_id()
     */
    const NOTICE_TRANSIENT_PREFIX = 'cbs_deploy_act_notice_';

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register all admin_post hooks. Call once from plugins_loaded.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action( 'admin_post_' . self::ACTION_RETRY,      [ self::class, 'handleRetry'           ] );
        add_action( 'admin_post_' . self::ACTION_REQUEUE,    [ self::class, 'handleRequeue'         ] );
        add_action( 'admin_post_' . self::ACTION_CLEAR_LOCK, [ self::class, 'handleClearLock'       ] );
        add_action( self::ACTION_FIX_ORPHAN_CATS,            [ self::class, 'handleFixOrphanCats'   ] );
    }

    // -------------------------------------------------------------------------
    // Action handlers
    // -------------------------------------------------------------------------

    /**
     * Retry a failed or partial_success deploy.
     *
     * Creates a brand-new run through the orchestrator (full lifecycle: lock,
     * store, close, release). The new run is linked to the original via:
     *   - retried_from_run_id column on the new run's DB row
     *   - run.retried event appended to the ORIGINAL run's event timeline
     *
     * Eligibility: status ∈ {failed, partial_success}
     *
     * @return void
     */
    public static function handleRetry(): void
    {
        self::checkCapability();

        $originalRunId = self::extractAndValidateRunId();
        // Nonce is scoped to this specific run — prevents cross-run replay.
        self::verifyNonce( 'cbs_retry_' . $originalRunId );

        $repo        = DeployRunRepository::create();
        $originalRun = self::loadRun( $repo, $originalRunId );

        $retryableStatuses = [
            DeployRunRepository::STATUS_FAILED,
            DeployRunRepository::STATUS_PARTIAL_SUCCESS,
        ];

        if ( ! in_array( $originalRun['status'], $retryableStatuses, true ) ) {
            self::redirectWithNotice(
                $originalRunId,
                sprintf(
                    'Cannot retry: run status is "%s". Only failed or partial_success runs can be retried.',
                    $originalRun['status']
                ),
                'error'
            );
        }

        // Pre-flight: no active run may be in progress.
        // The orchestrator's lock service is the true serialisation gate, but
        // checking here gives a clear, actionable error message before dispatch.
        $activeRun = $repo->findActiveRun();
        if ( $activeRun !== null ) {
            self::redirectWithNotice(
                $originalRunId,
                sprintf(
                    'Cannot retry while a deploy (%s…) is already in progress. Wait for it to finish first.',
                    substr( $activeRun['run_id'], 0, 8 )
                ),
                'error'
            );
        }

        // ── Dispatch ─────────────────────────────────────────────────────────
        // NOTE: run() is synchronous. For slow deploys, consider moving this to
        // wp_schedule_single_event() so the admin HTTP request returns immediately.
        $userId = get_current_user_id();
        $result = DeployOrchestrator::create()->run(
            DeployRunRepository::TRIGGER_MANUAL,
            $userId,
            'admin_retry'
        );

        // For non-blocked outcomes $result->run_id is the newly created run's UUID.
        // For blocked outcomes it is the incumbent's UUID — not useful for linking.
        $newRunId = $result->wasBlocked() ? '' : $result->run_id;

        // ── Link original → new run ───────────────────────────────────────────
        if ( ! empty( $newRunId ) ) {
            // Back-reference on the new run row so it's queryable.
            $repo->updateRun( $newRunId, [ 'retried_from_run_id' => $originalRunId ] );

            // Forward-link event on the original run's timeline — visible in
            // the detail view so the operator can follow the chain.
            $adminLogin = self::currentUserLogin();
            $repo->appendEvent(
                $originalRunId,
                DeployRunRepository::EVENT_RUN_RETRIED,
                DeployRunRepository::SEVERITY_INFO,
                sprintf( 'Retry run triggered by admin "%s".', $adminLogin ),
                [
                    'new_run_id'      => $newRunId,
                    'triggered_by_id' => $userId,
                    'triggered_by'    => $adminLogin,
                ]
            );
        }

        // ── Respond ───────────────────────────────────────────────────────────
        if ( $result->wasBlocked() ) {
            self::redirectWithNotice(
                $originalRunId,
                'Retry was blocked — another run became active just before dispatch: ' . $result->message,
                'warning'
            );
        }

        if ( $result->wasSuccessful() ) {
            self::redirectWithNotice(
                $newRunId,
                'Retry completed successfully.',
                'success'
            );
        }

        // Failed
        self::redirectWithNotice(
            ! empty( $newRunId ) ? $newRunId : $originalRunId,
            'Retry run completed with errors: ' . $result->message,
            'error'
        );
    }

    /**
     * Requeue a blocked deploy.
     *
     * A blocked run never executed (the lock was already held). This action
     * gives the operator a way to replay the blocked attempt now that the
     * blocking run has presumably finished.
     *
     * Creates a new run linked back to the blocked original via:
     *   - retried_from_run_id column on the new run row
     *   - run.requeued event on the original run's timeline
     *
     * Eligibility: status = blocked
     *
     * @return void
     */
    public static function handleRequeue(): void
    {
        self::checkCapability();

        $originalRunId = self::extractAndValidateRunId();
        self::verifyNonce( 'cbs_requeue_' . $originalRunId );

        $repo        = DeployRunRepository::create();
        $originalRun = self::loadRun( $repo, $originalRunId );

        if ( $originalRun['status'] !== DeployRunRepository::STATUS_BLOCKED ) {
            self::redirectWithNotice(
                $originalRunId,
                sprintf(
                    'Cannot requeue: run status is "%s". Only blocked runs can be requeued.',
                    $originalRun['status']
                ),
                'error'
            );
        }

        $activeRun = $repo->findActiveRun();
        if ( $activeRun !== null ) {
            self::redirectWithNotice(
                $originalRunId,
                sprintf(
                    'Cannot requeue while a deploy (%s…) is already in progress.',
                    substr( $activeRun['run_id'], 0, 8 )
                ),
                'error'
            );
        }

        $userId = get_current_user_id();
        $result = DeployOrchestrator::create()->run(
            DeployRunRepository::TRIGGER_MANUAL,
            $userId,
            'admin_requeue'
        );

        $newRunId = $result->wasBlocked() ? '' : $result->run_id;

        if ( ! empty( $newRunId ) ) {
            $repo->updateRun( $newRunId, [ 'retried_from_run_id' => $originalRunId ] );

            $adminLogin = self::currentUserLogin();
            $repo->appendEvent(
                $originalRunId,
                DeployRunRepository::EVENT_RUN_REQUEUED,
                DeployRunRepository::SEVERITY_INFO,
                sprintf( 'Requeued by admin "%s".', $adminLogin ),
                [
                    'new_run_id'      => $newRunId,
                    'triggered_by_id' => $userId,
                    'triggered_by'    => $adminLogin,
                ]
            );
        }

        if ( $result->wasBlocked() ) {
            self::redirectWithNotice(
                $originalRunId,
                'Requeue was blocked — another run is still active: ' . $result->message,
                'warning'
            );
        }

        if ( $result->wasSuccessful() ) {
            self::redirectWithNotice( $newRunId, 'Requeue completed successfully.', 'success' );
        }

        self::redirectWithNotice(
            ! empty( $newRunId ) ? $newRunId : $originalRunId,
            'Requeue run completed with errors: ' . $result->message,
            'error'
        );
    }

    /**
     * Force-clear the deploy lock for a stuck running run.
     *
     * Marks the DB run as stale and removes the Memcached lock key (if owned
     * by this run). An audit event is always written regardless of whether the
     * cache key existed.
     *
     * This does NOT start a new run. After clearing, the operator can manually
     * trigger a fresh deploy from the WooCommerce settings page or wait for the
     * next webhook.
     *
     * Eligibility: status = running (checked inside adminForceClearLock)
     *
     * @return void
     */
    public static function handleClearLock(): void
    {
        self::checkCapability();

        $runId = self::extractAndValidateRunId();
        self::verifyNonce( 'cbs_clear_lock_' . $runId );

        $repo = DeployRunRepository::create();
        $run  = self::loadRun( $repo, $runId );

        if ( $run['status'] !== DeployRunRepository::STATUS_RUNNING ) {
            self::redirectWithNotice(
                $runId,
                sprintf(
                    'Cannot clear lock: run status is "%s". Only a running run holds an active lock.',
                    $run['status']
                ),
                'error'
            );
        }

        $cleared = DeployLockService::create()->adminForceClearLock( $runId, get_current_user_id() );

        if ( ! $cleared ) {
            self::redirectWithNotice(
                $runId,
                'Lock clear failed. The run may have already finished or its status changed. Refresh and try again.',
                'error'
            );
        }

        self::redirectWithNotice(
            $runId,
            'Lock cleared. The run has been marked stale. You can now trigger a new deploy.',
            'success'
        );
    }

    // -------------------------------------------------------------------------
    // Notice API — used by ProductReportPage
    // -------------------------------------------------------------------------

    /**
     * Persist a notice for the current user, to be displayed after redirect.
     *
     * @param string $message Plain-text message (escaped on display).
     * @param string $type    One of: success, error, warning, info.
     * @return void
     */
    public static function setNotice( string $message, string $type = 'success' ): void
    {
        $key = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
        set_transient( $key, [ 'type' => $type, 'message' => $message ], 60 );
    }

    /**
     * Read and delete the stored notice for the current user.
     * Returns null when no notice is pending.
     *
     * @return array{type: string, message: string}|null
     */
    public static function popNotice(): ?array
    {
        $key    = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
        $notice = get_transient( $key );

        if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
            return null;
        }

        delete_transient( $key );
        return $notice;
    }

    // -------------------------------------------------------------------------
    // Nonce helpers (called by ProductReportPage to generate form nonces)
    // -------------------------------------------------------------------------

    /** @return string Nonce action string for the retry form. */
    public static function retryNonceAction( string $runId ): string
    {
        return 'cbs_retry_' . $runId;
    }

    /** @return string Nonce action string for the requeue form. */
    public static function requeueNonceAction( string $runId ): string
    {
        return 'cbs_requeue_' . $runId;
    }

    /** @return string Nonce action string for the clear-lock form. */
    public static function clearLockNonceAction( string $runId ): string
    {
        return 'cbs_clear_lock_' . $runId;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Die with a 403 if the current user lacks manage_woocommerce.
     */
    private static function checkCapability(): void
    {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die(
                esc_html__( 'You do not have permission to perform this action.' ),
                403
            );
        }
    }

    /**
     * Verify the request nonce and HTTP referrer.
     * wp_die()s on failure — uses standard WP admin referer mechanism.
     *
     * @param string $action Nonce action string.
     */
    private static function verifyNonce( string $action ): void
    {
        check_admin_referer( $action );
    }

    /**
     * Read, sanitize, and UUID-validate run_id from $_POST.
     * Redirects to the list page with an error notice when invalid.
     *
     * @return string Validated lowercase UUID v4.
     */
    private static function extractAndValidateRunId(): string
    {
        $raw = sanitize_text_field( $_POST['run_id'] ?? '' );

        if ( preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $raw
        ) ) {
            return strtolower( $raw );
        }

        self::setNotice( 'Invalid or missing run ID.', 'error' );
        wp_safe_redirect( admin_url( 'admin.php?page=' . ProductReportPage::PAGE_SLUG ) );
        exit;
    }

    /**
     * Load a run row; redirect to the list page with an error if not found.
     *
     * @param DeployRunRepository $repo
     * @param string              $runId Validated UUID.
     * @return array The run row.
     */
    private static function loadRun( DeployRunRepository $repo, string $runId ): array
    {
        $run = $repo->findByRunId( $runId );

        if ( $run === null ) {
            self::setNotice( 'Run not found: ' . $runId, 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=' . ProductReportPage::PAGE_SLUG ) );
            exit;
        }

        return $run;
    }

    /**
     * Persist a notice and redirect to the run detail page (or list if $runId is empty).
     * Always exits.
     *
     * @param string $runId   UUID of the run to redirect to. Empty redirects to list view.
     * @param string $message Plain-text message.
     * @param string $type    Notice type: success, error, warning, info.
     */
    private static function redirectWithNotice( string $runId, string $message, string $type ): void
    {
        self::setNotice( $message, $type );

        $url = ! empty( $runId )
            ? admin_url( 'admin.php?page=' . ProductReportPage::PAGE_SLUG . '&view=detail&run_id=' . urlencode( $runId ) )
            : admin_url( 'admin.php?page=' . ProductReportPage::PAGE_SLUG );

        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Return the current user's login name, or 'unknown'.
     *
     * @return string
     */
    private static function currentUserLogin(): string
    {
        $user = wp_get_current_user();
        return $user->exists() ? $user->user_login : 'unknown';
    }

    // -------------------------------------------------------------------------
    // Orphan category migration hook
    // -------------------------------------------------------------------------

    /**
     * Handle the northstar_run_fix_orphan_categories action.
     *
     * Triggered programmatically via:
     *   do_action( 'northstar_run_fix_orphan_categories', $site_id );
     *
     * Requires manage_woocommerce capability. $site_id may be null to run for
     * all sites.
     *
     * @param int|null $site_id  Site to migrate, or null for all sites.
     * @return void
     */
    public static function handleFixOrphanCats( ?int $site_id = null ): void
    {
        $isCron = wp_doing_cron();
        $isCli  = defined( 'WP_CLI' ) && WP_CLI;
        if ( ! $isCron && ! $isCli && ! current_user_can( 'manage_woocommerce' ) ) {
            CBSLogger::products()->warning(
                'handleFixOrphanCats: skipped — no logged-in user with manage_woocommerce capability',
                array( 'site_id' => $site_id )
            );
            return;
        }
        ( new FixOrphanCategories() )->run( $site_id );
    }
}
