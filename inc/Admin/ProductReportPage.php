<?php

namespace CBSNorthStar\Admin;

use CBSNorthStar\Repositories\DeployRunRepository;
use CBSNorthStar\Admin\DeployRunActionsHandler;

/**
 * ProductReportPage — "Product Report Deploy" admin page.
 *
 * Displays the full deploy run history including statuses, trigger sources,
 * lock conflicts, product-level outcomes, and a per-run event timeline.
 *
 * Menu path: WooCommerce > Product Report Deploy
 * Capability: manage_woocommerce
 *
 * Two views:
 *   list   — paginated run history with stat cards, filters, and sortable table.
 *   detail — single run: metadata card + chronological event timeline.
 *
 * Security notes:
 *   - All output is run through esc_html() / esc_attr() / esc_url() before rendering.
 *   - run_id from $_GET is validated against the UUID v4 format before use in queries.
 *   - Filter inputs are sanitized via sanitize_text_field() / sanitize_key().
 *   - No state-changing operations are exposed on this page; nonce protection is
 *     included on the future "retry" action placeholder as a reference pattern.
 *   - capability check is enforced in both addMenuPage() and renderPage().
 */
class ProductReportPage
{
    /** WordPress admin page slug. */
    const PAGE_SLUG = 'cbs-product-report-deploy';

    /** Number of runs shown per page in the list view. */
    const PER_PAGE = 20;

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register all hooks. Call once from plugins_loaded.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action( 'admin_menu',            [ self::class, 'addMenuPage' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueueAssets' ] );
    }

    /**
     * Add the submenu page under WooCommerce.
     *
     * @return void
     */
    public static function addMenuPage(): void
    {
        add_submenu_page(
            'woocommerce',
            'Product Report Deploy',
            'Product Report Deploy',
            'manage_woocommerce',
            self::PAGE_SLUG,
            [ self::class, 'renderPage' ]
        );
    }

    /**
     * Enqueue any assets specific to this page.
     * Inherits WooCommerce admin styles; no separate stylesheet needed yet.
     *
     * @param string $hook Current admin page hook suffix.
     * @return void
     */
    public static function enqueueAssets( string $hook ): void
    {
        if ( $hook !== 'woocommerce_page_' . self::PAGE_SLUG ) {
            return;
        }
        // Reserve: enqueue page-specific JS here when interactive features are added.
    }

    // -------------------------------------------------------------------------
    // Main router
    // -------------------------------------------------------------------------

    /**
     * Top-level render callback. Routes to list or detail view.
     *
     * Security: capability is re-checked here even though WordPress checks it
     * at menu registration time — defence in depth for direct URL access.
     *
     * @return void
     */
    public static function renderPage(): void
    {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.' ) );
        }

        $view  = sanitize_key( $_GET['view'] ?? 'list' );
        // Validate run_id: must be a valid UUID v4 or empty string.
        $runId = self::sanitizeRunId( $_GET['run_id'] ?? '' );
        ?>
        <div class="wrap cbs-prd-wrap">
            <h1>Product Report Deploy</h1>
            <?php
            self::renderAdminNotice();

            if ( $view === 'detail' && $runId !== '' ) {
                self::renderDetailView( $runId );
            } else {
                self::renderListView();
            }
            self::renderStyles();
            ?>
        </div>
        <?php
    }

    // =========================================================================
    // LIST VIEW
    // =========================================================================

    /**
     * Render the paginated run history table with stat cards and filters.
     *
     * @return void
     */
    private static function renderListView(): void
    {
        $repo    = DeployRunRepository::create();
        $filters = self::getFilters();
        $page    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $offset  = ( $page - 1 ) * self::PER_PAGE;

        $stats = $repo->getStats();
        $total = $repo->countRuns( $filters );
        $runs  = $repo->getRunSummaries( $filters, self::PER_PAGE, $offset );

        self::renderStatCards( $stats );
        self::renderFilterForm( $filters );

        if ( empty( $runs ) ) {
            echo '<p>No deploy runs found matching your filters.</p>';
            return;
        }

        self::renderRunsTable( $runs );
        self::renderPagination( $total, self::PER_PAGE, $page, $filters );
    }

    // -------------------------------------------------------------------------
    // Stat cards
    // -------------------------------------------------------------------------

