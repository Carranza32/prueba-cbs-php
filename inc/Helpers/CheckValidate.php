<?php

namespace CBSNorthStar\Helpers;

/**
 * Call policy for the WOAPI /checks/validate/ endpoint (OE-26669).
 *
 * cbs_custom_tax_surcharge() owns the single authoritative validate call per
 * fee pass; this class holds the pure/request-scoped logic around it so it is
 * unit-testable without loading inc/woocommerce_hooks.php (whose require
 * chain pulls in wp-load.php via Woapi/Connection.php):
 *
 *  - cacheKey():            the cbsValidateSnapshot key recipe, shared by the
 *                           fee pass and the review-order gate.
 *  - couponCodeToStrip():   whether a failed response warrants stripping one
 *                           coupon and retrying once in the same pass.
 *  - stripCouponCode():     the surgical cookie strip + "Invalid Coupon
 *                           applied." notice (mirrors the removed
 *                           cbsValidateCoupons() pre-pass).
 *  - shouldSkipReviewValidate(): the review-order snapshot gate.
 */
class CheckValidate
{
    /**
     * Compute the cbsValidateSnapshot cache key for the current request context.
     *
     * Single source of truth for the snapshot key recipe: used by
     * cbs_custom_tax_surcharge() when reading/writing the snapshot and by
     * action_woocommerce_review_order_before_submit() to decide whether the
     * cart state was already validated this request. Every request input that
     * can change the /checks/validate/ result MUST be part of this key, so a
     * quantity change, site switch, order-type change, slot change, coupon
     * update, or reward swap always busts the cache and forces a fresh call.
     *
     * All inputs are re-derived from the request (cookies/session) exactly as
     * cbs_custom_tax_surcharge() derives them for its payload, so both callers
     * agree on the key without threading six arguments around.
     */
    public static function cacheKey(\WC_Cart $cart): string
    {
        $siteid = !empty($_COOKIE['siteid'])
            ? sanitize_text_field($_COOKIE['siteid'])
            : (isset($_GET['site_id']) ? sanitize_text_field($_GET['site_id']) : '');

        $tableNum        = isset($_COOKIE['table_num']) ? htmlspecialchars($_COOKIE['table_num'], ENT_QUOTES, 'UTF-8') : '';
        $payLaterControl = isset($_COOKIE['pay_later_control']) ? htmlspecialchars($_COOKIE['pay_later_control'], ENT_QUOTES, 'UTF-8') : '';
        if (!empty($tableNum) && $payLaterControl === 'Enabled') {
            $orderType = 0;
        }
        else {
            $orderType = isset($_COOKIE['orderType']) ? (int) $_COOKIE['orderType'] : 1;
        }

        $deliveryContext = 'immediate';
        [$overrideTime] = oloNavSlotOverrides();
        if ($overrideTime) {
            $slotDate        = isset($_COOKIE['oloNavTimeslotDate']) ? sanitize_text_field($_COOKIE['oloNavTimeslotDate']) : current_time('Y-m-d');
            $deliveryContext = $slotDate . ' ' . $overrideTime;
        }

        $couponKeyParts = !empty($_COOKIE['olo_coupon_codes'])
            ? explode(',', sanitize_text_field(wp_unslash($_COOKIE['olo_coupon_codes'])))
            : [];
        sort($couponKeyParts);

        // Key on the full redeemed-reward identity (programId + per-reward uniqueKeys), not just the
        // programIds. Swapping a reward within the same program changes the uniqueKey but not the
        // programId set, so a programId-only hash collides and serves a stale Rewards fee / RewardsData
        // breakdown (OE-26382). Sorting keeps the hash order-independent so a re-render with the same
        // rewards still hits the cache.
        $loyaltyData = WC()->session ? ( WC()->session->get( 'loyaltyData', [] ) ?? [] ) : [];
        $loyaltyHash = '';
        if ( ! empty( $loyaltyData['programs'] ) ) {
            $loyaltySignature = [];
            foreach ( $loyaltyData['programs'] as $programId => $enrollments ) {
                $rewardKeys = is_array( $enrollments ) ? array_keys( $enrollments ) : [];
                sort( $rewardKeys );
                $loyaltySignature[ (string) $programId ] = $rewardKeys;
            }
            ksort( $loyaltySignature );
            $loyaltyHash = md5( json_encode( $loyaltySignature ) );
        }

        return implode('|', [
            $cart->get_cart_hash(),
            $siteid,
            (string) $orderType,
            $deliveryContext,
            implode(',', $couponKeyParts),
            $loyaltyHash,
        ]);
    }

