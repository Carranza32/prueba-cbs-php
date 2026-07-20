<?php
/**
 * CBS Log Insights Admin Page
 *
 * Registers a WooCommerce sub-menu page that shows AI-powered
 * human-friendly explanations of logged errors.
 *
 * Menu path:  WooCommerce > CBS Log Insights
 */

namespace CBSNorthStar\Admin;

use CBSNorthStar\Logger\CBSLogger;

class LogViewerPage {

    public static function register(): void {
        add_action('admin_menu', [self::class, 'addMenuPage']);
        add_action('admin_init', [self::class, 'handleSettingsSave']);
        add_action('wp_ajax_cbs_dismiss_insight', [self::class, 'ajaxDismissInsight']);
        add_action('wp_ajax_cbs_reanalyze_insight', [self::class, 'ajaxReanalyzeInsight']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    public static function addMenuPage(): void {
        add_submenu_page(
            'woocommerce',
            'CBS Log Insights',
            'CBS Log Insights',
            'manage_woocommerce',
            'cbs-log-insights',
            [self::class, 'renderPage']
        );
    }

    public static function enqueueAssets(string $hook): void {
        if ($hook !== 'woocommerce_page_cbs-log-insights') {
            return;
        }
        wp_enqueue_style('cbs-log-insights', plugins_url('assets/css/log-insights.css', CBS_PLUGIN_FILE), [], '1.0.0');
    }

    // -------------------------------------------------------------------------
    // Main page render
    // -------------------------------------------------------------------------

    public static function renderPage(): void {
        $tab = sanitize_key($_GET['tab'] ?? 'insights');
        ?>
        <div class="wrap cbs-log-insights">
            <h1>CBS Log Insights</h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=cbs-log-insights&tab=insights"
                   class="nav-tab <?php echo $tab === 'insights' ? 'nav-tab-active' : ''; ?>">
                   AI Insights
                </a>
                <a href="?page=cbs-log-insights&tab=settings"
                   class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                   Settings
                </a>
                <a href="?page=cbs-log-insights&tab=wc-logs"
                   class="nav-tab <?php echo $tab === 'wc-logs' ? 'nav-tab-active' : ''; ?>">
                   Log Files
                </a>
            </nav>

            <div class="cbs-tab-content">
                <?php
                switch ($tab) {
                    case 'settings': self::renderSettingsTab(); break;
                    case 'wc-logs':  self::renderLogFilesTab(); break;
                    default:         self::renderInsightsTab(); break;
                }
                ?>
            </div>
        </div>

        <style>
        .cbs-log-insights { max-width: 1200px; }
        .cbs-tab-content { background: #fff; border: 1px solid #ccd0d4; border-top: none; padding: 20px; }
        .cbs-insights-table { width: 100%; border-collapse: collapse; }
        .cbs-insights-table th { background: #f1f1f1; padding: 10px 12px; text-align: left; border-bottom: 2px solid #ddd; }
        .cbs-insights-table td { padding: 10px 12px; border-bottom: 1px solid #eee; vertical-align: top; }
        .cbs-insights-table tr:hover td { background: #fafafa; }
        .cbs-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .cbs-badge-error    { background: #f8d7da; color: #721c24; }
        .cbs-badge-critical { background: #f5c6cb; color: #491217; }
        .cbs-badge-warning  { background: #fff3cd; color: #856404; }
        .cbs-badge-info     { background: #d1ecf1; color: #0c5460; }
        .cbs-badge-debug    { background: #e2e3e5; color: #383d41; }
        .cbs-channel-badge  { background: #d4edda; color: #155724; }
        .cbs-explanation    { color: #23282d; }
        .cbs-suggestion     { color: #555; font-style: italic; margin-top: 4px; }
        .cbs-pending        { color: #999; font-style: italic; }
        .cbs-filters        { margin: 16px 0; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .cbs-filters label  { font-weight: 600; }
        .cbs-stat-cards     { display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
        .cbs-stat-card      { background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; padding: 12px 20px; text-align: center; min-width: 120px; }
        .cbs-stat-card .count { font-size: 28px; font-weight: 700; color: #135e96; }
        .cbs-stat-card .label { font-size: 12px; color: #666; }
        .cbs-no-key-notice  { background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px 16px; margin-bottom: 16px; }
        .cbs-log-files-list a { display: block; padding: 6px 0; color: #0073aa; }
        .cbs-settings-form table { max-width: 600px; }
        </style>
        <?php
    }

    // -------------------------------------------------------------------------
    // Insights tab
    // -------------------------------------------------------------------------

    private static function renderInsightsTab(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cbs_ai_insights';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            echo '<p>Log insights table not found. Please deactivate and reactivate the plugin.</p>';
            return;
        }

        $aiEnabled = get_option('cbs_ai_insights_enabled', false);
        $apiKey    = defined('WP_CBS_GROQ_API_KEY') ? (string) constant('WP_CBS_GROQ_API_KEY') : get_option('cbs_groq_api_key', '');

        if (!$aiEnabled || empty($apiKey)) {
            echo '<div class="cbs-no-key-notice">';
            echo '<strong>AI Insights disabled.</strong> ';
            echo 'To see human-friendly error explanations, ';
            echo '<a href="?page=cbs-log-insights&tab=settings">configure your free Groq API key</a> and enable AI Insights.';
            echo '</div>';
        }

        // Filters
        $filterChannel = sanitize_key($_GET['channel'] ?? '');
        $filterLevel   = sanitize_key($_GET['level'] ?? '');
        $perPage       = 50;
        $currentPage   = max(1, (int) ($_GET['paged'] ?? 1));
        $offset        = ($currentPage - 1) * $perPage;

        // Stats
        $stats = $wpdb->get_results("
            SELECT level, COUNT(*) as cnt
            FROM {$table}
            GROUP BY level
        ", ARRAY_A);
        $statsByLevel = [];
        foreach ($stats as $row) {
            $statsByLevel[$row['level']] = $row['cnt'];
        }

        // Query
        $where  = [];
        $values = [];
        if ($filterChannel) {
            $where[]  = 'channel = %s';
            $values[] = $filterChannel;
        }
        if ($filterLevel) {
            $where[]  = 'level = %s';
            $values[] = $filterLevel;
        }
        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $totalSQL = "SELECT COUNT(*) FROM {$table} {$whereSQL}";
        $total    = $values ? (int) $wpdb->get_var($wpdb->prepare($totalSQL, $values)) : (int) $wpdb->get_var($totalSQL);

        $querySQL = "SELECT * FROM {$table} {$whereSQL} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $allValues = array_merge($values, [$perPage, $offset]);
        $rows = $values
            ? $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} {$whereSQL} ORDER BY created_at DESC LIMIT %d OFFSET %d", array_merge($values, [$perPage, $offset])))
            : $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d", [$perPage, $offset]));

        // Stats cards
        echo '<div class="cbs-stat-cards">';
        foreach (['error' => 'Errors', 'critical' => 'Critical', 'warning' => 'Warnings'] as $lvl => $label) {
            $cnt = (int) ($statsByLevel[$lvl] ?? 0);
            echo "<div class='cbs-stat-card'><div class='count'>" . $cnt . "</div><div class='label'>" . esc_html($label) . "</div></div>";
        }
        $total_all = (int) array_sum(array_column($stats, 'cnt'));
        echo "<div class='cbs-stat-card'><div class='count'>" . $total_all . "</div><div class='label'>Total Logged</div></div>";
        echo '</div>';

        // Filters
        $channels = $wpdb->get_col("SELECT DISTINCT channel FROM {$table} ORDER BY channel");
        $levels   = $wpdb->get_col("SELECT DISTINCT level FROM {$table} ORDER BY level");

        echo '<form method="get" class="cbs-filters">';
        echo '<input type="hidden" name="page" value="cbs-log-insights">';
        echo '<input type="hidden" name="tab"  value="insights">';

        echo '<label>Channel: <select name="channel"><option value="">All</option>';
        foreach ($channels as $ch) {
            $sel = $filterChannel === $ch ? 'selected' : '';
            echo "<option value='" . esc_attr($ch) . "' {$sel}>" . esc_html(ucfirst($ch)) . "</option>";
        }
        echo '</select></label>';

        echo '<label>Level: <select name="level"><option value="">All</option>';
        foreach ($levels as $lv) {
            $sel = $filterLevel === $lv ? 'selected' : '';
            echo "<option value='" . esc_attr($lv) . "' {$sel}>" . esc_html(strtoupper($lv)) . "</option>";
        }
        echo '</select></label>';

        echo '<button type="submit" class="button">Filter</button>';
        if ($filterChannel || $filterLevel) {
            echo '<a href="?page=cbs-log-insights&tab=insights" class="button">Clear</a>';
        }
        echo '</form>';

        if (empty($rows)) {
            echo '<p>No log entries found.</p>';
            return;
        }

        echo '<table class="cbs-insights-table">';
        echo '<thead><tr>
            <th>Date</th>
            <th>Channel</th>
            <th>Level</th>
            <th>Error Message</th>
            <th>AI Explanation</th>
            <th>Suggested Action</th>
        </tr></thead><tbody>';

        foreach ($rows as $row) {
            $date        = esc_html(wp_date('M j, Y g:i a', strtotime($row->created_at)));
            $channel     = esc_html(ucfirst($row->channel));
            $level       = esc_html(strtoupper($row->level));
            $message     = esc_html(mb_substr($row->original_message, 0, 200));
            $explanation = '';
            $suggestion  = '';
            $levelClass  = esc_attr($row->level);

            if ($row->ai_status === 'done' && !empty($row->ai_explanation)) {
                $explanation = '<span class="cbs-explanation">' . esc_html($row->ai_explanation) . '</span>';
                $suggestion  = '<span class="cbs-suggestion">' . esc_html($row->ai_suggestion) . '</span>';
            } elseif ($row->ai_status === 'pending') {
                $explanation = '<span class="cbs-pending">Analyzing...</span>';
            } elseif ($row->ai_status === 'skipped_no_key') {
                $explanation = '<span class="cbs-pending">No API key configured</span>';
            } elseif (!$aiEnabled) {
                $explanation = '<span class="cbs-pending">AI disabled</span>';
            } else {
                $explanation = '<span class="cbs-pending">—</span>';
            }

            echo "<tr>
                <td>{$date}</td>
                <td><span class='cbs-badge cbs-channel-badge'>{$channel}</span></td>
                <td><span class='cbs-badge cbs-badge-{$levelClass}'>{$level}</span></td>
                <td><code style='font-size:11px;white-space:pre-wrap;word-break:break-all'>{$message}</code></td>
                <td>{$explanation}</td>
                <td>{$suggestion}</td>
            </tr>";
        }

        echo '</tbody></table>';

        // Pagination
        $totalPages = (int) ceil($total / $perPage);
        if ($totalPages > 1) {
            $baseUrl = admin_url('admin.php?page=cbs-log-insights&tab=insights');
            if ($filterChannel) $baseUrl .= '&channel=' . $filterChannel;
            if ($filterLevel)   $baseUrl .= '&level=' . $filterLevel;

            echo '<div style="margin-top:16px">';
            for ($p = 1; $p <= $totalPages; $p++) {
                $active = $p === $currentPage ? 'button-primary' : '';
                echo "<a href='{$baseUrl}&paged={$p}' class='button {$active}' style='margin-right:4px'>{$p}</a>";
            }
            echo '</div>';
        }
    }

    // -------------------------------------------------------------------------
    // Settings tab
    // -------------------------------------------------------------------------

    private static function renderSettingsTab(): void {
        $aiEnabled      = get_option('cbs_ai_insights_enabled', false);
        $apiKeyConstant = defined('WP_CBS_GROQ_API_KEY');
        $apiKey         = $apiKeyConstant ? (string) constant('WP_CBS_GROQ_API_KEY') : get_option('cbs_groq_api_key', '');
        $elkEnabled     = get_option('cbs_elk_enabled', true);
        $elkUrl         = get_option('cbs_elk_url', 'https://logs.cbsnorthstar.com/log');

        if (isset($_GET['settings-saved'])) {
            echo '<div class="notice notice-success inline"><p>Settings saved.</p></div>';
        }
        ?>
        <form method="post" class="cbs-settings-form">
            <?php wp_nonce_field('cbs_log_settings', 'cbs_log_nonce'); ?>

            <h2>AI Insights (Groq)</h2>
            <p>Uses the free <a href="https://console.groq.com" target="_blank">Groq API</a> to explain errors in plain English.
               Model: <strong>llama-3.1-8b-instant</strong> (free tier, no billing required).</p>

            <table class="form-table">
                <tr>
                    <th><label for="cbs_ai_insights_enabled">Enable AI Insights</label></th>
                    <td>
                        <input type="checkbox" id="cbs_ai_insights_enabled" name="cbs_ai_insights_enabled"
                               value="1" <?php checked($aiEnabled, true); ?>>
                        <p class="description">When enabled, warnings/errors are automatically explained in plain language.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cbs_groq_api_key">Groq API Key</label></th>
                    <td>
                        <input type="password" id="cbs_groq_api_key" name="cbs_groq_api_key"
                               value="<?php echo $apiKeyConstant ? '' : esc_attr($apiKey); ?>"
                               class="regular-text" autocomplete="new-password"
                               <?php echo $apiKeyConstant ? 'disabled placeholder="Set via WP_CBS_GROQ_API_KEY constant"' : ''; ?>>
                        <?php if ($apiKeyConstant): ?>
                            <p class="description">Key is provided by the <code>WP_CBS_GROQ_API_KEY</code> constant and cannot be changed here.</p>
                        <?php else: ?>
                            <p class="description">
                                Get a free key at <a href="https://console.groq.com" target="_blank">console.groq.com</a>.
                                Free tier: 14,400 requests/day. Leave blank to remove the stored key.
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <h2>ELK Stack</h2>
            <table class="form-table">
                <tr>
                    <th><label for="cbs_elk_enabled">Enable ELK Logging</label></th>
                    <td>
                        <input type="checkbox" id="cbs_elk_enabled" name="cbs_elk_enabled"
                               value="1" <?php checked($elkEnabled, true); ?>>
                        <p class="description">Send error/critical logs to the ELK Stack endpoint.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cbs_elk_url">ELK Endpoint URL</label></th>
                    <td>
                        <input type="url" id="cbs_elk_url" name="cbs_elk_url"
                               value="<?php echo esc_attr($elkUrl); ?>" class="regular-text">
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="cbs_save_log_settings" class="button button-primary">Save Settings</button>
            </p>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // Log Files tab — links to WC log viewer for each CBS channel
    // -------------------------------------------------------------------------

    private static function renderLogFilesTab(): void {
        $channels = CBSLogger::CHANNELS;
        $logsUrl  = admin_url('admin.php?page=wc-status&tab=logs');

        echo '<h2>CBS Log Files</h2>';
        echo '<p>Each channel writes to its own file in <code>wp-content/uploads/wc-logs/</code>. ';
        echo 'View them in the <a href="' . esc_url($logsUrl) . '">WooCommerce Logs viewer</a>.</p>';

        echo '<div class="cbs-log-files-list">';
        foreach ($channels as $channel) {
            $source  = 'cbs-' . $channel;
            $viewUrl = esc_url($logsUrl . '&source=' . $source);
            $label   = ucfirst($channel);
            echo "<div style='display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid #eee'>";
            echo "<span class='cbs-badge cbs-channel-badge'>{$label}</span>";
            echo "<code>cbs-{$channel}-{date}-{hash}.log</code>";
            echo "<a href='{$viewUrl}' class='button button-small'>View in WC Logs &rarr;</a>";
            echo "</div>";
        }
        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // Settings save handler
    // -------------------------------------------------------------------------

    public static function handleSettingsSave(): void {
        if (!isset($_POST['cbs_save_log_settings'])) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        check_admin_referer('cbs_log_settings', 'cbs_log_nonce');

        update_option('cbs_ai_insights_enabled', !empty($_POST['cbs_ai_insights_enabled']));
        update_option('cbs_elk_enabled',         !empty($_POST['cbs_elk_enabled']));

        // Always persist (including empty) so admins can clear stored values.
        // Skip Groq key if it's provided via constant — the field is disabled in the UI.
        if (!defined('WP_CBS_GROQ_API_KEY')) {
            update_option('cbs_groq_api_key', sanitize_text_field($_POST['cbs_groq_api_key'] ?? ''));
        }

        update_option('cbs_elk_url', esc_url_raw($_POST['cbs_elk_url'] ?? ''));

        wp_safe_redirect(admin_url('admin.php?page=cbs-log-insights&tab=settings&settings-saved=1'));
        exit;
    }

    // -------------------------------------------------------------------------
    // AJAX: dismiss insight
    // -------------------------------------------------------------------------

    public static function ajaxDismissInsight(): void {
        check_ajax_referer('cbs_log_insights_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized', 403);
        }

        global $wpdb;
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $wpdb->delete($wpdb->prefix . 'cbs_ai_insights', ['id' => $id]);
        }
        wp_send_json_success();
    }

    // -------------------------------------------------------------------------
    // AJAX: re-analyze insight
    // -------------------------------------------------------------------------

    public static function ajaxReanalyzeInsight(): void {
        check_ajax_referer('cbs_log_insights_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized', 403);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cbs_ai_insights';
        $id    = (int) ($_POST['id'] ?? 0);

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
        if (!$row) {
            wp_send_json_error('Not found', 404);
        }

        $wpdb->update($table, ['ai_status' => 'pending'], ['id' => $id]);

        wp_schedule_single_event(time(), 'cbs_run_ai_analysis', [[
            'insight_id' => $id,
            'error_hash' => $row->error_hash,
            'channel'    => $row->channel,
            'level'      => $row->level,
            'message'    => $row->original_message,
            'context'    => json_decode($row->context_data ?? '{}', true),
        ]]);

        wp_send_json_success(['message' => 'Re-analysis queued.']);
    }
}
