<?php
/**
 * CBSLogger - Centralized logging system for Northstar Online Ordering
 *
 * Channels: orders, payments, api, transactions, webhooks, cart, products, general
 * Each channel writes to its own WooCommerce log file visible at:
 * WooCommerce > Status > Logs  (or wp-content/uploads/wc-logs/cbs-{channel}-*.log)
 *
 * Usage:
 *   CBSLogger::orders()->error('Order failed', ['order_id' => 123]);
 *   CBSLogger::api()->debug('Request sent', ['url' => $url]);
 *   CBSLogger::payments()->warning('Tip missing');
 *   CBSLogger::channel('transactions')->info('Gift card applied', ['amount' => 5.00]);
 */

namespace CBSNorthStar\Logger;

use CBSNorthStar\Helpers\SessionReference;

class CBSLogger {

    const CHANNELS = [
        'orders',
        'payments',
        'api',
        'transactions',
        'webhooks',
        'cart',
        'products',
        'general',
    ];

    const LEVELS_REQUIRING_ELK = ['error', 'critical'];
    const LEVELS_REQUIRING_AI  = ['warning', 'error', 'critical'];

    /** @var string */
    private string $channel;

    /** @var self[] */
    private static array $instances = [];

    private function __construct(string $channel) {
        $this->channel = in_array($channel, self::CHANNELS, true) ? $channel : 'general';
    }

    // -------------------------------------------------------------------------
    // Static channel factory
    // -------------------------------------------------------------------------

    public static function channel(string $channel): self {
        if (!isset(self::$instances[$channel])) {
            self::$instances[$channel] = new self($channel);
        }
        return self::$instances[$channel];
    }

    public static function orders(): self       { return self::channel('orders'); }
    public static function payments(): self     { return self::channel('payments'); }
    public static function api(): self          { return self::channel('api'); }
    public static function transactions(): self { return self::channel('transactions'); }
    public static function webhooks(): self     { return self::channel('webhooks'); }
    public static function cart(): self         { return self::channel('cart'); }
    public static function products(): self     { return self::channel('products'); }
    public static function general(): self      { return self::channel('general'); }

    // -------------------------------------------------------------------------
    // Log level methods
    // -------------------------------------------------------------------------

    public function debug($message, array $context = []): void {
        $this->log('debug', $message, $context);
    }

    public function info($message, array $context = []): void {
        $this->log('info', $message, $context);
    }

    public function warning($message, array $context = []): void {
        $this->log('warning', $message, $context);
    }

    public function error($message, array $context = []): void {
        $this->log('error', $message, $context);
    }

    public function critical($message, array $context = []): void {
        $this->log('critical', $message, $context);
    }

    // -------------------------------------------------------------------------
    // Core log dispatch
    // -------------------------------------------------------------------------

    private function log(string $level, $message, array $context): void {
        $context['transaction_ref'] = SessionReference::get();

        $messageStr = $this->stringify($message);
        $formatted  = $this->format($messageStr, $context);

        // 1. Write to WooCommerce log file for this channel
        $this->writeToWCLogger($level, $formatted, $context);

        // 2. Send to ELK Stack for errors and critical
        if (in_array($level, self::LEVELS_REQUIRING_ELK, true)) {
            $this->dispatchToELK($level, $messageStr, $context);
        }

        // 3. Queue AI analysis (non-blocking) for warning/error/critical
        if (in_array($level, self::LEVELS_REQUIRING_AI, true)) {
            $this->scheduleAIAnalysis($level, $messageStr, $context);
        }
    }

    // -------------------------------------------------------------------------
    // WooCommerce Logger (wc-logs files)
    // -------------------------------------------------------------------------

    private function writeToWCLogger(string $level, string $formatted, array $context): void {
        $wcContext = ['source' => 'cbs-' . $this->channel];

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            switch ($level) {
                case 'debug':    $logger->debug($formatted, $wcContext);    break;
                case 'info':     $logger->info($formatted, $wcContext);     break;
                case 'warning':  $logger->warning($formatted, $wcContext);  break;
                case 'error':    $logger->error($formatted, $wcContext);    break;
                case 'critical': $logger->critical($formatted, $wcContext); break;
                default:         $logger->debug($formatted, $wcContext);    break;
            }
            return;
        }

        // Fallback when WooCommerce is not loaded yet (e.g. webhooks, CLI)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[CBS:%s:%s] %s', strtoupper($this->channel), strtoupper($level), $formatted));
        }
    }

    // -------------------------------------------------------------------------
    // ELK Stack dispatch (fire-and-forget via scheduled event)
    // -------------------------------------------------------------------------

    private function dispatchToELK(string $level, string $message, array $context): void {
        if (!get_option('cbs_elk_enabled', true)) {
            return;
        }

        // Schedule async to avoid blocking the current request
        wp_schedule_single_event(time(), 'cbs_send_to_elk', [[
            'channel' => $this->channel,
            'level'   => $level,
            'message' => $message,
            'context' => $this->scrubContext($context),
            'site_id' => sanitize_text_field($_COOKIE['siteid'] ?? ''),
            'time'    => current_time('mysql'),
        ]]);
    }

    // -------------------------------------------------------------------------
    // AI Analysis scheduling (fire-and-forget)
    // -------------------------------------------------------------------------

    private function scheduleAIAnalysis(string $level, string $message, array $context): void {
        if (!get_option('cbs_ai_insights_enabled', false)) {
            return;
        }

        $errorHash = md5($this->channel . $level . substr($message, 0, 200));

        // Deduplicate: skip if we already have a recent analysis (last 24h)
        global $wpdb;
        $table    = $wpdb->prefix . 'cbs_ai_insights';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE error_hash = %s AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1",
            $errorHash
        ));

        if ($existing) {
            return;
        }

        // Strip PII from context before persisting or scheduling for AI
        $safeContext = $this->scrubContext($context);

        // Insert a placeholder row so duplicate async events don't stack up
        $wpdb->insert($table, [
            'error_hash'       => $errorHash,
            'channel'          => $this->channel,
            'level'            => $level,
            'original_message' => substr($message, 0, 1000),
            'context_data'     => json_encode($safeContext),
            'ai_status'        => 'pending',
            'created_at'       => current_time('mysql'),
        ]);

        $insertedId = $wpdb->insert_id;

        if ($insertedId) {
            wp_schedule_single_event(time(), 'cbs_run_ai_analysis', [[
                'insight_id' => $insertedId,
                'error_hash' => $errorHash,
                'channel'    => $this->channel,
                'level'      => $level,
                'message'    => $message,
                'context'    => $safeContext,
            ]]);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Remove PII-bearing keys from context before the data leaves this server
     * (DB storage, AI API, ELK Stack). Only safe, non-personal operational keys
     * are retained. Add keys here as new safe fields are introduced.
     */
    private function scrubContext(array $context): array {
        static $allowedKeys = [
            'transaction_ref',
            'site_id',
            'run_id',
            'menu_id',
            'post_id',
            'term_id',
            'channel',
            'level',
            'error_code',
            'status_code',
            'http_status',
            'wc_order_id',
            'wc_error_code',
            'payment_method',
            'amount',
            'currency',
        ];

        return array_intersect_key($context, array_flip($allowedKeys));
    }

    private function stringify($message): string {
        if (is_array($message) || is_object($message)) {
            return print_r($message, true);
        }
        return (string) $message;
    }

    private function format(string $message, array $context): string {
        if (empty($context)) {
            return $message;
        }
        $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $message . ' | ' . $contextJson;
    }
}
