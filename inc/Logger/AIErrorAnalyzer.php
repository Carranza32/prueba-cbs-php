<?php
/**
 * AIErrorAnalyzer - Explains plugin errors in human-friendly language.
 *
 * Uses Groq API (free tier) with llama-3.1-8b-instant.
 * Get a free API key at https://console.groq.com
 *
 * Configure under:  WooCommerce > CBS Log Insights > Settings
 */

namespace CBSNorthStar\Logger;

class AIErrorAnalyzer {

    /** Free Groq model — fast and no cost on free tier */
    const MODEL   = 'llama-3.1-8b-instant';
    const API_URL = 'https://api.groq.com/openai/v1/chat/completions';

    const SYSTEM_PROMPT = <<<PROMPT
You are a helpful assistant for restaurant managers using an online ordering system.
Your job is to explain technical plugin errors in plain, friendly language — no code jargon.
Keep explanations short (2-3 sentences max). Always suggest a simple next step.
PROMPT;

    /**
     * Analyze an error and return human-friendly explanation + suggestion.
     *
     * @param int    $insightId DB row to update
     * @param string $channel   e.g. orders, payments, api
     * @param string $level     e.g. warning, error, critical
     * @param string $message   Raw error message
     * @param array  $context   Additional context data
     *
     * @return bool  True if analysis succeeded
     */
    public function analyze(int $insightId, string $channel, string $level, string $message, array $context): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'cbs_ai_insights';

        $apiKey = defined('WP_CBS_GROQ_API_KEY') ? WP_CBS_GROQ_API_KEY : get_option('cbs_groq_api_key', '');
        if (empty($apiKey)) {
            $wpdb->update($table, ['ai_status' => 'skipped_no_key'], ['id' => $insightId]);
            return false;
        }

        $prompt   = $this->buildPrompt($channel, $level, $message, $context);
        $response = $this->callGroq($apiKey, $prompt);

        if ($response === null) {
            $wpdb->update($table, ['ai_status' => 'failed'], ['id' => $insightId]);
            return false;
        }

        [$explanation, $suggestion] = $this->parseResponse($response);

        $wpdb->update($table, [
            'ai_explanation' => sanitize_textarea_field($explanation),
            'ai_suggestion'  => sanitize_textarea_field($suggestion),
            'ai_status'      => 'done',
            'analyzed_at'    => current_time('mysql'),
        ], ['id' => $insightId]);

        return true;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function callGroq(string $apiKey, string $prompt): ?string {
        $body = json_encode([
            'model'       => self::MODEL,
            'messages'    => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user',   'content' => $prompt],
            ],
            'max_tokens'  => 300,
            'temperature' => 0.3,
        ]);

        $response = wp_remote_post(self::API_URL, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            error_log(sprintf(
                '[AIErrorAnalyzer] API request failed. URL: %s | Status: %s | Body: %s',
                self::API_URL,
                $statusCode,
                wp_remote_retrieve_body($response)
            ));
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['choices'][0]['message']['content'] ?? null;
    }

    private function buildPrompt(string $channel, string $level, string $message, array $context): string {
        $channelLabel  = ucfirst($channel);
        $levelLabel    = strtoupper($level);
        $safeContext   = $this->sanitizeContext($context);
        $contextStr    = !empty($safeContext) ? json_encode($safeContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'none';

        // Truncate large messages so we stay within token limits
        $shortMessage = mb_substr($message, 0, 600);

        return <<<PROMPT
A {$levelLabel} occurred in the {$channelLabel} module of a restaurant online ordering plugin.

Error: {$shortMessage}
Extra info: {$contextStr}

Please respond in this exact format (two lines only):
EXPLANATION: [plain English explanation for a restaurant manager]
SUGGESTION: [one actionable step to fix or investigate]
PROMPT;
    }

    /**
     * Strip PII-bearing keys before the context is sent to the external Groq API.
     * Only safe, non-personal operational keys are forwarded.
     */
    private function sanitizeContext(array $context): array {
        static $allowedKeys = [
            'channel',
            'level',
            'transaction_ref',
            'site_id',
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

    private function parseResponse(string $raw): array {
        $explanation = '';
        $suggestion  = '';

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if (stripos($line, 'EXPLANATION:') === 0) {
                $explanation = trim(substr($line, strlen('EXPLANATION:')));
            } elseif (stripos($line, 'SUGGESTION:') === 0) {
                $suggestion = trim(substr($line, strlen('SUGGESTION:')));
            }
        }

        // Fallback: if model didn't follow format, use the whole response as explanation
        if (empty($explanation)) {
            $explanation = trim($raw);
        }

        return [$explanation, $suggestion];
    }
}
