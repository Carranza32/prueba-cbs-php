<?php

namespace CBSNorthStar\Helpers;

/**
 * SessionReference - Manages a numeric-only transaction reference per browser session.
 *
 * The reference is stored as a session cookie (no expiry = cleared when browser closes)
 * and is used to correlate all WOAPI requests and log entries for a single user session.
 *
 * Format: 10-digit Unix timestamp + 6 random digits = 16-digit numeric string.
 * Example: 1742287200483921
 */
class SessionReference
{
    const COOKIE_NAME = 'cbs_transaction_ref';

    /**
     * Returns the current session's transaction reference number.
     * Generates and sets the cookie on first call if not already present.
     */
    public static function get(): string
    {
        if (!empty($_COOKIE[self::COOKIE_NAME]) && preg_match('/^\d{16}$/', $_COOKIE[self::COOKIE_NAME])) {
            return $_COOKIE[self::COOKIE_NAME];
        }

        $ref = self::generate();

        // Session cookie: expires = 0 means it clears when the browser closes.
        // httponly = false so JavaScript can also read it if needed.
        if (!headers_sent()) {
            setcookie(self::COOKIE_NAME, $ref, 0, '/', null, is_ssl(), false);
        }

        // Make available for the current request immediately
        $_COOKIE[self::COOKIE_NAME] = $ref;

        return $ref;
    }

    /**
     * Initializes the session reference early in the request lifecycle.
     * Hook this to 'init' with a low priority number (e.g. 1) so the cookie
     * is written before any output can be sent.
     */
    public static function init(): void
    {
        self::get();
    }

    /**
     * Generates a unique 16-digit numeric reference.
     * Format: Unix timestamp (10 digits) + zero-padded random 6 digits.
     */
    private static function generate(): string
    {
        return (string) time() . str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
