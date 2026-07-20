<?php
/**
 * CBS Session Report Admin Page
 *
 * WooCommerce sub-menu page showing all browser sessions where an order was
 * attempted, with order outcome (success/failed) and CSV export.
 *
 * Menu path: WooCommerce > CBS Session Report
 */

namespace CBSNorthStar\Admin;

use CBSNorthStar\Repositories\SessionEventRepository;

class SessionReportPage
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    public static function addMenuPage(): void
    {
        add_submenu_page(
            'woocommerce',
            'CBS Session Report',
            'CBS Session Report',
            'manage_woocommerce',
            'cbs-session-report',
            [self::class, 'renderPage']
        );
    }

    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== 'woocommerce_page_cbs-session-report') {
            return;
        }
        // Inherits WooCommerce admin styles; no separate CSS file needed.
    }

    // -------------------------------------------------------------------------
    // Main page router
    // -------------------------------------------------------------------------

    public static function renderPage(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        // CSV export must run before any output so we can send headers
        if (isset($_GET['export_csv'])) {
            check_admin_referer('cbs_session_report_export');
            self::handleCsvExport();
            exit;
        }

        $view = sanitize_key($_GET['view'] ?? 'list');
        $ref  = sanitize_text_field($_GET['ref'] ?? '');

        ?>
        <div class="wrap cbs-session-report">
            <h1>CBS Session Report</h1>
            <?php
            if ($view === 'detail' && $ref !== '') {
                self::renderDetailView($ref);
            } else {
                self::renderListView();
            }
            ?>
        </div>

        <style>
        .cbs-session-report { max-width: 1300px; }
        .cbs-sr-table { width: 100%; border-collapse: collapse; }
        .cbs-sr-table th { background: #f1f1f1; padding: 10px 12px; text-align: left; border-bottom: 2px solid #ddd; }
        .cbs-sr-table td { padding: 10px 12px; border-bottom: 1px solid #eee; vertical-align: top; }
        .cbs-sr-table tr:hover td { background: #fafafa; }
        .cbs-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; margin: 1px; }
        .cbs-badge-success  { background: #d4edda; color: #155724; }
        .cbs-badge-failed   { background: #f8d7da; color: #721c24; }
        .cbs-badge-event    { background: #e2e3e5; color: #383d41; }
        .cbs-stat-cards     { display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
        .cbs-stat-card      { background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; padding: 12px 20px; text-align: center; min-width: 130px; }
        .cbs-stat-card .count { font-size: 28px; font-weight: 700; color: #135e96; }
        .cbs-stat-card .label { font-size: 12px; color: #666; }
        .cbs-filters        { margin: 16px 0; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .cbs-filters label  { font-weight: 600; }
        .cbs-filters input[type=date] { padding: 4px 8px; }
        .cbs-export-btn     { margin-left: auto; }
        .cbs-timeline td    { padding: 8px 12px; border-bottom: 1px solid #eee; }
        .cbs-timeline th    { background: #f1f1f1; padding: 8px 12px; text-align: left; }
        .cbs-detail-dl      { margin: 0; display: grid; grid-template-columns: max-content 1fr; gap: 2px 12px; }
        .cbs-detail-dl dt   { font-weight: 600; color: #555; white-space: nowrap; }
        .cbs-detail-dl dd   { margin: 0; word-break: break-all; }
        </style>
        <?php
    }

    // -------------------------------------------------------------------------
    // List view
    // -------------------------------------------------------------------------

    private static function renderListView(): void
    {
        $repo    = SessionEventRepository::create();
        $filters = self::getFilters();
        $perPage = 25;
        $page    = max(1, (int) ($_GET['paged'] ?? 1));
        $offset  = ($page - 1) * $perPage;

        $stats   = $repo->getStats();
        $total   = $repo->countSessions($filters);
        $rows    = $repo->getSessionSummaries($filters, $perPage, $offset);

        // Stat cards
        echo '<div class="cbs-stat-cards">';
        foreach ([
            'total'      => 'Total Sessions',
            'successful' => 'Successful',
            'failed'     => 'Failed',
        ] as $key => $label) {
            $cnt = (int) ($stats[$key] ?? 0);
            echo "<div class='cbs-stat-card'><div class='count'>{$cnt}</div><div class='label'>{$label}</div></div>";
        }
        echo '</div>';

        // Filters + export
        $exportUrl = wp_nonce_url(
            admin_url('admin.php?page=cbs-session-report&export_csv=1' . self::buildFilterQuery($filters)),
            'cbs_session_report_export'
        );

        echo '<form method="get" class="cbs-filters">';
        echo '<input type="hidden" name="page" value="cbs-session-report">';
        echo '<label>From: <input type="date" name="date_from" value="' . esc_attr($filters['date_from'] ?? '') . '"></label>';
        echo '<label>To: <input type="date" name="date_to" value="' . esc_attr($filters['date_to'] ?? '') . '"></label>';

        echo '<label>Status: <select name="status">';
        echo '<option value="">All</option>';
        foreach (['success' => 'Success', 'failed' => 'Failed'] as $val => $lbl) {
            $sel = ($filters['status'] ?? '') === $val ? 'selected' : '';
            echo "<option value='" . esc_attr($val) . "' {$sel}>{$lbl}</option>";
        }
        echo '</select></label>';

        echo '<label>Site ID: <input type="text" name="site_id" value="' . esc_attr($filters['site_id'] ?? '') . '" style="width:120px"></label>';

        echo '<button type="submit" class="button">Filter</button>';
        if (array_filter($filters)) {
            echo '<a href="?page=cbs-session-report" class="button">Clear</a>';
        }
        echo '<a href="' . esc_url($exportUrl) . '" class="button cbs-export-btn">Export CSV &darr;</a>';
        echo '</form>';

        if (empty($rows)) {
            echo '<p>No sessions found.</p>';
            return;
        }

        // Batch-fetch WC orders so we get totals without N+1 queries.
        $orderIds = array_filter(array_map('intval', array_column($rows, 'wc_order_id')));
        $wcOrders = [];
        foreach ($orderIds as $oid) {
            $o = wc_get_order($oid);
            if ($o) {
                $wcOrders[$oid] = $o;
            }
        }

        echo '<table class="cbs-sr-table">';
        echo '<thead><tr>
            <th>Transaction Ref</th>
            <th>First Seen</th>
            <th>Last Seen</th>
            <th>Site</th>
            <th>Check #</th>
            <th>WC Order #</th>
            <th>Total</th>
            <th>Status</th>
            <th>Actions</th>
        </tr></thead><tbody>';

        foreach ($rows as $row) {
            $ref         = esc_html($row['transaction_ref']);
            $refShort    = esc_html(substr($row['transaction_ref'], 0, 8)) . '&hellip;';
            $firstSeen   = esc_html(wp_date('M j, Y g:i a', strtotime($row['first_seen'])));
            $lastSeen    = esc_html(wp_date('M j, Y g:i a', strtotime($row['last_seen'])));
            $site        = esc_html($row['site_id'] ?: '—');
            $checkId     = !empty($row['check_number']) ? esc_html($row['check_number']) : '—';
            $wcOrderId   = $row['wc_order_id'] ? (int) $row['wc_order_id'] : null;
            $orderLink   = $wcOrderId
                ? '<a href="' . esc_url(get_edit_post_link($wcOrderId)) . '">#' . $wcOrderId . '</a>'
                : '—';

            $order       = $wcOrderId ? ($wcOrders[$wcOrderId] ?? null) : null;
            $totalHtml   = $order ? wp_kses_post( wc_price( $order->get_total() ) ) : '—';

            $overallStatus = $row['has_failure'] ? 'failed' : 'success';
            $statusBadge   = "<span class='cbs-badge cbs-badge-{$overallStatus}'>" . strtoupper($overallStatus) . '</span>';

            $detailUrl = esc_url(admin_url('admin.php?page=cbs-session-report&view=detail&ref=' . urlencode($row['transaction_ref'])));

            echo "<tr>
                <td><code title='{$ref}'>{$refShort}</code></td>
                <td>{$firstSeen}</td>
                <td>{$lastSeen}</td>
                <td>{$site}</td>
                <td>{$checkId}</td>
                <td>{$orderLink}</td>
                <td style='white-space:nowrap'>{$totalHtml}</td>
                <td>{$statusBadge}</td>
                <td><a href='{$detailUrl}' class='button button-small'>Details &rarr;</a></td>
            </tr>";
        }

        echo '</tbody></table>';

        // Pagination
        $totalPages = (int) ceil($total / $perPage);
        if ($totalPages > 1) {
            $baseUrl = admin_url('admin.php?page=cbs-session-report' . self::buildFilterQuery($filters));
            echo '<div style="margin-top:16px">';
            for ($p = 1; $p <= $totalPages; $p++) {
                $active = $p === $page ? 'button-primary' : '';
                echo "<a href='{$baseUrl}&paged={$p}' class='button {$active}' style='margin-right:4px'>{$p}</a>";
            }
            echo '</div>';
        }
    }

    // -------------------------------------------------------------------------
    // Detail view — full event timeline for one session
    // -------------------------------------------------------------------------

    private static function renderDetailView(string $transactionRef): void
    {
        $events  = SessionEventRepository::create()->getEventsForSession($transactionRef);
        $backUrl = esc_url(admin_url('admin.php?page=cbs-session-report'));

        echo '<p><a href="' . $backUrl . '" class="button">&larr; Back to Sessions</a></p>';
        echo '<h2>Session: <code>' . esc_html($transactionRef) . '</code></h2>';

        if (empty($events)) {
            echo '<p>No events found for this session.</p>';
            return;
        }

        // Extract check_id and wc_order_id from events
        $checkId   = null;
        $wcOrderId = null;
        foreach ($events as $event) {
            if (!$wcOrderId && !empty($event['wc_order_id'])) {
                $wcOrderId = (int) $event['wc_order_id'];
            }
            if (!$checkId && !empty($event['details'])) {
                $decoded = json_decode($event['details'], true);
                if (is_array($decoded) && !empty($decoded['check_number'])) {
                    $checkId = $decoded['check_number'];
                }
            }
        }

        $order     = $wcOrderId ? wc_get_order($wcOrderId) : null;
        $totalHtml = $order ? wp_kses_post(wc_price($order->get_total())) : '—';

        echo '<div class="cbs-stat-cards" style="margin-bottom:16px">';
        echo '<div class="cbs-stat-card"><div class="count" style="font-size:18px">' . esc_html($checkId ?: '—') . '</div><div class="label">Check #</div></div>';
        if ($wcOrderId) {
            $orderLink = '<a href="' . esc_url(get_edit_post_link($wcOrderId)) . '" style="font-size:18px;font-weight:700;color:#135e96">#' . $wcOrderId . '</a>';
            echo '<div class="cbs-stat-card"><div class="count" style="font-size:18px">' . $orderLink . '</div><div class="label">WC Order</div></div>';
        }
        echo '<div class="cbs-stat-card"><div class="count" style="font-size:18px">' . $totalHtml . '</div><div class="label">Order Total</div></div>';
        echo '</div>';

        echo '<table class="cbs-sr-table cbs-timeline">';
        echo '<thead><tr>
            <th>Time</th>
            <th>Event</th>
            <th>Status</th>
            <th>WC Order #</th>
            <th>Site</th>
            <th>Details</th>
        </tr></thead><tbody>';

        foreach ($events as $event) {
            $time      = esc_html(wp_date('M j, Y g:i:s a', strtotime($event['created_at'])));
            $eventType = esc_html($event['event_type']);
            $status    = esc_html($event['status']);
            $wcId      = $event['wc_order_id'] ? (int) $event['wc_order_id'] : null;
            $orderLink = $wcId
                ? '<a href="' . esc_url(get_edit_post_link($wcId)) . '">#' . $wcId . '</a>'
                : '—';
            $site      = esc_html($event['site_id'] ?: '—');

            $detailsHtml = '—';
            if (!empty($event['details'])) {
                $decoded = json_decode($event['details'], true);
                if (is_array($decoded) && !empty($decoded)) {
                    $detailsHtml = '<dl class="cbs-detail-dl">';
                    foreach ($decoded as $k => $v) {
                        $detailsHtml .= '<dt>' . esc_html($k) . '</dt><dd>' . esc_html((string) $v) . '</dd>';
                    }
                    $detailsHtml .= '</dl>';
                } else {
                    $detailsHtml = '<code>' . esc_html($event['details']) . '</code>';
                }
            }

            echo "<tr>
                <td style='white-space:nowrap'>{$time}</td>
                <td><span class='cbs-badge cbs-badge-event'>{$eventType}</span></td>
                <td><span class='cbs-badge cbs-badge-{$status}'>" . strtoupper($status) . "</span></td>
                <td>{$orderLink}</td>
                <td>{$site}</td>
                <td>{$detailsHtml}</td>
            </tr>";
        }

        echo '</tbody></table>';
    }

    // -------------------------------------------------------------------------
    // CSV export
    // -------------------------------------------------------------------------

    private static function handleCsvExport(): void
    {
        $filters    = self::getFilters();
        $repo       = SessionEventRepository::create();
        $batchSize  = 1000;
        $offset     = 0;
        $rows       = [];

        do {
            $batch  = $repo->getSessionSummaries($filters, $batchSize, $offset);
            $rows   = array_merge($rows, $batch);
            $offset += $batchSize;
        } while (count($batch) === $batchSize);

        // Batch-fetch WC orders for order totals
        $orderIds = array_filter(array_map('intval', array_column($rows, 'wc_order_id')));
        $wcOrders = [];
        foreach ($orderIds as $oid) {
            $o = wc_get_order($oid);
            if ($o) {
                $wcOrders[$oid] = $o;
            }
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="cbs-session-report-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Transaction Ref', 'First Seen', 'Last Seen', 'WC Order #', 'Order Total', 'Site ID', 'Check #', 'Overall Status']);

        foreach ($rows as $row) {
            $wcOrderId = $row['wc_order_id'] ? (int) $row['wc_order_id'] : null;
            $order     = $wcOrderId ? ($wcOrders[$wcOrderId] ?? null) : null;
            $total     = $order ? $order->get_total() : '';

            fputcsv($out, [
                $row['transaction_ref'],
                $row['first_seen'],
                $row['last_seen'],
                $wcOrderId ?: '',
                $total,
                $row['site_id'] ?: '',
                $row['check_number'] ?: '',
                $row['has_failure'] ? 'failed' : 'success',
            ]);
        }

        fclose($out);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function getFilters(): array
    {
        return [
            'date_from' => sanitize_text_field($_GET['date_from'] ?? ''),
            'date_to'   => sanitize_text_field($_GET['date_to']   ?? ''),
            'status'    => sanitize_key($_GET['status']           ?? ''),
            'site_id'   => sanitize_text_field($_GET['site_id']   ?? ''),
        ];
    }

    private static function buildFilterQuery(array $filters): string
    {
        $parts = [];
        foreach ($filters as $key => $val) {
            if ($val !== '') {
                $parts[] = urlencode($key) . '=' . urlencode($val);
            }
        }
        return $parts ? '&' . implode('&', $parts) : '';
    }
}
