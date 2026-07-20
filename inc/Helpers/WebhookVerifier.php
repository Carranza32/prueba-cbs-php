<?php

namespace CBSNorthStar\Helpers;

use CBSNorthStar\Logger\CBSLogger;

/**
 * Verifies the authenticity of inbound CBS NorthStar webhook requests.
 *
 * CBS returns a per-registration `Secret` when a webhook is registered
 * (stored in cbs_webhook_registration.secret). This class uses that secret to
 * authenticate incoming POSTs so an unauthenticated caller cannot trigger a
 * product deploy or stock change by hitting the listener URL directly.
 *
 * Two presentation styles are accepted, so the check works regardless of
 * whether the provider signs the body or sends the shared secret verbatim:
 *
 *   1. HMAC signature  — header value equals hash_hmac(<algo>, <raw body>, <secret>),
 *      compared in both bare-digest and "<algo>=<digest>" forms.
 *   2. Shared secret    — header value equals the registered secret verbatim
 *      (optionally prefixed with "Bearer " / "Token ").
 *
 * Comparisons are timing-safe (hash_equals).
 *
 * ## Enforcement model (fail-closed when a secret exists)
 *
 *   - If one or more secrets are on file for the webhook type, a valid header
 *     is REQUIRED; a missing or mismatched header is rejected.
 *   - If NO secret is on file (e.g. a site that has not re-registered yet),
 *     the request is allowed but a warning is logged — unless strict mode is
 *     forced via the `cbs_webhook_require_signature` filter.
 *
 * ## Tuning filters
 *   - cbs_webhook_signature_headers  array  Candidate $_SERVER header keys.
 *   - cbs_webhook_signature_algo     string hash_hmac algorithm (default sha256).
 *   - cbs_webhook_require_signature  bool   Force rejection when no secret exists.
 */
class WebhookVerifier
{
    /** Default $_SERVER keys checked for a signature/secret, in order. */
    const DEFAULT_SIGNATURE_HEADERS = [
        'HTTP_X_CBS_SIGNATURE',
        'HTTP_X_WEBHOOK_SIGNATURE',
        'HTTP_X_SIGNATURE',
        'HTTP_X_HUB_SIGNATURE_256',
        'HTTP_X_WEBHOOK_SECRET',
    ];

    /**
     * @param string $rawBody     The exact raw request body (php://input).
     * @param string $webhookType Registration type, e.g. 'MenuChanged'.
     * @return bool True when the request is authentic (or allowed under the
     *              no-secret fallback); false when it must be rejected.
     */
    public static function verify(string $rawBody, string $webhookType): bool
    {
        $secrets = self::collectSecrets($webhookType);

        if (empty($secrets)) {
            $strict = (bool) apply_filters('cbs_webhook_require_signature', false, $webhookType);
            if ($strict) {
                CBSLogger::webhooks()->error('Webhook rejected: strict mode on and no secret configured', [
                    'webhookType' => $webhookType,
                ]);
                return false;
            }
            CBSLogger::webhooks()->warning('Webhook not signature-verified: no secret on file for type', [
                'webhookType' => $webhookType,
            ]);
            return true;
        }

        $provided = self::extractProvidedValue();
        if ($provided === null || $provided === '') {
            CBSLogger::webhooks()->error('Webhook rejected: signature header missing', [
                'webhookType'   => $webhookType,
                'headers_seen'  => self::presentHeaderKeys(),
            ]);
            return false;
        }

        $algo = (string) apply_filters('cbs_webhook_signature_algo', 'sha256', $webhookType);

        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }

            // (1) Shared secret presented verbatim.
            if (hash_equals($secret, $provided)) {
                return true;
            }

            // (2) HMAC of the raw body keyed by the secret.
            $computed = hash_hmac($algo, $rawBody, $secret);
            if (hash_equals($computed, $provided) || hash_equals($algo . '=' . $computed, $provided)) {
                return true;
            }
        }

        CBSLogger::webhooks()->error('Webhook rejected: signature mismatch', [
            'webhookType'  => $webhookType,
            'headers_seen' => self::presentHeaderKeys(),
        ]);
        return false;
    }

    /**
     * Pull the first present signature/secret header value, stripping any
     * "Bearer "/"Token " prefix.
     */
    private static function extractProvidedValue(): ?string
    {
        $headers = (array) apply_filters('cbs_webhook_signature_headers', self::DEFAULT_SIGNATURE_HEADERS);

        foreach ($headers as $key) {
            if (!empty($_SERVER[$key])) {
                $value = trim((string) $_SERVER[$key]);
                return preg_replace('/^(Bearer|Token)\s+/i', '', $value);
            }
        }

        return null;
    }

    /**
     * Load all configured secrets for a webhook type from the registration
     * table. Multiple sites can register the same type, so every secret is a
     * valid candidate.
     *
     * @return string[]
     */
    private static function collectSecrets(string $webhookType): array
    {
        global $wpdb;

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT secret FROM cbs_webhook_registration WHERE webhooktype = %s",
                $webhookType
            )
        );

        $secrets = [];
        if (is_array($rows)) {
            foreach ($rows as $secret) {
                if (is_string($secret) && $secret !== '') {
                    $secrets[] = $secret;
                }
            }
        }

        return array_values(array_unique($secrets));
    }

    /**
     * Names (not values) of candidate headers actually present on the request —
     * logged on rejection so operators can adjust the header filter if CBS uses
     * a different header than the defaults.
     *
     * @return string[]
     */
    private static function presentHeaderKeys(): array
    {
        $headers = (array) apply_filters('cbs_webhook_signature_headers', self::DEFAULT_SIGNATURE_HEADERS);
        return array_values(array_filter($headers, static fn($k) => !empty($_SERVER[$k])));
    }
}
