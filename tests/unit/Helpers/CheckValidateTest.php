<?php

namespace {
    // Minimal WC_Cart stand-in: WooCommerce is not loaded in the unit suite and
    // CheckValidate::cacheKey() only needs get_cart_hash(). Guarded so a
    // WC-loaded context uses the real class.
    if ( ! class_exists( 'WC_Cart' ) ) {
        class WC_Cart {
            public string $testHash = 'carthash';
            public function get_cart_hash(): string {
                return $this->testHash;
            }
        }
    }
}

namespace CBSNorthStar\Tests\Helpers {

    use Brain\Monkey\Functions;
    use CBSNorthStar\Helpers\CheckValidate;
    use CBSNorthStar\Tests\TestCase;

    /**
     * Unit tests for CheckValidate (OE-26669) — the /checks/validate/ call
     * policy extracted from inc/woocommerce_hooks.php:
     *
     *  - cacheKey(): the shared cbsValidateSnapshot key recipe (coupon
     *    order-independence, loyalty reward identity, immediate vs slot
     *    delivery context).
     *  - couponCodeToStrip(): the strip-and-retry decision that replaced the
     *    cbsValidateCoupons() P9 pre-pass.
     *  - stripCouponCode(): surgical cookie strip + exact "Invalid Coupon
     *    applied." notice (dismiss-matcher contract,
     *    duplicate-coupon-notification req 7).
     *  - shouldSkipReviewValidate(): the review-order snapshot gate with its
     *    pay-later exemption and fail-open default.
     *
     * WordPress/WooCommerce is faked with Brain\Monkey; cookies are seeded via
     * $_COOKIE directly and restored in tearDown.
     */
    final class CheckValidateTest extends TestCase
    {
        private \stdClass $wc;
        private array $cookieBackup;

        protected function setUp(): void
        {
            parent::setUp();

            $this->cookieBackup = $_COOKIE;

            $this->wc          = new \stdClass();
            $this->wc->session = null;
            $wc                = $this->wc;
            Functions\when('WC')->alias(function () use ($wc) {
                return $wc;
            });

            // No timeslot override unless a test says otherwise.
            Functions\when('oloNavSlotOverrides')->justReturn([null, null]);
            Functions\when('sanitize_text_field')->returnArg();
            Functions\when('wp_unslash')->returnArg();
            Functions\when('setcookie')->justReturn(true);

            if (!defined('COOKIEPATH')) {
                define('COOKIEPATH', '/');
            }
            if (!defined('COOKIE_DOMAIN')) {
                define('COOKIE_DOMAIN', '');
            }
        }

        protected function tearDown(): void
        {
            $_COOKIE = $this->cookieBackup;
            parent::tearDown();
        }

        private function cart(string $hash = 'carthash'): \WC_Cart
        {
            $cart           = new \WC_Cart();
            $cart->testHash = $hash;
            return $cart;
        }

        private function sessionWithLoyalty(?array $loyaltyData): void
        {
            $session = new class($loyaltyData) {
                public function __construct(private ?array $loyaltyData) {}
                public function get($key, $default = null)
                {
                    return 'loyaltyData' === $key ? $this->loyaltyData : $default;
                }
            };
            $this->wc->session = $session;
        }

        // ── cacheKey ──────────────────────────────────────────────────────────

        public function test_cache_key_is_coupon_order_independent(): void
        {
            $_COOKIE['siteid']           = 'site-1';
            $_COOKIE['olo_coupon_codes'] = 'SAVE10,WELCOME5';
            $keyA = CheckValidate::cacheKey($this->cart());

            $_COOKIE['olo_coupon_codes'] = 'WELCOME5,SAVE10';
            $keyB = CheckValidate::cacheKey($this->cart());

            $this->assertSame($keyA, $keyB, 'Re-applying the same coupons in another order must hit the cache.');
        }