    /**
     * Render the summary stat cards above the table.
     *
     * @param array $stats Keys: total, completed, failed, blocked, partial_success.
     * @return void
     */
    private static function renderStatCards( array $stats ): void
    {
        $cards = [
            'total'           => 'Total Runs',
            'completed'       => 'Completed',
            'failed'          => 'Failed',
            'blocked'         => 'Blocked',
            'partial_success' => 'Partial',
        ];

        echo '<div class="cbs-prd-stat-cards">';
        foreach ( $cards as $key => $label ) {
            $count    = (int) ( $stats[ $key ] ?? 0 );
            $modifier = $key !== 'total' && $key !== 'completed' && $count > 0 ? ' cbs-prd-stat-alert' : '';
            printf(
                '<div class="cbs-prd-stat-card%s"><div class="count">%d</div><div class="label">%s</div></div>',
                esc_attr( $modifier ),
                $count,
                esc_html( $label )
            );
        }
        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Filter form
    // -------------------------------------------------------------------------

    /**
     * Render the filter bar above the table.
     *
     * Security: all filter values are escaped for HTML attributes.
     * No user input is placed unescaped into the DOM.
     *
     * @param array $filters Current active filters.
     * @return void
     */
    private static function renderFilterForm( array $filters ): void
    {
        $statuses = [
            ''                => 'All Statuses',
            'completed'       => 'Completed',
            'failed'          => 'Failed',
            'blocked'         => 'Blocked',
            'partial_success' => 'Partial Success',
            'running'         => 'Running',
            'stale'           => 'Stale',
        ];

        $triggers = [
            ''           => 'All Triggers',
            'manual'     => 'Manual',
            'hook'       => 'Hook',
            'cron'       => 'Cron',
            'background' => 'Background',
            'cli'        => 'CLI',
        ];

        echo '<form method="get" class="cbs-prd-filters">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';

        // Status
        echo '<label>Status: <select name="status">';
        foreach ( $statuses as $val => $lbl ) {
            $sel = ( ( $filters['status'] ?? '' ) === $val ) ? ' selected' : '';
            printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), $sel, esc_html( $lbl ) );
        }
        echo '</select></label>';