    /**
     * Extract the coupon code from an error_applying_coupon_<code> message.
     */
    public static function invalidCouponCode(string $error): ?string
    {
        if (preg_match('/error_applying_coupon_([^ :]+)/', $error, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Decide whether a failed /checks/validate/ response warrants stripping a
     * coupon code and retrying once in the same fee pass.
     *
     * Returns the coupon code to strip, or null when the normal failure
     * handling should run instead: response missing/ok, error not
     * coupon-shaped, the code not actually applied (a retry with an unchanged
     * list would fail identically), or the single retry already spent.
     *
     * @param mixed $response Decoded WOAPI response (object|false|null).
     */
    public static function couponCodeToStrip($response, array $coupons, bool $alreadyRetried): ?string
    {
        if ($alreadyRetried || empty($coupons)) {
            return null;
        }
        if (!$response || !is_object($response) || !empty($response->Ok)) {
            return null;
        }
        if (empty($response->ErrorMessage)) {
            return null;
        }

        $invalidCode = self::invalidCouponCode((string) $response->ErrorMessage);
        if ($invalidCode === null || !in_array($invalidCode, $coupons, true)) {
            return null;
        }

        return $invalidCode;
    }

    /**
     * Reject one applied coupon: surgically remove its code from the
     * olo_coupon_codes cookie (keeping the other applied codes), queue the
     * "Invalid Coupon applied." notice, and return the survivors. Mirrors the
     * strip+notice behavior of the removed cbsValidateCoupons() pre-pass: the
     * cookie is the source of truth for applied coupons, and $_COOKIE is
     * updated so later reads in this same request (payload rebuild,
     * cacheKey()) see the reduced list.
     */
    public static function stripCouponCode(string $invalidCode, array $coupons): array
    {
        $coupons = array_values(array_filter($coupons, function ($code) use ($invalidCode) {
            return $code !== $invalidCode;
        }));

        if (!empty($coupons)) {
            setcookie('olo_coupon_codes', implode(',', $coupons), 0, COOKIEPATH, COOKIE_DOMAIN);
            $_COOKIE['olo_coupon_codes'] = implode(',', $coupons);
        }
        else {
            setcookie('olo_coupon_codes', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
            unset($_COOKIE['olo_coupon_codes']);
        }

        // Exact text required by the dismiss-button matcher in olo_coupons.js
        // (see duplicate-coupon-notification spec, requirement 7).
        wc_add_notice('Invalid Coupon applied.', 'error');

        return $coupons;
    }

    /**
     * Decide whether the review-order render can skip its /checks/validate/
     * call because cbs_custom_tax_surcharge() already validated the cart
     * earlier in this same request.
     *
     * The signal is request-scoped ($validatedThisRequest, set by the fee
     * pass on a successful validation or snapshot cache hit) rather than a
     * snapshot cache-key comparison: WC_Cart::get_cart_hash() folds in
     * get_total('edit'), which is an intermediate value during the fee hook
     * but the final value at review render, so a key recomputed at render
     * time can NEVER match the one written at fee time. calculate_totals()
     * always runs before the review template renders, so the request-scoped
     * flag is exactly the "already validated" fact the gate needs.
     *
     * Pay-later / order-at-table is exempt: its table/area check
     * (no_locations_available_for_area) is not covered by any other call, so
     * that mode always performs the call. In standard mode the gate fails
     * open — no successful validation this request, or a failed one, keeps
     * the call so the existing error-overlay behavior is preserved for
     * genuinely stale carts.
     */
    public static function shouldSkipReviewValidate(bool $payLaterActive, bool $validatedThisRequest, bool $validationFailed): bool
    {
        if ($payLaterActive || $validationFailed) {
            return false;
        }

        return $validatedThisRequest;
    }
}