        public function test_cache_key_changes_when_a_coupon_is_removed(): void
        {
            $_COOKIE['siteid']           = 'site-1';
            $_COOKIE['olo_coupon_codes'] = 'SAVE10,WELCOME5';
            $keyBefore = CheckValidate::cacheKey($this->cart());

            $_COOKIE['olo_coupon_codes'] = 'WELCOME5';
            $keyAfter = CheckValidate::cacheKey($this->cart());

            $this->assertNotSame($keyBefore, $keyAfter, 'A stripped coupon must bust the snapshot cache.');
        }

        public function test_cache_key_uses_immediate_context_without_slot_override(): void
        {
            $_COOKIE['siteid'] = 'site-1';
            $key = CheckValidate::cacheKey($this->cart());

            $this->assertStringContainsString('|immediate|', $key);
        }

        public function test_cache_key_uses_slot_context_with_override(): void
        {
            $_COOKIE['siteid']             = 'site-1';
            $_COOKIE['oloNavTimeslotDate'] = '2026-07-08';
            Functions\when('oloNavSlotOverrides')->justReturn(['18:30', null]);

            $key = CheckValidate::cacheKey($this->cart());

            $this->assertStringContainsString('|2026-07-08 18:30|', $key);
        }

        public function test_cache_key_includes_loyalty_reward_identity(): void
        {
            $_COOKIE['siteid'] = 'site-1';

            $this->sessionWithLoyalty(['programs' => ['77' => ['reward-a' => true]]]);
            $keyRewardA = CheckValidate::cacheKey($this->cart());

            // Same program, different redeemed reward — must produce a new key
            // (OE-26382 regression guard).
            $this->sessionWithLoyalty(['programs' => ['77' => ['reward-b' => true]]]);
            $keyRewardB = CheckValidate::cacheKey($this->cart());

            $this->assertNotSame($keyRewardA, $keyRewardB);
        }

        public function test_cache_key_changes_with_cart_hash_and_site(): void
        {
            $_COOKIE['siteid'] = 'site-1';
            $key = CheckValidate::cacheKey($this->cart('hash-A'));

            $this->assertNotSame($key, CheckValidate::cacheKey($this->cart('hash-B')));

            $_COOKIE['siteid'] = 'site-2';
            $this->assertNotSame($key, CheckValidate::cacheKey($this->cart('hash-A')));
        }

        public function test_cache_key_order_type_pay_later_overrides_order_type_cookie(): void
        {
            $_COOKIE['siteid']    = 'site-1';
            $_COOKIE['orderType'] = '1';
            $keyStandard = CheckValidate::cacheKey($this->cart());

            $_COOKIE['table_num']         = '12';
            $_COOKIE['pay_later_control'] = 'Enabled';
            $keyPayLater = CheckValidate::cacheKey($this->cart());

            $this->assertNotSame($keyStandard, $keyPayLater);
            $this->assertStringContainsString('|0|', $keyPayLater);
        }

        // ── couponCodeToStrip ────────────────────────────────────────────────

        private function failedResponse(string $error): \stdClass
        {
            return (object) ['Ok' => false, 'ErrorMessage' => $error];
        }

        public function test_strip_decision_identifies_the_invalid_code(): void
        {
            $response = $this->failedResponse('error_applying_coupon_SAVE10');

            $this->assertSame(
                'SAVE10',
                CheckValidate::couponCodeToStrip($response, ['SAVE10', 'WELCOME5'], false)
            );
        }

        public function test_strip_decision_null_for_non_coupon_error(): void
        {
            $response = $this->failedResponse('item_not_available');

            $this->assertNull(CheckValidate::couponCodeToStrip($response, ['SAVE10'], false));
        }

        public function test_strip_decision_null_when_retry_already_spent(): void
        {
            $response = $this->failedResponse('error_applying_coupon_SAVE10');

            $this->assertNull(
                CheckValidate::couponCodeToStrip($response, ['SAVE10'], true),
                'Only one strip-and-retry per fee pass — a second coupon failure falls through to normal failure handling.'
            );
        }