        // Trigger type
        echo '<label>Trigger: <select name="trigger_type">';
        foreach ( $triggers as $val => $lbl ) {
            $sel = ( ( $filters['trigger_type'] ?? '' ) === $val ) ? ' selected' : '';
            printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), $sel, esc_html( $lbl ) );
        }
        echo '</select></label>';

        // Date range
        printf(
            '<label>From: <input type="date" name="date_from" value="%s"></label>',
            esc_attr( $filters['date_from'] ?? '' )
        );
        printf(
            '<label>To: <input type="date" name="date_to" value="%s"></label>',
            esc_attr( $filters['date_to'] ?? '' )
        );

        // Product search (ID or SKU)
        printf(
            '<label>Product (ID or SKU): <input type="text" name="product_search" value="%s" placeholder="e.g. 42 or SKU-001" style="width:150px"></label>',
            esc_attr( $filters['product_search'] ?? '' )
        );

        echo '<button type="submit" class="button">Filter</button>';

        if ( array_filter( $filters ) ) {
            $clearUrl = esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
            echo '<a href="' . $clearUrl . '" class="button">Clear</a>';
        }

        echo '</form>';
    }

    // -------------------------------------------------------------------------
    // Runs table
    // -------------------------------------------------------------------------

    /**
     * Render the main runs table.
     *
     * @param array $runs Array of run rows from DeployRunRepository::getRunSummaries().
     * @return void
     */
    private static function renderRunsTable( array $runs ): void
    {
        echo '<table class="cbs-prd-table wp-list-table widefat fixed striped">';
        echo '<thead><tr>
            <th style="width:90px">Run ID</th>
            <th style="width:90px">Trigger</th>
            <th style="width:100px">Status</th>
            <th style="width:130px">Started</th>
            <th style="width:130px">Finished</th>
            <th style="width:80px">Duration</th>
            <th style="width:120px">Sites</th>
            <th>Message / Conflict</th>
            <th style="width:70px">Detail</th>
        </tr></thead><tbody>';

        foreach ( $runs as $run ) {
            self::renderRunRow( $run );
        }

        echo '</tbody></table>';
    }

    /**
     * Render one table row for a single run.
     *
     * @param array $run Associative run row.
     * @return void
     */
    private static function renderRunRow( array $run ): void
    {
        $runId      = esc_html( $run['run_id'] ?? '' );
        $runIdShort = esc_html( substr( $run['run_id'] ?? '', 0, 8 ) ) . '&hellip;';

        $startedAt  = ! empty( $run['started_at'] )
            ? esc_html( wp_date( 'M j, Y g:i:s a', strtotime( $run['started_at'] ) ) )
            : '—';

        $finishedAt = ! empty( $run['finished_at'] )
            ? esc_html( wp_date( 'M j, Y g:i:s a', strtotime( $run['finished_at'] ) ) )
            : '—';

        $duration = self::formatDuration( isset( $run['duration_ms'] ) ? (int) $run['duration_ms'] : null );

        $products = sprintf(
            '<span title="Sites attempted">%d</span> / <span title="Sites succeeded" style="color:green">%d</span> / <span title="Sites failed" style="color:red">%d</span>',
            (int) ( $run['products_attempted'] ?? 0 ),
            (int) ( $run['products_succeeded'] ?? 0 ),
            (int) ( $run['products_failed']    ?? 0 )
        );

        // Summary: show conflict reason when blocked, otherwise show error message or truncated log message.
        $summary = '';
        if ( ! empty( $run['conflict_type'] ) ) {
            $summary = '<span class="cbs-prd-conflict">' . esc_html( $run['conflict_type'] ) . '</span>';
            if ( ! empty( $run['blocked_by_run_id'] ) ) {
                $summary .= ' <span style="color:#888;font-size:11px">by ' . esc_html( substr( $run['blocked_by_run_id'], 0, 8 ) ) . '&hellip;</span>';
            }
        } elseif ( ! empty( $run['error_message'] ) ) {
            $summary = '<span style="color:#a00">' . esc_html( substr( $run['error_message'], 0, 120 ) ) . ( strlen( $run['error_message'] ) > 120 ? '&hellip;' : '' ) . '</span>';
        }

        $detailUrl = esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&view=detail&run_id=' . urlencode( $run['run_id'] ?? '' ) ) );

        $userCell = '';
        if ( ! empty( $run['user_login'] ) ) {
            $userCell = '<br><span style="color:#666;font-size:11px">@' . esc_html( $run['user_login'] ) . '</span>';
        }

        printf(
            '<tr>
                <td><code title="%s">%s</code></td>
                <td>%s%s</td>
                <td>%s</td>
                <td>%s</td>
                <td>%s</td>
                <td>%s</td>
                <td>%s</td>
                <td>%s</td>
                <td><a href="%s" class="button button-small">View &rarr;</a></td>
            </tr>',
            $runId,
            $runIdShort,
            self::triggerBadge( $run['trigger_type'] ?? '' ),
            $userCell,
            self::statusBadge( $run['status'] ?? '' ),
            $startedAt,
            $finishedAt,
            esc_html( $duration ),
            $products,
            $summary,
            $detailUrl
        );
    }

    // -------------------------------------------------------------------------
    // Pagination
    // -------------------------------------------------------------------------

    /**
     * Render simple numbered pagination links.
     *
     * @param int   $total   Total matching run count.
     * @param int   $perPage Runs per page.
     * @param int   $page    Current page number (1-based).
     * @param array $filters Active filters (included in pagination links).
     * @return void
     */
    private static function renderPagination( int $total, int $perPage, int $page, array $filters ): void
    {
        $totalPages = (int) ceil( $total / $perPage );

        if ( $totalPages <= 1 ) {
            return;
        }

        $baseUrl = admin_url( 'admin.php?page=' . self::PAGE_SLUG . self::buildFilterQuery( $filters ) );

        echo '<div class="cbs-prd-pagination tablenav bottom"><div class="tablenav-pages">';
        printf( '<span class="displaying-num">%d runs</span>', $total );

        for ( $p = 1; $p <= $totalPages; $p++ ) {
            $cls = ( $p === $page ) ? 'button button-primary' : 'button';
            printf(
                '<a href="%s" class="%s" style="margin:0 2px">%d</a>',
                esc_url( $baseUrl . '&paged=' . $p ),
                esc_attr( $cls ),
                $p
            );
        }

        echo '</div></div>';
    }

    // =========================================================================
    // DETAIL VIEW
    // =========================================================================

    /**
     * Render the detail view for a single run: metadata card + event timeline.
     *
     * @param string $runId Validated UUID of the run to display.
     * @return void
     */
    private static function renderDetailView( string $runId ): void
    {
        $repo = DeployRunRepository::create();
        $run  = $repo->findByRunId( $runId );

        $backUrl = esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
        echo '<p><a href="' . $backUrl . '" class="button">&larr; Back to Runs</a></p>';

        if ( $run === null ) {
            echo '<div class="notice notice-error"><p>Run not found: <code>' . esc_html( $runId ) . '</code></p></div>';
            return;
        }

        self::renderRunMeta( $run );
        self::renderRecoveryActions( $run );
        self::renderRetryChainNotice( $run );

        $events = $repo->getEventsForRun( $runId );
        self::renderEventTimeline( $events );
    }

    // -------------------------------------------------------------------------
    // Run metadata card
    // -------------------------------------------------------------------------

    /**
     * Render a definition-list style metadata card for a single run.
     *
     * @param array $run Associative run row.
     * @return void
     */
    private static function renderRunMeta( array $run ): void
    {
        echo '<div class="cbs-prd-meta-card">';
        echo '<h2>Run Details</h2>';
        echo '<dl class="cbs-prd-dl">';

        // Run ID
        echo '<dt>Run ID</dt><dd><code>' . esc_html( $run['run_id'] ?? '—' ) . '</code></dd>';

        // Status + Trigger badges
        echo '<dt>Status</dt><dd>' . self::statusBadge( $run['status'] ?? '' ) . '</dd>';
        echo '<dt>Trigger</dt><dd>' . self::triggerBadge( $run['trigger_type'] ?? '' ) . '</dd>';

        // Simple scalar fields
        $scalarFields = [
            'Trigger Source' => $run['trigger_source'] ?? '—',
            'User'           => ! empty( $run['user_login'] )
                                    ? $run['user_login'] . ' (ID: ' . ( $run['user_id'] ?? '?' ) . ')'
                                    : '— (background / hook)',
            'Started'        => ! empty( $run['started_at'] )
                                    ? wp_date( 'M j, Y g:i:s a', strtotime( $run['started_at'] ) )
                                    : '—',
            'Finished'       => ! empty( $run['finished_at'] )
                                    ? wp_date( 'M j, Y g:i:s a', strtotime( $run['finished_at'] ) )
                                    : '—',
            'Duration'       => self::formatDuration( isset( $run['duration_ms'] ) ? (int) $run['duration_ms'] : null ),
            'Sites'          => sprintf(
                                    'Attempted: %d | Succeeded: %d | Failed: %d | Skipped: %d',
                                    (int) ( $run['products_attempted'] ?? 0 ),
                                    (int) ( $run['products_succeeded'] ?? 0 ),
                                    (int) ( $run['products_failed']    ?? 0 ),
                                    (int) ( $run['products_skipped']   ?? 0 )
                                ),
            'Conflict Type'  => ! empty( $run['conflict_type'] ) ? $run['conflict_type'] : '—',
            'Error Code'     => ! empty( $run['error_code'] ) ? $run['error_code'] : '—',
            'Plugin Version' => $run['plugin_version'] ?? '—',
        ];

        foreach ( $scalarFields as $label => $value ) {
            echo '<dt>' . esc_html( $label ) . '</dt>';
            echo '<dd>' . esc_html( (string) $value ) . '</dd>';
        }

        // Blocked By — link to the blocking run's detail page if we have its ID
        echo '<dt>Blocked By</dt><dd>';
        if ( ! empty( $run['blocked_by_run_id'] ) ) {
            $blockUrl = esc_url( admin_url(
                'admin.php?page=' . self::PAGE_SLUG . '&view=detail&run_id=' . urlencode( $run['blocked_by_run_id'] )
            ) );
            printf(
                '<a href="%s"><code>%s</code></a>',
                $blockUrl,
                esc_html( $run['blocked_by_run_id'] )
            );
        } else {
            echo '—';
        }
        echo '</dd>';

        // Retried From — link to the original run when this is a retry/requeue
        if ( ! empty( $run['retried_from_run_id'] ) ) {
            $originUrl = esc_url( admin_url(
                'admin.php?page=' . self::PAGE_SLUG . '&view=detail&run_id=' . urlencode( $run['retried_from_run_id'] )
            ) );
            echo '<dt>Retried From</dt><dd>';
            printf(
                '<a href="%s"><code>%s</code></a>',
                $originUrl,
                esc_html( $run['retried_from_run_id'] )
            );
            echo '</dd>';
        }

        // Error Message — plain display + expandable raw text
        echo '<dt>Error Message</dt><dd>';
        if ( ! empty( $run['error_message'] ) ) {
            $errMsg = $run['error_message'];
            // Show a truncated preview; full text inside a <details> toggle.
            $preview = strlen( $errMsg ) > 200 ? substr( $errMsg, 0, 200 ) . '…' : $errMsg;
            echo '<span class="cbs-prd-err-preview">' . esc_html( $preview ) . '</span>';
            if ( strlen( $errMsg ) > 200 ) {
                echo '<details class="cbs-prd-raw"><summary>Show full error</summary>';
                echo '<pre class="cbs-prd-raw-pre">' . esc_html( $errMsg ) . '</pre>';
                echo '</details>';
            }
        } else {
            echo '—';
        }
        echo '</dd>';

        echo '</dl></div>';
    }

    // -------------------------------------------------------------------------
    // Event timeline
    // -------------------------------------------------------------------------

    /**
     * Render the chronological event timeline for a run.
     *
     * Each event's `context` column (JSON) is decoded and displayed as a
     * definition list so developers can see structured debug data inline.
     *
     * Security: context JSON keys and values are run through esc_html() before
     * output; no raw JSON is echoed to the page.
     *
     * @param array $events Array of event rows from DeployRunRepository::getEventsForRun().
     * @return void
     */
    private static function renderEventTimeline( array $events ): void
    {
        echo '<h2>Event Timeline</h2>';

        if ( empty( $events ) ) {
            echo '<p>No events recorded for this run.</p>';
            return;
        }

        echo '<table class="cbs-prd-table cbs-prd-timeline wp-list-table widefat striped">';
        echo '<thead><tr>
            <th style="width:120px">Time</th>
            <th style="width:75px">Severity</th>
            <th style="width:140px">Event</th>
            <th style="width:70px">Product</th>
            <th style="width:220px">Message</th>
            <th>Context</th>
        </tr></thead><tbody>';

        foreach ( $events as $event ) {
            $time = ! empty( $event['occurred_at'] )
                ? esc_html( wp_date( 'M j g:i:s a', strtotime( $event['occurred_at'] ) ) )
                : '—';

            $product = '—';
            if ( ! empty( $event['product_id'] ) || ! empty( $event['product_sku'] ) ) {
                $product = esc_html(
                    trim( ( $event['product_sku'] ?? '' ) . ' #' . ( $event['product_id'] ?? '' ), ' #' )
                );
            }

            $contextHtml = self::renderContextCell( $event['context'] ?? null );

            printf(
                '<tr>
                    <td style="white-space:nowrap;font-size:12px">%s</td>
                    <td>%s</td>
                    <td><code style="font-size:11px">%s</code></td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                </tr>',
                $time,
                self::severityBadge( $event['severity'] ?? 'info' ),
                esc_html( $event['event_type'] ?? '' ),
                $product,
                esc_html( $event['message'] ?? '' ),
                $contextHtml
            );
        }

        echo '</tbody></table>';
    }

    /**
     * Decode a JSON context blob and render it as a safe HTML definition list.
     * Returns '—' when context is empty or invalid JSON.
     *
     * Security: every key and value passes through esc_html() — no raw data output.
     *
     * @param string|null $json Raw JSON string from the DB.
     * @return string HTML string (already escaped).
     */
    private static function renderContextCell( ?string $json ): string
    {
        if ( empty( $json ) ) {
            return '—';
        }

        $data = json_decode( $json, true );

        if ( ! is_array( $data ) || empty( $data ) ) {
            return '—';
        }

        $html = '<dl class="cbs-prd-ctx-dl">';
        foreach ( $data as $key => $value ) {
            $displayValue = is_array( $value ) || is_object( $value )
                ? json_encode( $value, JSON_UNESCAPED_SLASHES )
                : (string) $value;

            $html .= '<dt>' . esc_html( $key ) . '</dt>';
            $html .= '<dd>' . esc_html( substr( $displayValue, 0, 300 ) ) . '</dd>';
        }
        $html .= '</dl>';

        return $html;
    }

    // =========================================================================
    // ADMIN NOTICE
    // =========================================================================

    /**
     * Display the transient-based notice set by DeployRunActionsHandler after redirect.
     * Consumes the transient so the notice only appears once.
     *
     * @return void
     */
    private static function renderAdminNotice(): void
    {
        $notice = DeployRunActionsHandler::popNotice();

        if ( $notice === null ) {
            return;
        }

        $type = in_array( $notice['type'], [ 'success', 'error', 'warning', 'info' ], true )
            ? $notice['type']
            : 'info';

        printf(
            '<div class="notice notice-%s is-dismissible cbs-prd-notice"><p>%s</p></div>',
            esc_attr( $type ),
            esc_html( $notice['message'] )
        );
    }

    // =========================================================================
    // RECOVERY ACTIONS
    // =========================================================================

    /**
     * Render the recovery action buttons panel below the run metadata card.
     *
     * Available actions depend on the run's current status:
     *   failed / partial_success  → Retry Deploy
     *   blocked                   → Requeue Deploy
     *   running                   → Clear Stale Lock  (labelled with staleness context)
     *   completed / stale         → No actions available
     *
     * Each action is a self-contained POST form targeting admin-post.php.
     * Nonces are scoped to the specific run ID.
     * The submit button is disabled via JS on click to prevent double-submission.
     *
     * Security: capability is rechecked in the handler; nonces are generated
     * with wp_nonce_field() which automatically handles session binding.
     *
     * @param array $run Associative run row.
     * @return void
     */
    private static function renderRecoveryActions( array $run ): void
    {
        $status = $run['status'] ?? '';
        $runId  = $run['run_id'] ?? '';

        $retryableStatuses = [
            DeployRunRepository::STATUS_FAILED,
            DeployRunRepository::STATUS_PARTIAL_SUCCESS,
        ];

        $hasRetry     = in_array( $status, $retryableStatuses, true );
        $hasRequeue   = ( $status === DeployRunRepository::STATUS_BLOCKED );
        $hasClearLock = ( $status === DeployRunRepository::STATUS_RUNNING );

        if ( ! $hasRetry && ! $hasRequeue && ! $hasClearLock ) {
            return;
        }

        echo '<div class="cbs-prd-actions">';
        echo '<h3>Recovery Actions</h3>';

        // ── Retry ─────────────────────────────────────────────────────────────
        if ( $hasRetry ) {
            ?>
            <div class="cbs-prd-action-item">
                <strong>Retry Deploy</strong>
                <p class="description">
                    Runs a full product deploy now. A new run will be created and linked to this one.
                    <?php if ( $status === DeployRunRepository::STATUS_PARTIAL_SUCCESS ): ?>
                        <em>This run had partial failures — retry will re-attempt all products.</em>
                    <?php endif; ?>
                </p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                      onsubmit="this.querySelector('.cbs-prd-action-btn').disabled=true;this.querySelector('.cbs-prd-action-btn').textContent='Running…';">
                    <input type="hidden" name="action"  value="<?php echo esc_attr( DeployRunActionsHandler::ACTION_RETRY ); ?>">
                    <input type="hidden" name="run_id"  value="<?php echo esc_attr( $runId ); ?>">
                    <?php wp_nonce_field( DeployRunActionsHandler::retryNonceAction( $runId ) ); ?>
                    <button type="submit" class="button button-primary cbs-prd-action-btn">
                        Retry Deploy
                    </button>
                </form>
            </div>
            <?php
        }

        // ── Requeue ───────────────────────────────────────────────────────────
        if ( $hasRequeue ) {
            $blockedBy = $run['blocked_by_run_id'] ?? '';
            ?>
            <div class="cbs-prd-action-item">
                <strong>Requeue Deploy</strong>
                <p class="description">
                    This run was blocked because another deploy was in progress
                    <?php if ( $blockedBy ): ?>
                        (<?php echo esc_html( substr( $blockedBy, 0, 8 ) ); ?>…)
                    <?php endif; ?>.
                    Use this to trigger a fresh deploy now that the blocking run has finished.
                </p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                      onsubmit="this.querySelector('.cbs-prd-action-btn').disabled=true;this.querySelector('.cbs-prd-action-btn').textContent='Running…';">
                    <input type="hidden" name="action" value="<?php echo esc_attr( DeployRunActionsHandler::ACTION_REQUEUE ); ?>">
                    <input type="hidden" name="run_id" value="<?php echo esc_attr( $runId ); ?>">
                    <?php wp_nonce_field( DeployRunActionsHandler::requeueNonceAction( $runId ) ); ?>
                    <button type="submit" class="button button-primary cbs-prd-action-btn">
                        Requeue Deploy
                    </button>
                </form>
            </div>
            <?php
        }

        // ── Clear Stale Lock ──────────────────────────────────────────────────
        if ( $hasClearLock ) {
            $startedAt  = $run['started_at'] ?? '';
            $heartbeat  = $run['last_heartbeat_at'] ?? '';
            $lastActive = $heartbeat ?: $startedAt;
            $elapsed    = $lastActive ? ( time() - strtotime( $lastActive ) ) : 0;
            $minutes    = round( $elapsed / 60 );
            ?>
            <div class="cbs-prd-action-item cbs-prd-action-danger">
                <strong>Clear Stale Lock</strong>
                <p class="description">
                    This run has been in the <em>running</em> state
                    <?php if ( $minutes > 0 ): ?>
                        for approximately <strong><?php echo esc_html( $minutes ); ?> minute<?php echo $minutes !== 1 ? 's' : ''; ?></strong>
                    <?php endif; ?>.
                    If the deploy process died without releasing the lock, clearing it here
                    will mark this run as stale and unblock future deploys.
                    <strong>Only use this if you are certain the deploy process is no longer running.</strong>
                    This action is audited.
                </p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                      onsubmit="return confirm('Clear the lock for this run? Only do this if the deploy process is definitely no longer running.') && (this.querySelector(\'.cbs-prd-action-btn\').disabled=true,this.querySelector(\'.cbs-prd-action-btn\').textContent=\'Clearing…\',true);">
                    <input type="hidden" name="action" value="<?php echo esc_attr( DeployRunActionsHandler::ACTION_CLEAR_LOCK ); ?>">
                    <input type="hidden" name="run_id" value="<?php echo esc_attr( $runId ); ?>">
                    <?php wp_nonce_field( DeployRunActionsHandler::clearLockNonceAction( $runId ) ); ?>
                    <button type="submit" class="button cbs-prd-action-btn cbs-prd-action-btn-warn">
                        Clear Stale Lock
                    </button>
                </form>
            </div>
            <?php
        }

        echo '</div><!-- .cbs-prd-actions -->';
    }

    /**
     * Render a contextual banner when this run is part of a retry chain —
     * either it was itself retried, or it is a retry of another run.
     *
     * @param array $run Associative run row.
     * @return void
     */
    private static function renderRetryChainNotice( array $run ): void
    {
        // This run is a retry/requeue of an earlier run
        if ( ! empty( $run['retried_from_run_id'] ) ) {
            $originUrl = esc_url( admin_url(
                'admin.php?page=' . self::PAGE_SLUG . '&view=detail&run_id=' . urlencode( $run['retried_from_run_id'] )
            ) );
            printf(
                '<div class="notice notice-info cbs-prd-chain-notice"><p>This run is a retry/requeue of <a href="%s"><code>%s</code></a>.</p></div>',
                $originUrl,
                esc_html( $run['retried_from_run_id'] )
            );
        }
    }

    // =========================================================================
    // HELPERS — filters and URL building
    // =========================================================================

    /**
     * Extract and sanitize filter values from $_GET.
     *
     * Security: all values are sanitized before use. Only expected keys are
     * extracted — no pass-through of arbitrary GET parameters.
     *
     * @return array Sanitized filter map.
     */
    private static function getFilters(): array
    {
        return [
            'status'         => sanitize_key( $_GET['status']         ?? '' ),
            'trigger_type'   => sanitize_key( $_GET['trigger_type']   ?? '' ),
            'date_from'      => sanitize_text_field( $_GET['date_from']      ?? '' ),
            'date_to'        => sanitize_text_field( $_GET['date_to']        ?? '' ),
            'product_search' => sanitize_text_field( $_GET['product_search'] ?? '' ),
        ];
    }

    /**
     * Build a URL query string fragment from a filters array.
     * Used to preserve active filters across pagination links.
     *
     * @param array $filters Active filter map.
     * @return string URL-encoded query fragment (starts with '&' or empty string).
     */
    private static function buildFilterQuery( array $filters ): string
    {
        $parts = [];
        foreach ( $filters as $key => $val ) {
            if ( $val !== '' ) {
                $parts[] = urlencode( $key ) . '=' . urlencode( $val );
            }
        }
        return $parts ? '&' . implode( '&', $parts ) : '';
    }

    /**
     * Validate and return a UUID v4 string, or empty string on failure.
     *
     * Security: run_id from $_GET is untrusted. Only valid UUIDs are passed to
     * repository methods — prevents any SQL injection or path traversal attempt
     * even if prepare() is already in use downstream.
     *
     * @param string $raw Raw value from user input.
     * @return string Validated UUID or empty string.
     */
    private static function sanitizeRunId( string $raw ): string
    {
        $raw = sanitize_text_field( $raw );
        // UUID v4 format: xxxxxxxx-xxxx-4xxx-[89ab]xxx-xxxxxxxxxxxx
        if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $raw ) ) {
            return strtolower( $raw );
        }
        return '';
    }

    // =========================================================================
    // HELPERS — formatting and badges
    // =========================================================================

    /**
     * Format a duration in milliseconds to a human-readable string.
     *
     * @param int|null $ms Duration in milliseconds, or null if not recorded.
     * @return string Formatted duration (e.g. "2.4s", "1m 12s", "<1ms").
     */
    private static function formatDuration( ?int $ms ): string
    {
        if ( $ms === null ) {
            return '—';
        }
        if ( $ms < 1 ) {
            return '<1ms';
        }
        if ( $ms < 1000 ) {
            return $ms . 'ms';
        }
        if ( $ms < 60000 ) {
            return round( $ms / 1000, 1 ) . 's';
        }
        $minutes = (int) floor( $ms / 60000 );
        $seconds = (int) round( ( $ms % 60000 ) / 1000 );
        return $minutes . 'm ' . $seconds . 's';
    }

    /**
     * Return an HTML badge for a run status.
     *
     * @param string $status One of DeployRunRepository::STATUS_* values.
     * @return string HTML badge (already escaped).
     */
    private static function statusBadge( string $status ): string
    {
        $map = [
            'completed'       => 'cbs-badge-success',
            'partial_success' => 'cbs-badge-partial',
            'failed'          => 'cbs-badge-failed',
            'blocked'         => 'cbs-badge-blocked',
            'running'         => 'cbs-badge-running',
            'stale'           => 'cbs-badge-stale',
        ];

        $css   = $map[ $status ] ?? 'cbs-badge-neutral';
        $label = strtoupper( str_replace( '_', ' ', $status ) ) ?: '—';

        return '<span class="cbs-badge ' . esc_attr( $css ) . '">' . esc_html( $label ) . '</span>';
    }

    /**
     * Return an HTML badge for a trigger type.
     *
     * @param string $trigger One of DeployRunRepository::TRIGGER_* values.
     * @return string HTML badge (already escaped).
     */
    private static function triggerBadge( string $trigger ): string
    {
        $map = [
            'manual'     => 'cbs-badge-manual',
            'hook'       => 'cbs-badge-hook',
            'cron'       => 'cbs-badge-cron',
            'background' => 'cbs-badge-bg',
            'cli'        => 'cbs-badge-cli',
        ];

        $css   = $map[ $trigger ] ?? 'cbs-badge-neutral';
        $label = strtoupper( $trigger ) ?: '—';

        return '<span class="cbs-badge ' . esc_attr( $css ) . '">' . esc_html( $label ) . '</span>';
    }

    /**
     * Return an HTML badge for an event severity level.
     *
     * @param string $severity One of DeployRunRepository::SEVERITY_* values.
     * @return string HTML badge (already escaped).
     */
    private static function severityBadge( string $severity ): string
    {
        $map = [
            'debug'    => 'cbs-badge-neutral',
            'info'     => 'cbs-badge-info',
            'warning'  => 'cbs-badge-blocked',
            'error'    => 'cbs-badge-failed',
            'critical' => 'cbs-badge-critical',
        ];

        $css = $map[ $severity ] ?? 'cbs-badge-neutral';

        return '<span class="cbs-badge ' . esc_attr( $css ) . '">' . esc_html( strtoupper( $severity ) ) . '</span>';
    }

    // =========================================================================
    // INLINE STYLES
    // =========================================================================

    /**
     * Output scoped inline CSS for this page.
     * Keeps the page self-contained; no external stylesheet dependency.
     *
     * @return void
     */
    private static function renderStyles(): void
    {
        ?>
        <style>
        /* ── Layout ─────────────────────────────────────────────────────── */
        .cbs-prd-wrap { max-width: 1400px; }

        /* ── Stat cards ─────────────────────────────────────────────────── */
        .cbs-prd-stat-cards   { display:flex; gap:14px; margin:16px 0 20px; flex-wrap:wrap; }
        .cbs-prd-stat-card    { background:#f8f9fa; border:1px solid #ddd; border-radius:4px; padding:12px 22px; text-align:center; min-width:110px; }
        .cbs-prd-stat-card .count { font-size:28px; font-weight:700; color:#135e96; }
        .cbs-prd-stat-card .label { font-size:12px; color:#666; margin-top:2px; }
        .cbs-prd-stat-alert   { border-color:#c00; }
        .cbs-prd-stat-alert .count { color:#c00; }

        /* ── Filter bar ─────────────────────────────────────────────────── */
        .cbs-prd-filters      { display:flex; gap:10px; margin:14px 0; align-items:center; flex-wrap:wrap; background:#fff; border:1px solid #ddd; padding:10px 14px; border-radius:4px; }
        .cbs-prd-filters label  { font-weight:600; font-size:13px; }
        .cbs-prd-filters select,
        .cbs-prd-filters input[type=date],
        .cbs-prd-filters input[type=text] { padding:3px 6px; font-size:13px; }

        /* ── Main table ─────────────────────────────────────────────────── */
        .cbs-prd-table        { width:100%; border-collapse:collapse; margin-top:14px; font-size:13px; }
        .cbs-prd-table th     { background:#f1f1f1; padding:9px 11px; text-align:left; border-bottom:2px solid #ddd; }
        .cbs-prd-table td     { padding:9px 11px; border-bottom:1px solid #eee; vertical-align:top; }
        .cbs-prd-table tr:hover td { background:#fafafa; }

        /* ── Timeline table ─────────────────────────────────────────────── */
        .cbs-prd-timeline td  { font-size:12px; }

        /* ── Detail meta card ───────────────────────────────────────────── */
        .cbs-prd-meta-card    { background:#fff; border:1px solid #ddd; border-radius:4px; padding:16px 20px; margin:12px 0 20px; }
        .cbs-prd-meta-card h2 { margin-top:0; font-size:16px; }
        .cbs-prd-dl           { display:grid; grid-template-columns:180px 1fr; gap:4px 12px; margin:0; }
        .cbs-prd-dl dt        { font-weight:600; color:#555; white-space:nowrap; }
        .cbs-prd-dl dd        { margin:0; word-break:break-all; }

        /* ── Context DL inside timeline ─────────────────────────────────── */
        .cbs-prd-ctx-dl       { display:grid; grid-template-columns:max-content 1fr; gap:0 8px; margin:0; font-size:11px; }
        .cbs-prd-ctx-dl dt    { color:#888; white-space:nowrap; font-weight:600; }
        .cbs-prd-ctx-dl dd    { margin:0; word-break:break-all; color:#333; }

        /* ── Badges ─────────────────────────────────────────────────────── */
        .cbs-badge            { display:inline-block; padding:2px 7px; border-radius:3px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; }
        .cbs-badge-success    { background:#d4edda; color:#155724; }
        .cbs-badge-partial    { background:#fff3cd; color:#856404; }
        .cbs-badge-failed     { background:#f8d7da; color:#721c24; }
        .cbs-badge-blocked    { background:#ffe5b4; color:#7a4800; }
        .cbs-badge-running    { background:#cce5ff; color:#004085; }
        .cbs-badge-stale      { background:#e2e3e5; color:#383d41; }
        .cbs-badge-critical   { background:#6c1c24; color:#fff; }
        .cbs-badge-info       { background:#cce5ff; color:#004085; }
        .cbs-badge-neutral    { background:#e2e3e5; color:#383d41; }
        .cbs-badge-manual     { background:#d1ecf1; color:#0c5460; }
        .cbs-badge-hook       { background:#e2d9f3; color:#432874; }
        .cbs-badge-cron       { background:#d4edda; color:#155724; }
        .cbs-badge-bg         { background:#e2e3e5; color:#383d41; }
        .cbs-badge-cli        { background:#f5c6cb; color:#721c24; }

        /* ── Conflict label ─────────────────────────────────────────────── */
        .cbs-prd-conflict     { font-family:monospace; font-size:11px; background:#fff3cd; color:#856404; padding:1px 5px; border-radius:3px; }

        /* ── Pagination ─────────────────────────────────────────────────── */
        .cbs-prd-pagination   { margin-top:14px; }
        .cbs-prd-pagination .displaying-num { margin-right:10px; color:#666; font-size:13px; }

        /* ── Recovery action panel ───────────────────────────────────────── */
        .cbs-prd-actions      { background:#fff; border:1px solid #ddd; border-radius:4px; padding:16px 20px; margin:0 0 20px; }
        .cbs-prd-actions h3   { margin-top:0; font-size:14px; border-bottom:1px solid #eee; padding-bottom:8px; margin-bottom:12px; }
        .cbs-prd-action-item  { margin-bottom:14px; padding-bottom:14px; border-bottom:1px solid #f0f0f0; }
        .cbs-prd-action-item:last-child { margin-bottom:0; padding-bottom:0; border-bottom:none; }
        .cbs-prd-action-item strong { display:block; font-size:13px; margin-bottom:4px; }
        .cbs-prd-action-item .description { margin:0 0 8px; color:#555; font-size:12px; max-width:680px; }
        .cbs-prd-action-item form { display:inline; }
        .cbs-prd-action-btn   { font-size:12px; }
        .cbs-prd-action-danger { border-left:3px solid #c00; padding-left:10px; }
        .cbs-prd-action-btn-warn { background:#c00; border-color:#900; color:#fff; }
        .cbs-prd-action-btn-warn:hover { background:#900; border-color:#700; color:#fff; }

        /* ── Raw error expand ────────────────────────────────────────────── */
        .cbs-prd-err-preview  { color:#a00; }
        .cbs-prd-raw          { margin-top:6px; }
        .cbs-prd-raw summary  { cursor:pointer; color:#0073aa; font-size:12px; }
        .cbs-prd-raw-pre      { background:#f6f6f6; border:1px solid #ddd; padding:8px 10px; font-size:11px;
                                 white-space:pre-wrap; word-break:break-all; max-height:260px; overflow-y:auto;
                                 margin-top:6px; border-radius:3px; }

        /* ── Retry chain notice ──────────────────────────────────────────── */
        .cbs-prd-chain-notice { margin:0 0 14px; }
        </style>
        <?php
    }
}