        public function test_strip_decision_null_when_code_not_applied(): void
        {
            $response = $this->failedResponse('error_applying_coupon_OTHER');

            $this->assertNull(
                CheckValidate::couponCodeToStrip($response, ['SAVE10'], false),
                'Stripping a code we never sent would retry an identical payload — pointless second call.'
            );
        }

        public function test_strip_decision_null_for_ok_null_or_malformed_response(): void
        {
            $this->assertNull(CheckValidate::couponCodeToStrip((object) ['Ok' => true, 'ErrorMessage' => ''], ['SAVE10'], false));
            $this->assertNull(CheckValidate::couponCodeToStrip(null, ['SAVE10'], false));
            $this->assertNull(CheckValidate::couponCodeToStrip(false, ['SAVE10'], false));
            $this->assertNull(CheckValidate::couponCodeToStrip($this->failedResponse('error_applying_coupon_SAVE10'), [], false));
        }

        // ── stripCouponCode ──────────────────────────────────────────────────

        public function test_strip_keeps_other_coupons_and_updates_cookie(): void
        {
            $_COOKIE['olo_coupon_codes'] = 'SAVE10,WELCOME5';
            Functions\expect('wc_add_notice')->once()->with('Invalid Coupon applied.', 'error');

            $survivors = CheckValidate::stripCouponCode('SAVE10', ['SAVE10', 'WELCOME5']);

            $this->assertSame(['WELCOME5'], $survivors);
            $this->assertSame('WELCOME5', $_COOKIE['olo_coupon_codes']);
        }

        public function test_strip_clears_cookie_when_last_coupon_is_removed(): void
        {
            $_COOKIE['olo_coupon_codes'] = 'SAVE10';
            Functions\expect('wc_add_notice')->once()->with('Invalid Coupon applied.', 'error');

            $survivors = CheckValidate::stripCouponCode('SAVE10', ['SAVE10']);

            $this->assertSame([], $survivors);
            $this->assertArrayNotHasKey('olo_coupon_codes', $_COOKIE);
        }

        public function test_strip_notice_text_matches_dismiss_button_contract(): void
        {
            // olo_coupons.js attaches its dismiss (×) control to notices whose
            // text includes "Invalid Coupon" (duplicate-coupon-notification
            // spec, requirement 7). The exact string is the contract.
            $noticed = null;
            Functions\when('wc_add_notice')->alias(function ($text, $level) use (&$noticed) {
                $noticed = [$text, $level];
            });

            CheckValidate::stripCouponCode('SAVE10', ['SAVE10']);

            $this->assertSame(['Invalid Coupon applied.', 'error'], $noticed);
            $this->assertStringStartsWith('Invalid Coupon', $noticed[0]);
        }

        // ── shouldSkipReviewValidate ─────────────────────────────────────────
        // The gate keys off the request-scoped "validated this request" flag
        // set by the fee pass — NOT a snapshot cache-key comparison, because
        // get_cart_hash() folds in get_total('edit'), which is intermediate
        // during the fee hook but final at review render, so recomputed keys
        // never match across the two lifecycle points.

        public function test_review_gate_skips_when_validated_this_request(): void
        {
            $this->assertTrue(
                CheckValidate::shouldSkipReviewValidate(false, true, false)
            );
        }

        public function test_review_gate_fails_open_when_not_validated_this_request(): void
        {
            $this->assertFalse(
                CheckValidate::shouldSkipReviewValidate(false, false, false),
                'No successful fee-pass validation this request → the call must fire so stale carts still get the error overlay.'
            );
        }

        public function test_review_gate_fails_open_when_validation_failed(): void
        {
            $this->assertFalse(
                CheckValidate::shouldSkipReviewValidate(false, true, true),
                'taxValidationFailed must force the call so the error overlay renders for stale carts.'
            );
        }

        public function test_review_gate_always_calls_in_pay_later_mode(): void
        {
            $this->assertFalse(
                CheckValidate::shouldSkipReviewValidate(true, true, false),
                'Pay-later table/area validation (no_locations_available_for_area) is unique to the review call and must never be skipped.'
            );
        }
    }
}
