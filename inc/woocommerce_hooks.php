<?php
use CBSNorthStar\Dto\CartOrderDto;
use CBSNorthStar\Dto\CartOrderReviewDto;
use CBSNorthStar\Dto\OrderDto;
use CBSNorthStar\Repositories\ConfigurationRepository;
use CBSNorthStar\Woapi\Connection;
use CBSNorthStar\Woapi\Payment;
use CBSNorthStar\Order\OrderQR;
use CBSNorthStar\Helpers\CheckValidate;
use CBSNorthStar\Helpers\EmailService;
use CBSNorthStar\Helpers\MenuItemActiveWindow;
use CBSNorthStar\Dto\PaymentDTO;
use CBSNorthStar\Dto\OrderWithPaymentDto;
use CBSNorthStar\Order\Check;
use CBSNorthstar\Woapi\OrderProcess;
use CBSNorthStar\Models\Cart;
use CBSNorthStar\Views\CartBlock;
use CBSNorthStar\Views\MembershipField;
use CBSNorthStar\Views\OrderAtTablePopUp;
use CBSNorthStar\Services\LoyaltyService;
use CBSNorthStar\Repositories\DaypartMenusRepository;
use CBSNorthStar\Helpers\BuildNumberHelper;
use CBSNorthStar\Helpers\SessionReference;
use CBSNorthStar\Logger\CBSLogger;
use CBSNorthStar\Repositories\SessionEventRepository;
use CBSNorthStar\Services\TimeSlotService;

use CBSNorthStar\Helpers\OrderMeta;

include(plugin_dir_path(__DIR__) . 'cbs_functions.php');
require_once dirname(__FILE__) . '/Woapi/Connection.php';
require_once dirname(__FILE__) . '/Woapi/Payment.php';
require_once dirname(__FILE__) . '/Woapi/OrderProcess.php';

add_action('woocommerce_cart_calculate_fees', 'cbs_custom_tax_surcharge', 10, 1);
add_action('woocommerce_cart_calculate_fees', 'calculateGiftCardFee', PHP_INT_MAX, 1);

// OE-25549: the customize / single-product page adds via the standard WooCommerce
// form POST (WC_Form_Handler::add_to_cart_action), which never calls our validate
// hook. Capture the just-added key and validate it on the redirect filter (same
// request, safely outside add_to_cart) so a WOAPI /validate failure removes only
// that item and leaves pre-existing items in the cart.
add_action('woocommerce_add_to_cart', 'cbsCaptureFormAddedKey', 30, 1);
add_filter('wc_add_to_cart_message_html', 'cbsCaptureFormAddedMessage', 10, 1);
add_filter('woocommerce_add_to_cart_redirect', 'cbsValidateFormAddOnRedirect', 20, 1);

add_filter('woocommerce_add_to_cart_fragments', function (array $fragments): array {
    // Always recalculate when rebuilding the cart-collaterals fragment.
    // WPCot's `wp`-action handler pre-populates tip fees outside the
    // calculate_totals flow, so guarding on empty(get_fees()) would skip the
    // recalc and drop fees that are only added via woocommerce_cart_calculate_fees.
    if (WC()->cart && !WC()->cart->is_empty()) {
        WC()->cart->calculate_totals();
    }
    // Force is_cart() truthy during fragment rebuild so WC core's
    // wc_cart_totals_shipping_html() keeps the shipping calculator in the
    // refreshed .cart-collaterals HTML (it short-circuits on is_cart() before
    // the woocommerce_shipping_show_shipping_calculator filter runs).
    add_filter('woocommerce_is_cart', '__return_true');
    ob_start();
    try {
        do_action('woocommerce_cart_collaterals');
        $fragments['.cart-collaterals'] = '<div class="cart-collaterals">' . ob_get_clean() . '</div>';
    } finally {
        // Always clean the buffer (in case the try-block exited via exception
        // before ob_get_clean() ran) and lift the is_cart() override so the
        // rest of the request keeps the real conditional state.
        if (ob_get_level() > 0 && !isset($fragments['.cart-collaterals'])) {
            ob_end_clean();
        }
        remove_filter('woocommerce_is_cart', '__return_true');
    }
    return $fragments;
});


add_action('woocommerce_before_cart_collaterals', function () {
    // Non-AJAX cart-page render: keep the empty(get_fees()) guard. WC core
    // already runs calculate_totals upstream during normal page load; this
    // only recovers when fees ended up empty. AJAX-context recalc is handled
    // by the woocommerce_add_to_cart_fragments filter above.
    if (!(defined('DOING_AJAX') && DOING_AJAX) && is_cart() && WC()->cart && !WC()->cart->is_empty() && empty(WC()->cart->get_fees())) {
        WC()->cart->calculate_totals();
    }
}, 5);


// OE-26318: surface the menu's "no location selected" prompt on the cart page.
// The user lands here after checkout empties the cart on a menu change and WooCommerce
// redirects to the cart, so the guidance must live on the cart — mirroring the menu page.
// `locationSelected === '1'` is the canonical explicit-selection marker (OE-25933); the
// auto-derived "Picked for you" area never sets it.
//
// WooCommerce loads cart/cart.php for a non-empty cart but cart/cart-empty.php for an empty
// one, so both entry points are needed: woocommerce_before_cart (non-empty) and
// woocommerce_cart_is_empty (empty — the post-redirect screen QA actually sees).
add_action('woocommerce_before_cart', 'cbsCartNoLocationNotice', 5);
add_action('woocommerce_cart_is_empty', 'cbsCartNoLocationNotice', 5);
function cbsCartNoLocationNotice()
{
    if (!cbsCartLocationSelected() && function_exists('displayNoLocationSelectedMessage')) {
        echo displayNoLocationSelectedMessage();
    }
}

// True when the customer has explicitly selected a location (OE-25933 marker).
function cbsCartLocationSelected()
{
    return isset($_COOKIE['locationSelected']) && $_COOKIE['locationSelected'] === '1';
}

// OE-26318: tag the cart page when no location is selected so CSS can hide WooCommerce's
// default empty-cart message and return-to-shop button — the location prompt replaces them.
add_filter('body_class', 'cbsCartNoLocationBodyClass');
function cbsCartNoLocationBodyClass($classes)
{
    if (function_exists('is_cart') && is_cart() && !cbsCartLocationSelected()) {
        $classes[] = 'olo-no-location';
    }
    return $classes;
}


// product-active-date-window / cart-item-active-date-enforcement: catch an item
// that has fallen outside its per-site active window while already sitting in
// the cart. Fires on both cart-page render AND checkout validation
// (WC_Checkout::check_cart_items() calls the same action — design.md Decision 4),
// so a customer who skips the cart page and goes straight to checkout is still
// caught. Raises an explicit, item-specific error rather than silently removing
// the line (design.md: "the customer added a valid item in good faith").
add_action('woocommerce_check_cart_items', 'cbsCheckCartActiveDateWindow');
function cbsCheckCartActiveDateWindow()
{
    if (!function_exists('WC') || !WC()->cart) {
        return;
    }

    $siteId = isset($_COOKIE['siteid']) ? sanitize_text_field(wp_unslash($_COOKIE['siteid'])) : '';
    if ('' === $siteId) {
        return;
    }

    $cartLines = [];
    foreach (WC()->cart->get_cart() as $cartItemKey => $cartItem) {
        $productId = $cartItem['product_id'] ?? 0;
        $name = (isset($cartItem['data']) && $cartItem['data'] instanceof WC_Product)
            ? $cartItem['data']->get_name()
            : get_the_title($productId);
        $cartLines[$cartItemKey] = ['product_id' => $productId, 'name' => $name];
    }

    $outOfWindow = MenuItemActiveWindow::findOutOfWindowCartLines($cartLines, $siteId);

    foreach ($outOfWindow as $name) {
        wc_add_notice(
            sprintf(
                /* translators: %s: cart item name */
                __('"%s" is no longer available and must be removed from your cart before you can check out.', 'olo'),
                $name
            ),
            'error'
        );
    }
}

// Suppress "added to cart" notice in kiosk mode (covers PRL upsell standard WC add-to-cart path).
add_filter('wc_add_to_cart_message_html', function ($message, $products) {
    if (get_option('siteMode') === 'kiosk') {
        return '';
    }
    return $message;
}, 10, 2);


// ---------------------------------------------------------------------------
// Payment attempt rate-limiting
// Block checkout after 3 consecutive failed payment attempts in the same session.
// ---------------------------------------------------------------------------

add_action('woocommerce_checkout_process', function () {
    if (get_option('siteMode') === 'kiosk') {
        return;
    }
    $chosenGateway = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : '';
    if ($chosenGateway !== 'authorize_net_cim_credit_card') {
        return;
    }
    if (!WC()->session) {
        return;
    }

    $attempts   = (int) WC()->session->get('cbs_payment_attempts', 0);
    $siteId     = isset($_COOKIE['siteid']) ? sanitize_text_field($_COOKIE['siteid']) : null;
    $wcOrderId  = (int) WC()->session->get('order_awaiting_payment') ?: null;
    $orderTotal = WC()->cart ? (float) WC()->cart->get_total('edit') : null;

    if ($attempts >= 3) {
        wc_add_notice(
            __('Too many failed payment attempts. Please try a different payment method or contact support.', 'olo'),
            'error'
        );
        // Include attempt count in the message so each blocked submission
        // produces a different dedup hash in scheduleAIAnalysis and is not
        // suppressed by the 24-hour deduplication window.
        CBSLogger::orders()->warning('[PAYMENT] Authorize.Net checkout blocked — too many attempts (' . $attempts . ')', [
            'attempts' => $attempts,
        ]);
        SessionEventRepository::create()->logEvent(
            SessionReference::get(),
            SessionEventRepository::EVENT_PAYMENT_FAILED,
            SessionEventRepository::STATUS_FAILED,
            ['error' => 'checkout_blocked_too_many_attempts', 'attempts' => $attempts, 'order_total' => $orderTotal],
            $wcOrderId,
            $siteId
        );
        return;
    }
});

// Increment the payment attempts counter only after all checkout field
// validations pass — i.e., when a real payment attempt is imminent.
// woocommerce_checkout_process fires before field validation, so incrementing

add_action('woocommerce_after_checkout_validation', function ($data, $errors) {
    if (get_option('siteMode') === 'kiosk') {
        return;
    }
    $chosenGateway = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : '';
    if ($chosenGateway !== 'authorize_net_cim_credit_card') {
        return;
    }
    if (!WC()->session) {
        return;
    }
    if ($errors->has_errors()) {
        return;
    }
    $attempts = (int) WC()->session->get('cbs_payment_attempts', 0);

    if ($attempts >= 3) {
        return;
    }
    WC()->session->set('cbs_payment_attempts', $attempts + 1);
}, 10, 2);

// Server-side card comparison: reset the counter when Authorize.Net confirms
// a different card via order meta (written from the API response — not from
// client-supplied POST data). Also logs the decline to the session report.
// Only fires on the first status transition to 'failed' per order lifetime.
add_action('woocommerce_order_status_failed', function ($orderId, $order) {
    if ($order->get_payment_method() !== 'authorize_net_cim_credit_card') {
        return;
    }

    $notes    = wc_get_order_notes(['order_id' => $orderId, 'limit' => 1, 'orderby' => 'date_created_gmt', 'order' => 'DESC']);
    $lastNote = !empty($notes) ? wp_strip_all_tags($notes[0]->content) : '';

    CBSLogger::orders()->warning('[PAYMENT] Authorize.Net order failed', [
        'order_id' => $orderId,
        'error'    => $lastNote,
    ]);

    if (!WC()->session) {
        return;
    }

    // Use the server-set account_four from order meta (written by SkyVerge from
    // the Authorize.Net response) to detect a genuine card change — never trust
    // client-supplied POST values for this decision.
    $accountFour     = (string) $order->get_meta('_wc_authorize_net_cim_credit_card_account_four');
    $storedAccountFour = (string) WC()->session->get('cbs_last_card_four', '');

    if ($accountFour && $storedAccountFour && $accountFour !== $storedAccountFour) {
        // Different card confirmed server-side — reset counter so the customer
        // gets fresh attempts with their new card.
        WC()->session->set('cbs_payment_attempts', 1);
        CBSLogger::orders()->info('[PAYMENT] New card detected (server) — attempt counter reset', [
            'order_id' => $orderId,
        ]);
    } else {
        $existing = (int) WC()->session->get('cbs_payment_attempts', 0);
        WC()->session->set('cbs_payment_attempts', $existing + 1);
    }

    if ($accountFour) {
        WC()->session->set('cbs_last_card_four', $accountFour);
    }

    $attempts = (int) WC()->session->get('cbs_payment_attempts');
    $siteId   = isset($_COOKIE['siteid']) ? sanitize_text_field($_COOKIE['siteid']) : null;

    SessionEventRepository::create()->logEvent(
        SessionReference::get(),
        SessionEventRepository::EVENT_PAYMENT_FAILED,
        SessionEventRepository::STATUS_FAILED,
        ['error' => 'authorize_net_payment_attempt', 'attempts' => $attempts, 'order_total' => (float) $order->get_total()],
        (int) $orderId,
        $siteId
    );
}, 10, 2);

add_action('woocommerce_payment_complete', function ($orderId) {
    if (WC()->session) {
        WC()->session->__unset('cbs_payment_attempts');
    }
});


/**
 * Get taxes response
 *
 * @since  1.0.8
 * @version 1.0.8
 * @param  $cart
 */
function oloCartRunningTotal(WC_Cart $cart): float
{
    $feeTotal = (float) array_sum(array_map(fn($f) => $f->amount, $cart->get_fees()));

    return $cart->get_cart_contents_total()
        + $feeTotal
        + $cart->get_shipping_total()
        + $cart->get_shipping_tax()
        + $cart->get_taxes_total();
}

/**
 * Compute the cbsValidateSnapshot cache key for the current request context.
 *
 * Thin delegate to CheckValidate::cacheKey() — the shared snapshot key recipe
 * (OE-26669) used by cbs_custom_tax_surcharge() and the review-order gate.
 * The logic lives in the Helpers class so it is unit-testable without loading
 * this file (whose require chain pulls in wp-load.php via Woapi/Connection.php).
 */
function cbsValidateCacheKey(WC_Cart $cart): string
{
    return CheckValidate::cacheKey($cart);
}

function cbs_custom_tax_surcharge($cart)
{
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }

    // Request-scoped "cart validated" signal for the review-order gate
    // (OE-26669). Reset on every fee pass so the LAST validation outcome of
    // this request wins; set true below on a snapshot cache hit or a
    // successful validate call. A snapshot cache-key comparison cannot serve
    // the gate instead: get_cart_hash() folds in get_total('edit'), which is
    // intermediate during this hook but final at review render, so keys from
    // the two lifecycle points never match.
    unset($GLOBALS['cbsValidateOkThisRequest']);

    $clear_cart_flag = (!empty($_COOKIE['olo_clear_cart']) && $_COOKIE['olo_clear_cart'] === '1')
        || (WC()->session && WC()->session->get('olo_clear_cart') === '1');

    // During add-item validation (cbsValidateAddedItem) the whole cart must never be
    // emptied — only the just-added item is removed by that caller. Suppress acting on
    // the flag this pass WITHOUT deleting it: a flag set by an unrelated mechanism
    // (e.g. stale-menu reset at action_woocommerce_review_order_before_submit) must
    // survive to its own follow-up render (OE-25549).
    if (!empty($GLOBALS['cbsAddItemValidation'])) {
        $clear_cart_flag = false;
    }

    if ($clear_cart_flag) {
        CBSLogger::orders()->info('[TAX SURCHARGE] Skipped: olo_clear_cart active — cart emptied.');
        WC()->cart->empty_cart();
        setcookie('olo_clear_cart', '', time() - 3600, '/');
        unset($_COOKIE['olo_clear_cart']);
        if (WC()->session) {
            WC()->session->set('olo_clear_cart', null);
        }
        return;
    }

    if ($cart->is_empty()) {
        CBSLogger::orders()->info('[TAX SURCHARGE] Skipped: cart is empty — no items to validate.');
        return;
    }

    try {
        $config = ConfigurationRepository::create();
        $configDetails = $config->getDetails();
    }
    catch (\Throwable $e) {
        CBSLogger::orders()->error('[TAX SURCHARGE] Skipped: ConfigurationRepository error', ['message' => $e->getMessage()]);
        return;
    }

    $token = $configDetails->token;

    setcookie("token", $token, time() + 3600, '/', '', is_ssl(), true);

    $siteid = !empty($_COOKIE['siteid'])
        ? sanitize_text_field($_COOKIE['siteid'])
        : (isset($_GET['site_id']) ? sanitize_text_field($_GET['site_id']) : '');

    if (!$siteid) {
        CBSLogger::orders()->warning('[TAX SURCHARGE] site id value null or empty', ['type' => gettype($siteid)]);
        return;
    }

    $siteAreaid = getAreaId($siteid);

    $deliveryDate = current_time('Y-m-d H:i:s');
    [$overrideTime] = oloNavSlotOverrides();
    if ($overrideTime) {
        $slotDate     = isset($_COOKIE['oloNavTimeslotDate']) ? sanitize_text_field($_COOKIE['oloNavTimeslotDate']) : current_time('Y-m-d');
        $deliveryDate = $slotDate . ' ' . $overrideTime;
    }

    $tableNum = isset($_COOKIE['table_num']) ? htmlspecialchars($_COOKIE['table_num'], ENT_QUOTES, 'UTF-8') : '';
    $payLaterControl = isset($_COOKIE['pay_later_control']) ? htmlspecialchars($_COOKIE['pay_later_control'], ENT_QUOTES, 'UTF-8') : '';
    $orderTypeCookie = isset($_COOKIE['orderType']) ? (int)$_COOKIE['orderType'] : null;

    if (!empty($tableNum) && $payLaterControl === "Enabled") {
        $orderType = 0;
    }
    else {
        $orderType = isset($orderTypeCookie) ? $orderTypeCookie : 1;
    }

    if (isset($_COOKIE["guestPhone"])) {
        $cart->get_customer()->set_billing_phone($_COOKIE["guestPhone"]);
    }

    $coupons = !empty($_COOKIE['olo_coupon_codes'])
        ? explode(',', sanitize_text_field(wp_unslash($_COOKIE['olo_coupon_codes'])))
        : [];

    // Skip the external API call when the cart, site, and coupons haven't changed
    // since the last successful validation. The key recipe lives in
    // cbsValidateCacheKey() (shared with the review-order gate, OE-26669) and
    // covers all inputs that affect the tax/coupon result, so a quantity change,
    // site switch, or coupon update always busts the cache and triggers a fresh call.
    $cacheKey = cbsValidateCacheKey($cart);
    if (WC()->session) {
        $snapshot = WC()->session->get('cbsValidateSnapshot');
        if (
            $snapshot &&
            isset($snapshot['cacheKey'], $snapshot['taxTotal']) &&
            $snapshot['cacheKey'] === $cacheKey
        ) {
            if (!empty($snapshot['couponDiscount'])) {
                $cart->add_fee(__('Coupons Discount', 'woocommerce'), $snapshot['couponDiscount'], false);
            }
            if ( isset( $snapshot['rewardTotal'] ) && $snapshot['rewardTotal'] < 0 ) {
                $cart->add_fee( 'Rewards', $snapshot['rewardTotal'], false, '' );
            }
            $cart->add_fee(__('TAX', 'woocommerce'), $snapshot['taxTotal'], false);
            setcookie("TAX", $snapshot['taxTotal'], time() + 3600, '/', '', is_ssl(), true);
            WC()->session->set('taxValidationFailed', false);
            $GLOBALS['cbsValidateOkThisRequest'] = true;
            CBSLogger::orders()->debug('[TAX SURCHARGE] Cache hit — skipping API call', ['cacheKey' => $cacheKey, 'taxTotal' => $snapshot['taxTotal']]);
            return;
        }
    }

    $path = '/checks/validate/';

    $connection = new Connection();

    // This call runs synchronously inside every cart totals recalculation
    // (quantity changes included). It uses the 45s transport default: OE-26589
    // originally bounded it to 25s, but that reopen showed legitimate validate
    // calls can exceed 25s under load, tripping false validate failures. A hung
    // OEAPI still degrades into the existing validate-failure path
    // (taxValidationFailed + notice, cart preserved) — just at the 45s ceiling.
    // Use especialPostData (logErrorToUI = false) so a validate failure does NOT
    // add WoapiRequest's raw "Unknown Error" notice — this path already surfaces
    // the friendlier "We couldn't validate your order…" message below, and we
    // don't want both stacked (OE-26589).
    //
    // Invalid-coupon strip-and-retry (OE-26669): this is now the ONLY coupon
    // validation — the standalone P9 pre-pass (cbsValidateCoupons) that paid a
    // second upstream round-trip on every fee pass was removed. When the API
    // rejects the cart solely because one applied coupon is invalid
    // (error_applying_coupon_<code>), strip that code from the cookie, queue the
    // same "Invalid Coupon applied." notice the pre-pass showed, and retry ONCE
    // with the survivors so tax for this render reflects the corrected coupon
    // set — the pre-pass's same-pass correction, without its happy-path cost.
    $retriedCouponStrip = false;
    while (true) {
        $payload = (new CartOrderDto([
            'order' => $cart,
            'orderType' => $orderType,
            'deliveryDate' => $deliveryDate,
            'areaId' => $siteAreaid ?? "",
            'coupons' => $coupons
        ]))->toJson();

        CBSLogger::orders()->debug('[TAX SURCHARGE] Validate payload', ['siteid' => $siteid, 'path' => $path, 'payload' => $payload]);
        $response = $connection->especialPostData($siteid, $path, 'Token', $payload);

        write_log($response);

        $invalidCode = oloCouponCodeToStrip($response, $coupons, $retriedCouponStrip);
        if ($invalidCode === null) {
            break;
        }

        $coupons = cbsStripCouponCodeFromCookie($invalidCode, $coupons);
        CBSLogger::orders()->warning('[TAX SURCHARGE] Invalid coupon stripped — retrying validate once', [
            'invalidCode'      => $invalidCode,
            'survivingCoupons' => $coupons,
        ]);

        // The coupon set changed, so recompute the snapshot key: the retry's
        // successful response must be cached under the corrected key or every
        // follow-up render would miss the cache and re-call the API.
        $cacheKey = cbsValidateCacheKey($cart);
        $retriedCouponStrip = true;
    }

    if (!$response || !is_object($response)) {
        CBSLogger::orders()->error('[TAX SURCHARGE] Skipped: API returned null or non-object response', ['siteid' => $siteid]);
        if (WC()->session) {
            WC()->session->set('taxValidationFailed', true);
            WC()->session->set('lastValidateError', '');
        }
        // A validate failure must NOT wipe the whole cart (OE-25549). The offending
        // item is removed at add time by cbsValidateAddedItem; here we only flag the
        // failure (blocks checkout via woocommerce_after_checkout_validation) and skip
        // applying tax. The cart is preserved so a single bad item — or an API blip —
        // never empties items the customer already added.
        // calculate_totals() re-runs several times per request (fragment refresh,
        // before_cart_collaterals re-run when no fees are set), so guard against adding
        // the same session notice more than once (OE-26589 stacked-notice fix).
        $validateNotice = __('We couldn\'t validate your order right now. Please review your items and try again.', 'olo');
        cbsAddUniqueErrorNotice($validateNotice);
        return;
    }

    if ($response->Ok) {

        $rewardResult = calculateRewardFee($cart, $response->Data);
        $taxDiscount  = is_array( $rewardResult ) ? ( $rewardResult['tax_savings'] ?? 0 ) : $rewardResult;
        $rewardTotal  = is_array( $rewardResult ) ? ( $rewardResult['reward_total'] ?? null ) : null;
        $taxTotal = $response->Data->TaxTotal;

        if($taxDiscount!== null && $taxDiscount == 0) {
            $taxTotal = 0;
        }elseif ($taxDiscount > 0) {
            $taxTotal = $taxDiscount;
        }

        $gcData       = WC()->session ? (array) WC()->session->get('giftCardData', []) : [];
        $gcPending    = (float) array_sum(array_map(fn($gc) => $gc['giftcardReduce'] ?? 0, $gcData));
        $effectiveTotal = oloCartRunningTotal($cart) + $gcPending; // gcPending is negative

        if ($response->Data->CouponCodes && $effectiveTotal > 0) {
            $cart->add_fee(__('Coupons Discount', 'woocommerce'), $response->Data->AdjustmentTotal, false);
        } elseif (!empty($coupons) && $effectiveTotal <= 0) {
            setcookie('olo_coupon_codes', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
            unset($_COOKIE['olo_coupon_codes']);
            wc_add_notice(__('Coupon cannot be applied. Your order total is already covered.', 'olo'), 'error');
        }

        CBSLogger::orders()->debug('[TAX SURCHARGE] Tax Total from API', ['taxTotal' => $taxTotal]);
        $cart->add_fee( __( 'TAX', 'woocommerce'), $taxTotal, false );
        setcookie("TAX", $taxTotal, time() + 3600, '/', '', is_ssl(), true);

        if (get_option('siteMode') === 'kiosk') {
            if (WC()->session) {
                WC()->session->set('orderpayloadKiosk', json_encode($response->Data));
            }
        }

        $GLOBALS['cbsValidateOkThisRequest'] = true;

        $hash = $cart->get_cart_hash();
        if (WC()->session) {
            WC()->session->set('taxValidationFailed', false);
            WC()->session->set('cbsValidateSnapshot', [
                'hash'           => $hash,
                'cacheKey'       => $cacheKey,
                'data'           => json_encode($response->Data),
                'taxTotal'       => $taxTotal,
                'couponDiscount' => ($response->Data->CouponCodes ? (float) $response->Data->AdjustmentTotal : null),
                'rewardTotal'    => $rewardTotal,
            ]);

            WC()->session->set('orderpayload', json_encode($response->Data));

            if (empty($coupons)) {
                setcookie('olo_coupon_codes', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
            }
        }
    }
    else {
        $validateError = ($response && $response->ErrorMessage != "") ? $response->ErrorMessage : '';
        if (WC()->session) {
            WC()->session->set('taxValidationFailed', true);
            WC()->session->set('lastValidateError', $validateError);
        }
        if ($response && $response->ErrorMessage != "") {
            CBSLogger::orders()->error('[TAX SURCHARGE] Skipped: API validation failed', ['error' => $response->ErrorMessage]);
            $validateNotice = __('We couldn\'t validate your order. Please review your items and try again.', 'olo');
            cbsAddUniqueErrorNotice($validateNotice);
        }
        else {
            CBSLogger::orders()->error('[TAX SURCHARGE] Skipped: API response Ok=false with no ErrorMessage', ['siteid' => $siteid]);
            $validateNotice = __('We couldn\'t validate your order right now. Please review your items and try again.', 'olo');
            cbsAddUniqueErrorNotice($validateNotice);
        }
        // A validate failure must NOT wipe the whole cart (OE-25549). Previously this
        // set olo_clear_cart, which a later (unguarded) request — e.g. a parallel cart
        // fragment refresh — would consume to empty the whole cart, even after the
        // add-time validation had already removed only the offending item. The flag is
        // no longer set here; taxValidationFailed alone blocks checkout. The
        // olo_clear_cart flag remains exclusively owned by the menu-change flow.
    }

}

/**
 * Clear the deferred whole-cart-clear flag (cookie + session).
 *
 * Used after an add-item validate failure so that removing the single offending
 * item does NOT cascade into emptying the whole cart on the next request.
 */
function cbsClearOloClearCartFlag(): void
{
    if (WC()->session) {
        WC()->session->set('olo_clear_cart', null);
    }
    setcookie('olo_clear_cart', '', time() - 3600, '/');
    unset($_COOKIE['olo_clear_cart']);
}

/**
 * Map a raw WOAPI validate error to a customer-facing message.
 */
function cbsValidateFailureMessage(string $rawError): string
{
    if (strpos($rawError, 'no_locations_available_for_area') !== false) {
        return __('No locations available for your area.', 'olo');
    }

    // ECM rejects orders placed outside the kitchen's open hours (OE-26385).
    if (strpos($rawError, 'invalid_pickup_time') !== false) {
        return __('This location is currently closed and cannot accept orders at this time.', 'olo');
    }

    return __('This item can\'t be added to your order right now.', 'olo');
}

/**
 * Normalize a notice string for resilient dedupe checks.
 */
function cbsNormalizeNotice(string $notice): string
{
    $notice = wp_strip_all_tags($notice);
    $notice = preg_replace('/\s+/', ' ', $notice);
    return strtolower(trim((string) $notice));
}

/**
 * Add an error notice once per request/session even when cart totals re-enter.
 */
function cbsAddUniqueErrorNotice(string $notice): void
{
    static $seen = [];

    $normalized = cbsNormalizeNotice($notice);
    if ($normalized === '') {
        return;
    }

    if (isset($seen[$normalized])) {
        return;
    }

    $seen[$normalized] = true;

    if (wc_has_notice($notice, 'error')) {
        return;
    }

    $existing = wc_get_notices('error');
    foreach ($existing as $entry) {
        $existingNotice = is_array($entry) && isset($entry['notice']) ? (string) $entry['notice'] : (string) $entry;
        if (cbsNormalizeNotice($existingNotice) === $normalized) {
            return;
        }
    }

    wc_add_notice($notice, 'error');
}

/**
 * Validate the cart right after item(s) were added and, on failure, remove ONLY
 * those items — leaving any pre-existing items in place (OE-25549).
 *
 * Validation runs synchronously here (mirroring quantityChange) so the WOAPI
 * /checks/validate/ call and the cbsValidateSnapshot cache land in this request.
 * cbs_custom_tax_surcharge sets taxValidationFailed and lastValidateError on
 * failure; this reads that signal, removes the just-added $cartItemKeys, and
 * re-validates the remaining cart (a snapshot cache hit, so no extra API call).
 * A validate failure never empties the whole cart — cbs_custom_tax_surcharge no
 * longer sets olo_clear_cart on failure, so there is no whole-cart-clear to suppress.
 *
 * Takes an array so the quick-add batch endpoint (AddToCartLoader::ajaxHandler)
 * can validate its whole batch in one calculate_totals()/WOAPI call and roll back
 * every item the batch added on failure, not just one — WOAPI returns a single
 * Ok/ErrorMessage for the whole cart, never a per-item breakdown, so there is no
 * finer-grained attribution to preserve here than "what this request added."
 *
 * add_to_cart() returns the SAME cart_item_key when this request's item matches
 * an existing line's product+components — it merges quantity into that line
 * rather than creating a new one. Without $preExistingQuantities, rolling back
 * that key would delete the whole line, including quantity the customer already
 * had before this request. $preExistingQuantities (keyed by cart_item_key, taken
 * before this request's adds) lets rollback restore just that original quantity
 * instead, and only remove_cart_item() a key that didn't exist before at all.
 *
 * @param string[] $cartItemKeys Keys returned by WC()->cart->add_to_cart() for the new item(s).
 * @param array<string, int> $preExistingQuantities cart_item_key => quantity, snapshotted before this request added anything. Omitted (single-item callers) preserves today's remove-entirely behavior.
 * @return array{ok: bool, message?: string}
 */
function cbsValidateAddedItems(array $cartItemKeys, array $preExistingQuantities = []): array
{
    // Snapshot whether olo_clear_cart was already set BEFORE this validation. The
    // validate-fail branches are guarded (they never set the flag while validating),
    // so the only way it can be set here is a pre-existing one from an unrelated
    // mechanism (stale-menu reset). We must not delete that flag — it owns its own
    // follow-up render — so we only clear in finally when we did NOT inherit it.
    $hadClearFlag = (!empty($_COOKIE['olo_clear_cart']) && $_COOKIE['olo_clear_cart'] === '1')
        || (WC()->session && WC()->session->get('olo_clear_cart') === '1');

    // Request-scoped guard: while it is set, cbs_custom_tax_surcharge must NOT empty
    // the cart nor set the olo_clear_cart flag on a validate failure. That whole-cart
    // clear is what wiped pre-existing items — here only the just-added items are
    // removed, by this function. The guard makes that independent of how many times
    // calculate_totals re-enters the fee hook (OE-25549).
    $GLOBALS['cbsAddItemValidation'] = true;

    try {
        WC()->cart->calculate_totals();

        $failed = WC()->session && WC()->session->get('taxValidationFailed') === true;
        if (!$failed) {
            return ['ok' => true];
        }

        $rawError = WC()->session ? (string) WC()->session->get('lastValidateError') : '';
        $message  = cbsValidateFailureMessage($rawError);

        CBSLogger::orders()->warning('[ADD VALIDATE] Item(s) failed validation — removing only what this request added', [
            'cart_item_keys' => $cartItemKeys,
            'error'          => $rawError,
        ]);

        // Remove ONLY the offending item(s); pre-existing items stay. A key that
        // already had quantity before this request (add_to_cart() merged into it
        // rather than creating it) gets restored to that original quantity instead
        // of deleted outright — otherwise the customer's earlier quantity on that
        // same line would be lost along with what this request added.
        foreach (array_unique($cartItemKeys) as $cartItemKey) {
            if (isset($preExistingQuantities[$cartItemKey])) {
                WC()->cart->set_quantity($cartItemKey, $preExistingQuantities[$cartItemKey], false);
            } else {
                WC()->cart->remove_cart_item($cartItemKey);
            }
        }
        if (WC()->session) {
            WC()->session->set('taxValidationFailed', false);
        }
        wc_clear_notices();

        // Re-validate the remaining cart so its TAX fee + cbsValidateSnapshot are
        // correct for the follow-up render. Still guarded, so a failure here cannot
        // empty the cart either.
        WC()->cart->calculate_totals();
        if (WC()->session) {
            WC()->session->set('taxValidationFailed', false);
        }

        // Persist now so the follow-up fragment/cart render reads the reduced cart
        // and a cleared flag, not a stale session.
        WC()->cart->set_session();
        WC()->cart->maybe_set_cart_cookies();
        if (WC()->session) {
            WC()->session->save_data();
        }

        return ['ok' => false, 'message' => $message];
    }
    finally {
        unset($GLOBALS['cbsAddItemValidation']);
        // Only clear a flag this validation itself would have caused. Never delete a
        // pre-existing one — it belongs to another flow (e.g. stale-menu reset).
        if (!$hadClearFlag) {
            cbsClearOloClearCartFlag();
        }
    }
}

/**
 * Single-item convenience wrapper around cbsValidateAddedItems(), used by the
 * form-POST redirect flow and the REST /cart controller — both add exactly one
 * item per request and are unaffected by the quick-add batch endpoint.
 *
 * @param string $cartItemKey Key returned by WC()->cart->add_to_cart() for the new item.
 * @return array{ok: bool, message?: string}
 */
function cbsValidateAddedItem(string $cartItemKey): array
{
    return cbsValidateAddedItems([$cartItemKey]);
}

/**
 * Capture the cart_item_key just added through the standard WooCommerce
 * single-product form POST (the "customize" page). Stored in a request global so
 * cbsValidateFormAddOnRedirect() can validate it before the redirect.
 *
 * Only the form POST path is targeted here: the quick-add AJAX
 * (add_to_cart_action_cbs) and REST (/cart) paths validate explicitly in their own
 * handlers, the edit flow is left to CartEditController, and programmatic adds
 * (cart restore, site transition) set no add-to-cart request var. (OE-25549)
 *
 * @param string $cartItemKey
 */
function cbsCaptureFormAddedKey($cartItemKey): void
{
    if (empty($_REQUEST['add-to-cart'])) {
        return; // not the standard single-product form POST
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return; // REST /cart handled by MainController::addToCart
    }
    if (!empty($_POST['action']) && $_POST['action'] === 'add_to_cart_action_cbs') {
        return; // quick-add handled by AddToCartLoader::ajaxHandler
    }
    if (!empty($_POST['edit_cart_item_key'])) {
        return; // cart edit/replace flow — handled by CartEditController
    }

    $GLOBALS['cbsFormAddedKey'] = $cartItemKey;
}

/**
 * Capture the message WooCommerce is about to queue as a session notice for the
 * standard single-product form add, so it can be replayed as a cookie-driven toast on
 * the redirect target instead. Gated on cbsFormAddedKey (set by cbsCaptureFormAddedKey,
 * which already excludes the kiosk REST/quick-add-ajax/edit-flow paths) so this never
 * fires for anything but the native form POST. (OE-26616)
 *
 * @param string $message
 * @return string
 */
function cbsCaptureFormAddedMessage($message)
{
    if (!empty($GLOBALS['cbsFormAddedKey'])) {
        $GLOBALS['cbsFormAddedMessage'] = $message;
    }
    return $message;
}

/**
 * On the redirect that follows a single-product form add, validate the cart and —
 * if WOAPI /validate rejects it — remove ONLY the just-added item, preserving any
 * pre-existing items. Replaces the misleading "added" / "cart has been cleared"
 * notices with a single actionable error. (OE-25549)
 *
 * @param string $url
 * @return string
 */
function cbsValidateFormAddOnRedirect($url)
{
    if (empty($GLOBALS['cbsFormAddedKey'])) {
        return $url;
    }

    $cartItemKey = $GLOBALS['cbsFormAddedKey'];
    unset($GLOBALS['cbsFormAddedKey']);

    $addedMessage = $GLOBALS['cbsFormAddedMessage'] ?? null;
    unset($GLOBALS['cbsFormAddedMessage']);

    $result = cbsValidateAddedItem($cartItemKey);
    if (!$result['ok']) {
        // Drop WooCommerce's "added to cart" notice and the validate failure's
        // "cart has been cleared" notice. Instead of a WooCommerce notice (which
        // renders inside the customize modal), hand the message to the menu page via
        // a short-lived cookie so rules.js shows it as the top toast — consistent
        // with the quick-add path. (OE-25549)
        wc_clear_notices();
        setcookie('oloAddError', $result['message'], time() + 60, '/');
        return $url;
    }

    // The native form POST always queues its "added to cart" notice as a WC session
    // notice, but the redirect target (the [menuitems] shortcode's default render path)
    // never calls wc_print_notices() — the notice would otherwise silently vanish until
    // the customer happens to land on a page that does print notices. Replay it as the
    // same cookie-driven top toast the quick-add path already shows. (OE-26616)
    if ($addedMessage) {
        wc_clear_notices();
        setcookie('oloAddSuccess', $addedMessage, time() + 60, '/');
    }

    return $url;
}
// cbsValidateCoupons() was removed (OE-26669): it fired a second, uncached,
// unbounded /checks/validate/ call on every fee pass whenever a coupon cookie
// existed. Its invalid-coupon strip now lives inside cbs_custom_tax_surcharge()
// as a strip-and-retry on the single authoritative validate call.
function oloGetInvalidCoupon(string $error): ?string
{
    return CheckValidate::invalidCouponCode($error);
}

/**
 * Decide whether a failed /checks/validate/ response warrants stripping a
 * coupon code and retrying once in the same fee pass (OE-26669).
 * Delegates to CheckValidate::couponCodeToStrip() (unit-tested there).
 */
function oloCouponCodeToStrip($response, array $coupons, bool $alreadyRetried): ?string
{
    return CheckValidate::couponCodeToStrip($response, $coupons, $alreadyRetried);
}

/**
 * Reject one applied coupon: strip it from the olo_coupon_codes cookie, queue
 * the "Invalid Coupon applied." notice, and return the surviving codes.
 * Delegates to CheckValidate::stripCouponCode() (unit-tested there).
 */
function cbsStripCouponCodeFromCookie(string $invalidCode, array $coupons): array
{
    return CheckValidate::stripCouponCode($invalidCode, $coupons);
}

function calculateGiftCardFee(WC_Cart $cart)
{
    static $totalsCalculated = false; // Prevent multiple executions

    if ($totalsCalculated) {
        return;
    }

    $totalsCalculated = true;

    $giftCardArray = WC()->session->get('giftCardData', []);
    if (!empty($giftCardArray)) {
        $giftcardTotal = 0;

        $remainingTotal = oloCartRunningTotal($cart);

        foreach ($giftCardArray as &$giftCardData) {
            if ($remainingTotal <= 0) {
                $giftCardData['giftcardReduce'] = 0;
            }
            else {
                $giftCardData['giftcardReduce'] = min($giftCardData['giftCardBalance'], $remainingTotal) * -1;
                $remainingTotal += $giftCardData['giftcardReduce'];
            }
            $giftcardTotal += $giftCardData['giftcardReduce'];
        }
        WC()->session->set('giftCardData', $giftCardArray);
        if ($giftcardTotal != 0) {
            $cart->add_fee(__('Gift Card Total', 'woocommerce'), $giftcardTotal, false);

        }
    }
    $totalsCalculated = false;
}



function calculateRewardFee($cart, $orderPayload)
{
    $loyaltyRewards = WC()->session->get('loyaltyData', null);
    if (!$loyaltyRewards || count($loyaltyRewards['programs']) === 0) {
        return null;
    }

    $allPrograms = array_merge(...array_values($loyaltyRewards['programs']));
    $hasValidatedPrograms = array_filter($allPrograms, fn($entry) => !empty($entry['check']));
    if(empty($hasValidatedPrograms)) {
        return null;
    }

    $siteId = !empty($_COOKIE['siteid']) 
    ? sanitize_text_field($_COOKIE['siteid'])
    : (isset($_GET['site_id']) ? sanitize_text_field($_GET['site_id']) : '');

    $orderItems = $orderPayload->OrderItems ?? [];

    $customerId = $loyaltyRewards['customer']['id'] ?? ($loyaltyRewards['customerId'] ?? null);

    if(!$customerId) {
        CBSLogger::transactions()->warning('Loyalty rewards data missing customer ID', ['loyaltyRewards' => $loyaltyRewards]);
        return null;
    }
    $loyaltyservice = new LoyaltyService($siteId, $customerId);

    $loyaltyPrograms = $loyaltyservice->getAvailableLoyaltyPrograms($orderPayload);

    foreach ($loyaltyRewards['programs'] as $programId => $enrollments) {
        foreach ($enrollments as $uniqueKey => $entry) {

            $payload = [
                'ProgramId' => $programId,
                'OrderItemIds' => $loyaltyservice->getQualifyingOrderItemIds($loyaltyPrograms, $programId) ?? [],
                'Amount' => $entry['points'] ?? ($entry['data']['points'] ?? 0),
                'Check' => [
                    'OrderItems' => $orderItems ?? [],
                    'CustomerId' => $customerId
                ]
            ];

            $url = '/loyalty/validate';
            $validateLoyalty = (new Connection())->postData($siteId, $url, 'Token', json_encode($payload));
            CBSLogger::transactions()->debug('Validating loyalty program', ['payload' => $payload]);

            if (!$validateLoyalty->Ok) {
                CBSLogger::transactions()->error('Loyalty validate error', ['error' => $validateLoyalty->ErrorMessage]);
                continue;
            }
            CBSLogger::transactions()->debug('Loyalty validation response', ['data' => $validateLoyalty->Data]);
            $loyaltyRewards['programs'][$programId][$uniqueKey]['check'] = $validateLoyalty->Data ?? null;
        }
    }

    WC()->session->set('loyaltyData', $loyaltyRewards);
    $cartSubtotal = (float) $cart->get_cart_contents_total();
    $programsDiscounts = computeDiscountsFromProgramsAdjustments($loyaltyRewards['programs'] ?? [], $cartSubtotal);
    CBSLogger::transactions()->debug('Computed loyalty discounts', ['discounts' => $programsDiscounts]);

    if ($programsDiscounts['total_discount'] > 0) {
        $cart->add_fee('Rewards', -$programsDiscounts['total_discount'], false, '');
        WC()->session->set('RewardsData', $programsDiscounts['discount_by_program'] ?? []);
        return [
            'tax_savings'  => $programsDiscounts['tax_savings'] ?? 0,
            'reward_total' => -$programsDiscounts['total_discount'],
        ];
    }

    return ['tax_savings' => 0, 'reward_total' => null];
}

function computeDiscountsFromProgramsAdjustments(array $programs, float $maxDiscount = PHP_FLOAT_MAX): array
{
    if (empty($programs)) {
        return [
            'total_discount' => 0,
            'discount_by_program' => [],
            'tax_savings' => 0,
        ];
    }
    $items = [];
    foreach ($programs as $prog) {
        foreach (($prog->OrderItems ?? []) as $it) {
            $id = (string)($it->OrderItemId ?? '');
            if ($id === '') {
                continue;
            }

            $qty = (float)($it->Quantity ?? 1);
            $prc = (float)($it->Price ?? 0);
            if (!isset($items[$id])) {
                $items[$id] = [
                    'price' => $prc,
                    'qty' => $qty,
                    'remaining' => $prc,
                    'taxes' => $it->Taxes ?? [],
                ];
            }
        }
    }

    $totalTaxByProgram = [];
    $discountByProgram = [];
    $totalDiscount = 0.0;
    $remaining = $maxDiscount;

    foreach ($programs as $programId => $enrollments) {
        foreach ($enrollments as $uniqueKey => $entry) {
            $prog  = $entry['check'];
            if($prog === null) {
                continue;
            }
            $pid = (string)$programId;
            $pname = isset($entry['name']) ? (string)$entry['name'] : (isset($entry['data']['name']) ? (string)$entry['data']['name'] : null);
            $rawDiscount    = abs($prog->AdjustmentTotal ?? 0.0);
            $cappedDiscount = min($rawDiscount, max(0.0, $remaining));

            $totalTaxByProgram[$pid] = ($totalTaxByProgram[$pid] ?? 0.0) + ($prog->TaxTotal ?? 0.0);

            $discountByProgram[$pid][$uniqueKey] = ['total' => $cappedDiscount, 'reward_name' => $pname];

            $totalDiscount += $cappedDiscount;
            $remaining     -= $cappedDiscount;

            if( $remaining <= 0) {
                $totalTaxByProgram[$pid] = 0.0;
                continue;
            }
        }
    }

    return [
        'discount_by_program' => $discountByProgram,
        'tax_savings'         => round(array_sum($totalTaxByProgram), 2),
        'total_discount'      => round($totalDiscount, 2),
    ];
}


/**
 * use when loading the checkout page
 */
add_action('woocommerce_review_order_before_submit', 'action_woocommerce_review_order_before_submit', 10, 0);

/**
 * Review order before checkout
 *
 * @since  1.0.8
 * @version 1.0.8
 */
/**
 * Decide whether the review-order render can skip its /checks/validate/ call
 * because cbs_custom_tax_surcharge() already validated the cart earlier in
 * this same request (OE-26669). Delegates to
 * CheckValidate::shouldSkipReviewValidate() (unit-tested there).
 */
function cbsReviewOrderShouldSkipValidate(bool $payLaterActive, bool $validatedThisRequest, bool $validationFailed): bool
{
    return CheckValidate::shouldSkipReviewValidate($payLaterActive, $validatedThisRequest, $validationFailed);
}

function action_woocommerce_review_order_before_submit()
{
    $siteid = isset($_COOKIE['siteid']) ? sanitize_text_field($_COOKIE['siteid']) : '';
    $areaExternalCode = isset($_COOKIE['area_external_code']) ? sanitize_text_field($_COOKIE['area_external_code']) : '';
    $orderType = isset($_COOKIE['orderType']) ? (int)$_COOKIE['orderType'] : 0;
    
    $cart = WC()->cart;

    if (isset($_COOKIE["guestPhone"])) {
        $cleanedPhoneNumber = preg_replace('/[()\s-]/', '', $_COOKIE["guestPhone"]);
        $cart->get_customer()->set_billing_phone($cleanedPhoneNumber);
    }
    $activeMenu = (DaypartMenusRepository::create())->getActiveDaypartMenu(
        $siteid,
        ...oloNavSlotOverrides()
    );

    if (!isset($_COOKIE["currentMenu"]) || $_COOKIE["currentMenu"] != $activeMenu) {
        setcookie("currentMenu", $activeMenu, time() + 86400, "/");
        setcookie('olo_clear_cart', '1', time() + 60, '/');
        WC()->session->set('olo_clear_cart', '1');
        WC()->session->set('olo_menu_changed', '1');
        setcookie('olo_menu_changed', '1', time() + 60, '/');
    }
    else {
        WC()->session->set('olo_menu_changed', '0');
        setcookie('olo_menu_changed', '0', time() + 60, '/');
    }

    $deliveryDate = current_time('Y-m-d H:i:s');
    CBSLogger::orders()->info('action_woocommerce_review_order_before_submit');
    $payload = (new CartOrderReviewDto([
        'order' => $cart,
        'orderType' => $orderType,
        'deliveryDate' => $deliveryDate,
        'orderItems' => $cart->get_cart(),
        'tableNumber' => "",
        'areaExternalCode' => $areaExternalCode,
    ]))->toJson();

    $path = '/checks/validate/';
    $connection = new Connection();

    if (!$siteid) {
        echo '<div class="location-not-selected">Please select a <a href="/" class="select-location-btn"> Location </a></div>';
        echo '<div class="location-not-selected-overlay"></div>';
        echo '<div id= "overlay-error"class="overlay-error" onclick="scrollToTop()"></div>';
        return;
    }

    // Same-request gate (OE-26669): calculate_totals() runs before this
    // template renders, so cbs_custom_tax_surcharge() has already validated
    // this cart — re-asking WOAPI here added ~600ms to every checkout field
    // change while sending a strictly weaker payload (see CartOrderReviewDto).
    // The signal is the request-scoped flag set by the fee pass (a snapshot
    // cache-key recomputation cannot work here: get_cart_hash() differs
    // between the fee hook and this render — see CheckValidate).
    // Pay-later keeps the call: its table/area check is unique to this path.
    // The payload above is still built unconditionally because its DTO also
    // writes the cartitemkey_arr_init cookie the order-at-table flows read.
    $payLaterActive = isset($_COOKIE['pay_later_control'])
        && !empty($_COOKIE['table_num'])
        && !empty($areaExternalCode);
    $validatedThisRequest = !empty($GLOBALS['cbsValidateOkThisRequest']);
    $validationFailed     = WC()->session && WC()->session->get('taxValidationFailed') === true;

    if (cbsReviewOrderShouldSkipValidate($payLaterActive, $validatedThisRequest, $validationFailed)) {
        CBSLogger::orders()->debug('[REVIEW ORDER] Cart validated this request — skipping /checks/validate/ call');
        return;
    }

    // The success path of this call is otherwise silent — log why it fired so
    // the gate's behavior is observable in cbs-orders logs (OE-26669).
    CBSLogger::orders()->debug('[REVIEW ORDER] Performing /checks/validate/ call', [
        'reason' => $payLaterActive ? 'pay_later' : ($validationFailed ? 'validation_failed' : 'not_validated_this_request'),
    ]);

    // Uses the 45s transport default, matching the tax-surcharge call (OE-26589
    // reopen removed the earlier 25s bound): a hung WOAPI still degrades into the
    // error overlay rather than freezing checkout, just at the 45s ceiling.
    $response = $connection->postData($siteid, $path, 'Token', $payload);

    // A hung or failed WOAPI (timeout at the 45s ceiling) bubbles up a null
    // response here — postData() returns json_decode('') on a WP_Error. Guard
    // before dereferencing: without this, null->ErrorMessage warns and
    // (null != "") is false, so the error overlay never renders and an
    // unvalidated cart passes through to submit. Degrade into the same overlay
    // the ErrorMessage branch shows, mirroring the fee-pass guard (OE-26589 reopen).
    if (!$response || !is_object($response)) {
        wc_add_notice(__('Invalid Items in the cart.'), 'error');
        echo '<div id= "overlay-error"class="overlay-error" onclick="scrollToTop()"></div>';
        CBSLogger::orders()->error('API response Review Order Before submit', ['error' => 'null_or_non_object_response']);
        return;
    }

    if ($response->ErrorMessage != "") {
        if ($response->ErrorMessage === "no_locations_available_for_area") {
            $label = "No locations available";
            wc_add_notice(__($label), 'error');
        }
        else {
            $label = "Invalid Items in the cart.";
            wc_add_notice(__($label), 'error');
        }
        echo '<div id= "overlay-error"class="overlay-error" onclick="scrollToTop()"></div>';
        CBSLogger::orders()->error('API response Review Order Before submit', ['error' => $response->ErrorMessage]);
    }
}

// Hook to save guest identification data to order during checkout (kiosk mode only)
add_action('woocommerce_checkout_create_order', 'cbsSaveGuestDataToOrder', 10, 2);

/**
 * Save guest name and phone from cookies to order object during checkout (kiosk mode only)
 * 
 * @param WC_Order $order Order object
 * @param array $data Posted checkout data
 */
function cbsSaveGuestDataToOrder($order, $data)
{
    // Only apply in kiosk mode
    if (get_option('siteMode') !== 'kiosk') {
        return;
    }

    if (isset($_COOKIE['guestName']) && !empty($_COOKIE['guestName'])) {
        $guest_name = sanitize_text_field($_COOKIE['guestName']);
        $order->set_billing_first_name($guest_name);
    }

    if (isset($_COOKIE['guestPhone']) && !empty($_COOKIE['guestPhone'])) {
        $clean_phone = preg_replace('/[^0-9]/', '', $_COOKIE['guestPhone']);
        $order->set_billing_phone($clean_phone);
    }
}


add_filter('cbs_send_order', 'cbsSendOrder', 10, 2);

/**
 * Acquire a cross-process exclusive lock for submitting a single order to WOAPI.
 *
 * Uses a MySQL named lock (GET_LOCK), which is shared across PHP-FPM workers and
 * does NOT depend on an external object cache — so two concurrent checkout
 * requests (double-click, gateway retry, overlapping status hooks) cannot both
 * reach submitOrder() for the same order.
 *
 * @param int $orderId
 * @param int $timeout Seconds to wait for the lock before giving up.
 * @return bool True if this caller now owns the lock.
 */
function cbsAcquireOrderSubmitLock(int $orderId, int $timeout = 10): bool
{
    global $wpdb;
    $lockName = substr($wpdb->dbname, 0, 24) . '_cbs_order_submit_' . $orderId;
    $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, $timeout));
    return $got === '1';
}

/**
 * Release the named lock acquired by cbsAcquireOrderSubmitLock().
 */
function cbsReleaseOrderSubmitLock(int $orderId): void
{
    global $wpdb;
    $lockName = substr($wpdb->dbname, 0, 24) . '_cbs_order_submit_' . $orderId;
    $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
}

/**
 * Acquire a cross-process exclusive lock scoped to one WooCommerce session, for
 * the quick-add batch endpoint (AddToCartLoader::ajaxHandler). Same GET_LOCK
 * technique as cbsAcquireOrderSubmitLock(), keyed by session/customer ID instead
 * of order ID.
 *
 * Client-side request coalescing already keeps at most one add_to_cart_action_cbs
 * request in flight per browser tab, which removes the lost-item race for the
 * common case. This lock is a backstop for what coalescing can't see: a second
 * browser tab (or any other caller) open to the same session firing a genuinely
 * concurrent batch request. The second caller waits for the lock rather than
 * racing WC_Session_Handler::save_data()'s blind full-blob overwrite.
 *
 * @param string $sessionId WC()->session->get_customer_id() for the current request.
 * @param int    $timeout   Seconds to wait for the lock before giving up.
 * @return bool True if this caller now owns the lock.
 */
function cbsAcquireCartSessionLock(string $sessionId, int $timeout = 5): bool
{
    global $wpdb;
    $lockName = substr($wpdb->dbname, 0, 20) . '_cbscart_' . substr(md5($sessionId), 0, 24);
    $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, $timeout));
    return $got === '1';
}

/**
 * Release the named lock acquired by cbsAcquireCartSessionLock().
 */
function cbsReleaseCartSessionLock(string $sessionId): void
{
    global $wpdb;
    $lockName = substr($wpdb->dbname, 0, 20) . '_cbscart_' . substr(md5($sessionId), 0, 24);
    $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
}

/**
 * Re-read the committed cart from the session and rebuild WC()->cart from it.
 *
 * Call this immediately AFTER acquiring cbsAcquireCartSessionLock() in a cart-mutation
 * AJAX handler. WooCommerce hydrates the cart from the session once on `wp_loaded`, before
 * the handler runs, so a request that had to wait on the session lock would otherwise
 * mutate a stale snapshot and, on its own save_data(), silently re-introduce an item that
 * a concurrent request just removed (the reported OE-26589 "deleted item comes back on
 * refresh" bug). Refreshing only the cart-related session keys avoids clobbering unrelated
 * in-memory session state; get_cart_from_session() then rebuilds the live cart contents.
 *
 * @param string $sessionId WC()->session->get_customer_id() for the current request.
 */
function cbsRehydrateCartFromSession(string $sessionId): void
{
    if ( ! WC()->session || ! WC()->cart || '' === $sessionId ) {
        return;
    }

    // Read the committed session row STRAIGHT from the DB. WC_Session_Handler::get_session()
    // would return the copy cached at wp_loaded (the non-persistent object cache is populated
    // on init), i.e. the same stale snapshot this request started with — making the re-read a
    // no-op and leaving the lost-update race in place. A direct query is cache-independent, so
    // a request that waited on the lock sees the removal a prior request just committed.
    global $wpdb;
    $raw = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT session_value FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s",
            $sessionId
        )
    );
    if ( null === $raw ) {
        return;
    }

    $fresh = maybe_unserialize($raw);
    if ( ! is_array($fresh) ) {
        return;
    }

    // Overwrite EVERY cart key WC_Cart_Session::get_cart_from_session() consumes,
    // not only the keys present in the committed row. Skipping an absent key would
    // leave the value hydrated at wp_loaded in place, and get_cart_from_session()
    // reads each key back from the session — so a missing 'cart' key would rebuild
    // WC()->cart from the stale in-memory cart and resurrect a just-removed item.
    // For an absent key fall back to the SAME default get_cart_from_session() uses
    // (empty array for the collections, null for cart_totals), so committed state
    // always wins and no wrong type reaches WC's setters.
    $cartKeyDefaults = array(
        'cart'                       => array(),
        'cart_totals'                => null,
        'applied_coupons'            => array(),
        'coupon_discount_totals'     => array(),
        'coupon_discount_tax_totals' => array(),
        'removed_cart_contents'      => array(),
    );
    foreach ($cartKeyDefaults as $key => $default) {
        WC()->session->set(
            $key,
            array_key_exists($key, $fresh) ? maybe_unserialize($fresh[$key]) : $default
        );
    }

    WC()->cart->get_cart_from_session();
}

/**
 * True once an order has been successfully submitted to WOAPI. The flag is set
 * inside the submit lock so a concurrent caller that wins the lock next sees it
 * and skips a duplicate submission.
 */
function cbsOrderAlreadySubmitted(int $orderId): bool
{
    $order = wc_get_order($orderId);
    return $order ? $order->get_meta('_cbs_woapi_submitted', true) === '1' : false;
}

/**
 * Mark an order as successfully submitted to WOAPI (idempotency flag).
 */
function cbsMarkOrderSubmitted(int $orderId): void
{
    $order = wc_get_order($orderId);
    if ($order) {
        $order->update_meta_data('_cbs_woapi_submitted', '1');
        $order->save();
    }
}

function cbsSendOrder($ok, WC_Order $order)
{

    $orderId = $order->get_id();
    $siteId = isset($_COOKIE['siteid']) ? sanitize_text_field(wp_unslash($_COOKIE['siteid'])) : '';
    $checkId = isset($_COOKIE['checkid']) ? sanitize_text_field(wp_unslash($_COOKIE['checkid'])) : '';

    if (!$siteId) {
        return new WP_Error('cbs_missing_site', 'Missing site id.');
    }

    // Serialize all submission attempts for this order across processes.
    if (!cbsAcquireOrderSubmitLock($orderId)) {
        CBSLogger::orders()->warning('Order submit already in progress — duplicate suppressed', ['order_id' => $orderId]);
        return new WP_Error('cbs_order_in_progress', "We're still processing your order. Please don't resubmit.");
    }

    try {
        // Re-check inside the lock: a concurrent request may have just finished.
        if (cbsOrderAlreadySubmitted($orderId)) {
            CBSLogger::orders()->info('Order already submitted to WOAPI — skipping duplicate', ['order_id' => $orderId]);
            return true;
        }

        if ($checkId) {
            $result = handleCheckIdScenario($orderId, $siteId, $order);
        }
        else {
            $result = handleDefaultScenario($orderId, $siteId, $order);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        return true;

    }
    catch (Throwable $e) {
        return new WP_Error('cbs_exception', 'Could not send order. Please try again.');
    }
    finally {
        cbsReleaseOrderSubmitLock($orderId);
    }
}

function handleCheckIdScenario($orderId, $siteId, $orderData)
{
    $orderType = isset($_COOKIE['orderType']) ? (int)$_COOKIE['orderType'] : 2;
    $orderQR = new OrderProcess($orderId, $siteId, $orderType, $orderData);
    $responsePayment = $orderQR->sendPaymentOnly($_COOKIE['checkid']);

    if ($responsePayment->Ok) {
        cbsMarkOrderSubmitted((int) $orderId);
        SessionEventRepository::create()->logEvent(
            SessionReference::get(),
            SessionEventRepository::EVENT_PAYMENT_PROCESSED,
            SessionEventRepository::STATUS_SUCCESS,
            ['check_id' => $_COOKIE['checkid'] ?? null],
            (int) $orderId,
            $siteId
        );
        cbsConfirmTimeSlot($orderData, $siteId, (string) $orderQR->checkId);
        completeOrderProcessing($orderQR);
        return true;
    }
    else {
        SessionEventRepository::create()->logEvent(
            SessionReference::get(),
            SessionEventRepository::EVENT_PAYMENT_FAILED,
            SessionEventRepository::STATUS_FAILED,
            ['error' => is_object($responsePayment) ? ($responsePayment->ErrorMessage ?? 'payment failed') : 'no response'],
            (int) $orderId,
            $siteId
        );
        $orderQR->order->add_order_note(OrderProcess::ERROR_SUBMIT);
        return new WP_Error('cbs_payment_failed', 'Payment submission failed.');
    }
}

function handleDefaultScenario(int $orderId, string $siteId, WC_Order $order): bool|WP_Error
{

    $orderType = isset($_COOKIE['orderType'])
        ? (int)sanitize_text_field(wp_unslash($_COOKIE['orderType']))
        : 2;

    if (empty($siteId)) {
        $order->add_order_note('[CBS] Missing siteId.');
        return new WP_Error('cbs_missing_site', 'Missing site configuration. Please try again.');
    }

    $areaId = getAreaId($siteId);

    if (empty($areaId)) {
        $order->add_order_note('[CBS] Missing areaId for siteId: ' . $siteId);
        return new WP_Error('cbs_missing_area', 'Store configuration error. Please contact support.');
    }

    try {
        $orderWoapi = new OrderProcess($orderId, $siteId, $orderType, $order, $areaId);

        $responseValidate = processAndValidateOrderWithLoyalty($orderWoapi, $siteId);

        if (empty($responseValidate) || empty($responseValidate->Ok)) {

            $order->add_order_note('[CBS] Validate failed: ' . json_encode($responseValidate));
            $order->add_order_note('[CBS] ' . OrderProcess::ERROR_VALIDATE);

            return new WP_Error(
                'cbs_validate_failed',
                "We couldn't validate your order. Please try again."
                );
        }

        $responseSubmit = $orderWoapi->submitOrder();

        if (empty($responseSubmit) || empty($responseSubmit->Ok)) {

            $order->add_order_note('[CBS] Submit failed: ' . json_encode($responseSubmit));
            $order->add_order_note('[CBS] ' . OrderProcess::ERROR_SUBMIT);
            CBSLogger::orders()->error('Submit failed', ['response' => $responseSubmit]);
            SessionEventRepository::create()->logEvent(
                SessionReference::get(),
                SessionEventRepository::EVENT_ORDER_FAILED,
                SessionEventRepository::STATUS_FAILED,
                ['error' => is_object($responseSubmit) ? ($responseSubmit->ErrorMessage ?? 'submit failed') : 'no response'],
                $orderId,
                $siteId
            );

            return new WP_Error(
                'cbs_submit_failed',
                "We couldn't submit your order. Please try again."
                );
        }

        cbsMarkOrderSubmitted($orderId);
        SessionEventRepository::create()->logEvent(
            SessionReference::get(),
            SessionEventRepository::EVENT_ORDER_SUBMITTED,
            SessionEventRepository::STATUS_SUCCESS,
            ['check_id' => $responseSubmit->Data->CheckId ?? null, 'check_number' => $responseSubmit->Data->CheckNumber ?? null],
            $orderId,
            $siteId
        );
        cbsConfirmTimeSlot($order, $siteId, (string) ($responseSubmit->Data->CheckId ?? ''));
        completeOrderProcessing($orderWoapi);
        return true;

    }
    catch (Throwable $e) {

        // Order note: short, safe
        $order->add_order_note('[CBS] Exception: ' . $e->getMessage());

        // Server log: more detail (optional)
        error_log('[CBS] handleDefaultScenario exception order ' . $orderId . ': ' . $e->getMessage());

        return new WP_Error(
            'cbs_exception',
            'We hit an unexpected error while processing your order. Please try again.'
            );
    }
}

function completeOrderProcessing($orderObj)
{
    $orderObj->order->update_status('completed');
    setcookie("checkid", $orderObj->checkId, time() + 86400, '/', '', is_ssl(), true);
    setcookie("checknumber", $orderObj->checkNumber, time() + 86400, '/', null, is_ssl(), true);
    WC()->session->__unset('giftCardData');

    // Clear the applied-coupon cookie here so cleanup does not depend on the
    // Thank You page render (OE-26209).
    setcookie('olo_coupon_codes', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
    unset($_COOKIE['olo_coupon_codes']);

    // Reset the customer shipping address and the chosen shipping method so
    // the next cart page does not pre-fill the shipping calculator with the
    // previous order's data (OE-26317 reopen).
    oloResetCustomerShippingState();
}

/**
 * Wipe the customer shipping address, the shipping-calculated flag and the
 * chosen shipping method so a fresh order starts with an empty calculator.
 * Used after order completion (WOAPI submit and the thank-you fallback).
 */
function oloResetCustomerShippingState(): void
{
    if (WC()->customer) {
        // Preserve the project's US baseline (see the
        // woocommerce_default_address_fields filter below); only the
        // user-entered fields are cleared.
        WC()->customer->set_shipping_country('US');
        WC()->customer->set_shipping_state('');
        WC()->customer->set_shipping_city('');
        WC()->customer->set_shipping_postcode('');
        WC()->customer->set_shipping_address_1('');
        WC()->customer->set_shipping_address_2('');
        WC()->customer->set_shipping_company('');
        WC()->customer->set_calculated_shipping(false);
        WC()->customer->save();
    }
    if (WC()->session) {
        WC()->session->set('chosen_shipping_methods', array());
        WC()->session->set('shipping_for_package_0', null);
    }
}

/**
 * Confirm the reserved timeslot with CBS after a successful WOAPI order submission.
 * Silently skips if no timeslot was reserved for this order.
 *
 * @param WC_Order $order   The WooCommerce order.
 * @param string   $siteId  CBS site identifier.
 * @param string   $checkId CheckId returned by the WOAPI submit response.
 */
function cbsConfirmTimeSlot(WC_Order $order, string $siteId, string $checkId = ''): void
{
    if ($order->get_meta('_cbs_time_slot_confirmed', true)) {
        return;
    }

    $timeSlotsOrderId = $order->get_meta('_olo_time_slot_order_id', true);
    if (empty($timeSlotsOrderId)) {
        return;
    }

    $confirmed = TimeSlotService::create()->confirmTimeSlot($siteId, (string) $timeSlotsOrderId, $checkId);

    if ($confirmed) {
        $order->update_meta_data('_cbs_time_slot_confirmed', '1');
        $order->save();
    } else {
        CBSLogger::orders()->error('[CBS] Failed to confirm timeslot', [
            'orderId'          => $order->get_id(),
            'timeSlotsOrderId' => $timeSlotsOrderId,
            'checkId'          => $checkId,
        ]);
    }
}

//add WooComerce order to Woapi when order is processing
add_action('woocommerce_order_status_processing', 'cbsCheckOrder', 10);

/**
 * Send New order to WOAPI
 *
 * @since  1.0.8
 * @version 1.0.8
 * @param  string $orderId
 */
function cbsCheckOrder($orderId)
{
    global $wpdb;
    $order = wc_get_order($orderId);

    // Orders processed via the cbs_send_order filter (e.g. Authorize.net) are
    // already validated and submitted by cbsSendOrder. Skip them here to avoid
    // duplicate WOAPI calls and duplicate session events.
    if ($order->get_payment_method() === OrderProcess::CREDIT_CARD) {
        return;
    }

    $siteId = $_COOKIE['siteid'] ?? '';
    $orderType = isset($_COOKIE['orderType']) ? (int)$_COOKIE['orderType'] : 2;
    $areaId = getAreaId($siteId);

    // Serialize against the cbs_send_order path and against repeated
    // status_processing transitions so an order is submitted to WOAPI once.
    if (!cbsAcquireOrderSubmitLock((int) $orderId)) {
        CBSLogger::orders()->warning('cbsCheckOrder: submit lock busy — another submission in progress, skipping', ['order_id' => $orderId]);
        return;
    }
    try {
    if (cbsOrderAlreadySubmitted((int) $orderId)) {
        CBSLogger::orders()->info('cbsCheckOrder: order already submitted — skipping', ['order_id' => $orderId]);
        return;
    }
    $processOrderWoapi = new OrderProcess($orderId, $siteId, $orderType, null, $areaId);
    $responseWoapi = processAndValidateOrderWithLoyalty($processOrderWoapi, $siteId);

    //Process NorthStar Order
    if ($responseWoapi->Ok) {
        if ($processOrderWoapi->getPaymentMethod() === OrderProcess::CASH_ON_DELIVERY || $processOrderWoapi->getPaymentMethod() === '') {
            $responseSubmitOrder = $processOrderWoapi->submitOrder();
            if ($responseSubmitOrder && $responseSubmitOrder->Ok) {
                cbsMarkOrderSubmitted((int) $orderId);
                SessionEventRepository::create()->logEvent(
                    SessionReference::get(),
                    SessionEventRepository::EVENT_ORDER_SUBMITTED,
                    SessionEventRepository::STATUS_SUCCESS,
                    ['check_id' => $responseSubmitOrder->Data->CheckId ?? null, 'check_number' => $responseSubmitOrder->Data->CheckNumber ?? null],
                    (int) $orderId,
                    $siteId
                );
                cbsConfirmTimeSlot($order, $siteId, (string) ($responseSubmitOrder->Data->CheckId ?? ''));
                $order->update_status('completed');
                setcookie("checkid", $processOrderWoapi->checkId, time() + 86400, '/', '', is_ssl(), true);
                setcookie("checknumber", $processOrderWoapi->checkNumber, time() + 86400, '/', '', is_ssl(), true);

            }
            else {
                SessionEventRepository::create()->logEvent(
                    SessionReference::get(),
                    SessionEventRepository::EVENT_ORDER_FAILED,
                    SessionEventRepository::STATUS_FAILED,
                    ['error' => is_object($responseSubmitOrder) ? ($responseSubmitOrder->ErrorMessage ?? 'submit failed') : 'no response'],
                    (int) $orderId,
                    $siteId
                );
                $order->add_order_note(OrderProcess::ERROR_SUBMIT);
            }
        }
    }
    else {
        $order->add_order_note(OrderProcess::ERROR_VALIDATE);
        $order->add_order_note('API Response:' . htmlentities(serialize($responseWoapi)));
    }


    //Process NorthStar QR Order
    if ($order->get_payment_method() != "northstar" && isset($_COOKIE['checkid']) && $_COOKIE['checkid'] != "") {
        $cart = WC()->cart;
        $deliveryDate = current_time('Y-m-d H:i:s');
        $payload = (new CartOrderReviewDto(
        [
            'order' => $cart,
            'orderType' => 0,
            'deliveryDate' => $deliveryDate,
            'orderItems' => $cart->get_cart(),
            'tableNumber' => "",
            'areaExternalCode' => "",
        ]))->toJson();
        $cartitemkey_arr = json_decode(stripslashes($_COOKIE['cartitemkey_arr_init']), true);
        //set cookie to avoid sending double items

        foreach ($cartitemkey_arr as $itemkey => $itemvalue) {
            setcookie($itemkey, $itemvalue, time() + 86400, '/', null, is_ssl(), true);
        }

        $orderQR = new OrderQR();
        $responseQR = $orderQR->qrHandler(
            $_COOKIE['siteid'],
            $payload,
            $_COOKIE['table_num'],
            getAreaId($_COOKIE['siteid']),
            $cartitemkey_arr
        );

        if ($responseQR) {
            CBSLogger::orders()->info('itemsleft sent');
        }
        // --- Task 2: HPOS-compatible OrderMeta CRUD operations ---
        OrderMeta::set($order, 'cbs_orderid', esc_attr(htmlspecialchars($_COOKIE['checkid'])), false);
        OrderMeta::set($order, 'cbs_siteid', esc_attr(htmlspecialchars($_COOKIE['siteid'])), false);
        OrderMeta::set($order, 'cbs_checknumber', esc_attr(htmlspecialchars($_COOKIE['checknumber'])), true);
    }
    } finally {
        cbsReleaseOrderSubmitLock((int) $orderId);
    }
}

$sitemode = get_option('siteMode');
$thankyou = get_option('thank_you_page_message');
if (get_option('thank_you_page_message') != "" && (get_option('siteMode') !== 'olo')) {
    add_action('woocommerce_before_thankyou', 'addKioskImage', 10, 1);
    add_filter('woocommerce_thankyou_order_received_text', 'kioskHeadThankyou', 10, 1);
    add_action('wp_footer', 'embededStartOver', 10, 1);
}

function addKioskImage()
{
    $url = plugins_url('../img/approvedImg.png', __FILE__);
    echo "<img src='$url' alt='' style='display:block; margin:auto'>";
}

function kioskHeadThankyou()
{
    $message = get_option('thank_you_page_message');
    $messageParts = explode(".", $message, 2);

    if ($messageParts[1] == "") {
        return '<p class="title-sucess" style="text-align:center;">Payment Successful</p>
    <p class="thankyou-message" style="text-align:center;">' . $messageParts[0] . '</p>';
    }

    return '
  <p class="thankyou-message" style="text-align:center;">' . $messageParts[0] . '.</br>' .
        $messageParts[1] . '</p>';
}

function embededStartOver()
{
    wp_enqueue_script('start-over-js', plugins_url('/../js/kioskStartOver.js', __FILE__), array(), BuildNumberHelper::getBuildNumber(), true);
    add_filter('script_loader_tag', 'add_type_attribute', 10, 2);
}

function add_type_attribute($tag, $handle)
{
    // Add type="module" to the specific script handle
    if ('start-over-js' !== $handle) {
        return $tag;
    }
    return str_replace(' src', ' type="module" src', $tag);
}

//add thankyou hook
add_action('woocommerce_thankyou', 'cbsAddContentThankyou', 10);

// Display nav time-slot info on thank-you page when orddd is not active
add_action('woocommerce_order_details_after_order_table', function ($order) {
    if (!function_exists('carbon_get_theme_option')
        || !(bool) carbon_get_theme_option('olo_enable_time_slots')) {
        return;
    }

    if (is_plugin_active('order-delivery-date/order_delivery_date.php')
        || is_plugin_active('order-delivery-date-for-woocommerce/order_delivery_date.php')) {
        return;
    }

    $slotTime     = $order->get_meta('_olo_time_slot_time');
    $businessDate = $order->get_meta('_olo_time_slot_business_date');

    if (!$slotTime && !$businessDate) {
        return;
    }

    $displayTime = '';
    if ($slotTime) {
        // Literal wall-clock CBS returned (offset ignored), not the UTC instant.
        $displayTime = \CBSNorthStar\Helpers\TimeSlotValueParser::formatDisplayTime($slotTime);
    }

    $displayDate = '';
    if ($businessDate) {
        $dt = DateTime::createFromFormat('Y-m-d', $businessDate);
        $displayDate = $dt ? $dt->format('j F, Y') : esc_html($businessDate);
    }

    echo '<p>';
    if ($displayDate) {
        echo '<strong>' . esc_html__('Selected Date:', 'olo') . '</strong> ' . esc_html($displayDate) . '<br>';
    }
    if ($displayTime) {
        echo '<strong>' . esc_html__('Time Slot:', 'olo') . '</strong> ' . esc_html($displayTime);
    }
    echo '</p>';
}, 20);

/**
 * Add Thank you Message
 *
 * @since  1.0.8
 * @version 1.0.8
 * @param  string $order
 */
function cbsAddContentThankyou()
{
    $checknumber = $_COOKIE['checknumber'];
    if ($checknumber) {
        echo '<div class="woapi-response" >';
        echo "Submit to kitchen CheckNumber: " . $checknumber;
        echo "<br>";
        echo 'Status: Successfully submitted<br/>';
        echo '</div>';
    }
    else {
        echo '<div class="woapi-response" >';
        echo 'Something happened! Please contact the restaurant staff.';
        echo '</div>';
    }
    echo isset($_SESSION['deliverydate']) ? esc_html($_SESSION['deliverydate']) : '';

    if (get_option('thank_you_page_message') != "" && (get_option('siteMode') !== 'olo')) {
        echo '<div class="finalize-controls" style="display:flex; justify-content:center">
      <a href="' . home_url() . '" id="finalizeButton" class="button btn-finalize wp-block-button__link">Finalize</a>
    </div>';
    }
    echo "<script>
        console.log('Clearing guest data from sessionStorage');
        sessionStorage.removeItem('guestPhone');
        // Purge the WC cart-fragments cache so the next cart visit does not
        // restore the previous order's cart-collaterals HTML (which holds the
        // pre-filled shipping calculator). See OE-26317 reopen.
        Object.keys(sessionStorage).forEach(function (k) {
            if (k.indexOf('wc_cart_') === 0 || k.indexOf('wc_fragments_') === 0) {
                sessionStorage.removeItem(k);
            }
        });
        try { localStorage.removeItem('woocommerce_cart_hash'); } catch (e) {}
    </script>";

    setcookie("checkid", "", time() - 3600, '/', '', is_ssl(), true);
    setcookie("checknumber", "", time() - 3600, '/', '', is_ssl(), true);
    setcookie("table_num", "", time() - 3600, '/', '', is_ssl(), true);
    setcookie("area_id", "", time() - 3600, '/', '', is_ssl(), true);
    setcookie("areaId", "", time() - 3600, '/', '', is_ssl(), true);
    setcookie("areaIdSite", "", time() - 3600, '/', '', is_ssl(), true);
    setcookie("area_external_code", "", time() - 3600, '/', '', is_ssl(), true);
    setcookie("pay_later_control", "", time() - 3600, '/', '', is_ssl(), true);
    setcookie("cart_item_arr", "", time() - 3600, '/', '', is_ssl(), true);
    setcookie("cartitemkey_arr_init", "", time() - 3600, '/', '', is_ssl(), true);
    setcookie("cartitemkey_arr", "", time() - 3600, '/', '', is_ssl(), true);
    setcookie('olo_coupon_codes', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
    setcookie('oloNavTimeslot',        '', time() - 3600, '/');
    setcookie('oloNavTimeslotId',      '', time() - 3600, '/');
    setcookie('oloNavTimeslotTime',    '', time() - 3600, '/');
    setcookie('oloNavTimeslotDate',    '', time() - 3600, '/');
    setcookie('oloNavTimeslotOrderId', '', time() - 3600, '/');
    setcookie('oloNavTimeslotSiteId',  '', time() - 3600, '/');
    setcookie('oloNavAreaId',          '', time() - 3600, '/');
    WC()->session->set('giftCardData', null);
    WC()->session->set('loyaltyData', null);
    WC()->session->set('RewardsData', null);
    WC()->session->set('customerData', null);

    // Mirror the customer shipping reset done in completeOrderProcessing() so
    // non-WOAPI gateways that reach the thank-you page directly also start the
    // next order with an empty shipping calculator (OE-26317 reopen).
    oloResetCustomerShippingState();
}

//Override Woocomerce Mail to add Check Number
add_action('woocommerce_email_order_details', 'action_woocommerce_email_order_details', 1, 4);

/**
 * Get Mail Woocommerce Hook
 *
 * @since  1.0.8
 * @version 1.0.8
 * @param  string $order $sent_to_admin $plain_text $email
 */
function action_woocommerce_email_order_details($order, $sent_to_admin, $plain_text, $email)
{
    echo "<h2>Check Number: " . ($_COOKIE['checknumber'] ?? "") . "</h2>";
}
add_action('woocommerce_after_add_to_cart_quantity', 'ts_quantity_plus_sign');

function ts_quantity_plus_sign()
{
    echo '<button type="button" class="plus" >+</button></div>';
}

add_action('woocommerce_before_add_to_cart_quantity', 'ts_quantity_minus_sign');

function ts_quantity_minus_sign()
{
    echo '<div class="row"><button type="button" class="minus" >-</button>';
}

add_action('wp_footer', 'ts_quantity_plus_minus');

function ts_quantity_plus_minus()
{
    // To run this on the single product page
    if (!is_product())
        return;
?>
<script type="text/javascript">

    jQuery(document).ready(function ($) {

        $('form.cart').on('click', 'button.plus, button.minus', function () {

            // Get current quantity values
            var qty = $(this).closest('form.cart').find('.qty');
            var val = parseFloat(qty.val());
            var max = parseFloat(qty.attr('max'));
            var min = parseFloat(qty.attr('min'));
            var step = parseFloat(qty.attr('step'));

            // Change the value if plus or minus
            if ($(this).is('.plus')) {
                if (max && (max <= val)) {
                    qty.val(max);
                }
                else {
                    qty.val(val + step);
                }
            }
            else {
                if (min && (min >= val)) {
                    qty.val(min);
                }
                else if (val > 1) {
                    qty.val(val - step);
                }
            }

        });

    });

</script>
<?php
}

function redirectToMenuAfterAddToCart()
{
    global $woocommerce;

    $url = home_url('/menu-items/');

    $cart = $woocommerce->cart->get_cart();
    $lastItemAdded = array_pop($cart);
    $product_id = $lastItemAdded['product_id'];

    if (has_term('holiday', 'product_cat', $product_id) && get_page_by_path('holiday')) {
        $url = home_url('/holiday');
    }
    else {
        if ( empty( $_COOKIE['categoryslug'] ) || empty( $_COOKIE['categoryname'] ) ) {
            return $url;
        }

        $categorySlug = sanitize_title( wp_unslash( $_COOKIE['categoryslug'] ) );
        $categoryName = sanitize_text_field( wp_unslash( $_COOKIE['categoryname'] ) );

        $url = add_query_arg(
            array(
                'cat_slug' => $categorySlug,
                'cat_name' => $categoryName,
            ),
            $url
        );
    }

    return $url;
}
add_filter('woocommerce_add_to_cart_redirect', 'redirectToMenuAfterAddToCart');

/**
 * Adds a custom order review template.
 *
 * This function hooks into WooCommerce to add a custom order review template.
 *
 * @param string $template The path of the template to include.
 * @param string $template_name The name of the template.
 * @param string $template_path The path to the template directory.
 * @return string The path of the custom template if it exists, otherwise the original template path.
 */
function addCustomOrderReview($template, $template_name, $template_path)
{
    global $woocommerce;
    $_template = $template;
    if (!$template_path) {
        $template_path = $woocommerce->template_url;
    }

    $plugin_path = untrailingslashit(plugin_dir_path(__DIR__)) . '/woocommerce/';

    $template = locate_template(
        array(
        $template_path . $template_name,
        $template_name
    )
    );

    if (!$template && file_exists($plugin_path . $template_name)) {
        $template = $plugin_path . $template_name;
    }
    if (!$template) {
        $template = $_template;
    }

    return $template;
}

add_filter('woocommerce_locate_template', 'addCustomOrderReview', 1, 3);

if (get_option('cbs_membership_setting', false)) {
    MembershipField::create();
}

add_action('wp', function () {
    if (get_option('time_slot_setting')) {
        setcookie("single_time_slot", get_option('time_slot_setting'), time() + 36000, "/");
    }
    else {
        setcookie("single_time_slot", false, time() + 36000, "/");
    }
});




/**
 * Check if a cart item has tax from WOAPI response
 * 
 * @param array $cart_item Cart item data
 * @param string $cart_item_key Cart item key
 * @return bool True if item has tax, false otherwise
 */
function cbsItemHasTax($cart_item, $cart_item_key): bool
{
    // Get WOAPI order payload from session
    if (!WC()->session) {
        return false;
    }

    $orderPayload = WC()->session->get('orderpayload');
    if (!$orderPayload) {
        return false;
    }

    $orderData = json_decode($orderPayload);
    if (!$orderData || !isset($orderData->OrderItems) || !is_array($orderData->OrderItems)) {
        return false;
    }

    $productId = $cart_item['product_id'];
    $menuItemId = get_post_meta($productId, '_itemid', true);

    if (!$menuItemId) {
        return false;
    }

    foreach ($orderData->OrderItems as $orderItem) {
        if (isset($orderItem->MenuItemId) &&
        $orderItem->MenuItemId === $menuItemId &&
        isset($orderItem->Tax) &&
        $orderItem->Tax > 0) {
            return true;
        }
    }

    return false;
}

/**
 * Add taxable badge to cart item name on checkout if item has tax
 * 
 * @param string $product_name Product name
 * @param array $cart_item Cart item data
 * @param string $cart_item_key Cart item key
 * @return string Modified product name with badge if taxable
 */
function cbsAddTaxableBadge($product_name, $cart_item, $cart_item_key): string
{
    // Only show on checkout page
    if (!is_checkout()) {
        return $product_name;
    }

    if (cbsItemHasTax($cart_item, $cart_item_key)) {
        $product_name .= ' <span class="cbs-taxable-badge">Taxable</span>';
    }

    return $product_name;
}

/**
 * Register taxable badge filter after Carbon Fields is loaded
 * This ensures carbon_get_theme_option() is available
 * 
 * @return void
 */
function cbsRegisterTaxableBadgeFilter(): void
{
    // Only register filter if setting is enabled
    if (function_exists('carbon_get_theme_option') && carbon_get_theme_option('olo_show_taxable_tag')) {
        add_filter('woocommerce_cart_item_name', 'cbsAddTaxableBadge', 20, 3);
    }
}
add_action('carbon_fields_fields_registered', 'cbsRegisterTaxableBadgeFilter');


CartBlock::create();
OrderAtTablePopUp::create();

add_action('wp_ajax_update_cart_block', 'updateCartBlock');
add_action('wp_ajax_nopriv_update_cart_block', 'updateCartBlock');

function updateCartBlock()
{

    global $cartBlockOptionKey;
    global $cacheKey;

    $cacheKey = isset($_POST['cache_key']) ? sanitize_text_field($_POST['cache_key']) : '';

    if (!$cacheKey) {
        wp_send_json_error(['message' => 'Cache key not provided']);
        return;
    }

    $cartBlockOptionKey = isset($_POST['option_key']) ? sanitize_text_field($_POST['option_key']) : '';

    if (!$cartBlockOptionKey) {
        wp_send_json_error(['message' => 'Option key not provided']);
        return;
    }

    $html = apply_filters('cbs_cart_content', '');

    wp_send_json_success(['html' => $html]);
    wp_die();
}

function processAndValidateOrderWithLoyalty(OrderProcess $orderProcess, string $siteId = '')
{
    $orderProcess->processOrderData();

    // Apply loyalty adjustments BEFORE validate so the WOAPI payload reconciles
    // line items + tax + shipping against the reward-reduced total.
    $loyaltyRewards = WC()->session->get('loyaltyData', null);
    if ($loyaltyRewards) {
        $orderProcess->updateCheckWithLoyaltyDiscounts($loyaltyRewards['programs'] ?? []);
    }

    $responseValidate = $orderProcess->validateOrder();

    $ref  = SessionReference::get();
    $repo = SessionEventRepository::create();
    $wcId = isset($orderProcess->order) ? (int) $orderProcess->order->get_id() : null;

    if (!empty($responseValidate) && !empty($responseValidate->Ok)) {
        $repo->logEvent(
            $ref,
            SessionEventRepository::EVENT_ORDER_VALIDATED,
            SessionEventRepository::STATUS_SUCCESS,
            ['check_id' => $responseValidate->Data->CheckId ?? null],
            $wcId,
            $siteId
        );
    } else {
        $repo->logEvent(
            $ref,
            SessionEventRepository::EVENT_VALIDATION_FAILED,
            SessionEventRepository::STATUS_FAILED,
            ['error' => is_object($responseValidate) ? ($responseValidate->ErrorMessage ?? 'validation failed') : 'no response'],
            $wcId,
            $siteId
        );
    }

    return $responseValidate;
}

add_filter('woocommerce_default_address_fields', function ($fields) {
    $fields['country']['default'] = 'US'; // United States
    return $fields;
});


add_filter('woocommerce_package_rates', function ($rates, $package) {
    $no_shipping = isset($_COOKIE['no_shipping']) && sanitize_text_field($_COOKIE['no_shipping']) === '1';
    if ($no_shipping) {
        return []; // removes methods + shipping cost display
    }
    return $rates;
}, 10, 2);

add_filter('woocommerce_cart_needs_shipping', 'noShippingConditionallyVirtualProducts');
add_filter('woocommerce_cart_needs_shipping_address', 'noShippingConditionallyVirtualProducts');

function noShippingConditionallyVirtualProducts($needs)
{

    if (WC()->session && isset($_COOKIE['no_shipping']) && sanitize_text_field($_COOKIE['no_shipping']) === '1') {

        return false;
    }

    return $needs;
}
add_action('wp_head', function () {
    if (!is_checkout())
        return;

    if (isset($_COOKIE['no_shipping']) && sanitize_text_field($_COOKIE['no_shipping']) === '1') {
        echo '<style>
          tr.woocommerce-shipping-totals, #shipping_method, .shipping { display:none !important; }
        </style>';
    }
});

/**
 * Get Area Id based on site id and cookie.
 *
 * @param string $siteid Site ID for lookup.
 * @return string|null Area ID if found, null otherwise.
 */
function getAreaId(string $siteid): ?string
{
    $cachedAreaId = $_COOKIE['areaId'] ?? $_COOKIE['area_id'] ?? null;
    $cachedSite   = $_COOKIE['areaIdSite'] ?? null;

    // Only honor cached areaId if it was cached for the current site.
    if ($cachedAreaId && $cachedSite === $siteid) {
        return sanitize_text_field($cachedAreaId);
    }

    $siteAreaData = (ConfigurationRepository::create())->getAreaId($siteid);

    if (!empty($siteAreaData['areaid'])) {
        $value = sanitize_text_field($siteAreaData['areaid']);
        setcookie('areaId',     $value,  time() + 36000, '/', '', is_ssl(), true);
        setcookie('areaIdSite', $siteid, time() + 36000, '/', '', is_ssl(), true);
        return $value;
    }

    return null;
}

/**
 * Detect whether the active daypart menu differs from the menu the customer
 * was last browsing (the `currentMenu` cookie). Single source of truth for the
 * "menu changed due to time" condition.
 *
 * @return bool True when the menu changed; false when it matches or the site
 *              context cannot be determined.
 */
function oloActiveMenuChanged(): bool
{
    // Memoize per request: cookies and the active menu cannot change within a
    // single request, and this helper is consulted by several hooks (some of
    // which WooCommerce fires more than once), so cache the DB-backed lookup.
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    // With Time Slots enabled, daypart/menu changes for an in-progress order are
    // owned by the slot flow (OE-26492: the watcher migrates the cart and auto-
    // switches to ASAP, and the expired-slot checkout backstop blocks submission).
    // The legacy "menu changed due to time" message + blanket empty-cart is then
    // redundant — and would wrongly wipe the cart the watcher just migrated — so
    // disable this path entirely in timeslot mode.
    if (function_exists('carbon_get_theme_option') && (bool) carbon_get_theme_option('olo_enable_time_slots')) {
        return $cached = false;
    }

    $siteId = $_COOKIE['siteid'] ?? null;
    if (!$siteId) {
        return $cached = false;
    }

    $activeMenu = DaypartMenusRepository::create()->getActiveDaypartMenu(
        $siteId,
        ...oloNavSlotOverrides()
    );

    return $cached = ($activeMenu !== ($_COOKIE['currentMenu'] ?? null));
}

/**
 * Whether the menu-changed message has already been shown for the current
 * change event. Stored in the WooCommerce session so a single notice spans
 * every page (single product, menu items, cart, checkout, my account).
 */
function oloMenuNoticeShown(): bool
{
    return function_exists('WC') && WC()->session
        && WC()->session->get('olo_menu_notice_shown') === '1';
}

/**
 * Mark the menu-changed message as shown so it is not repeated on other pages.
 */
function oloMarkMenuNoticeShown(): void
{
    if (function_exists('WC') && WC()->session) {
        WC()->session->set('olo_menu_notice_shown', '1');
    }
}

/**
 * Re-arm the menu-changed message once the mismatch is resolved, so a future
 * genuine change can show it again ("once per change event").
 */
function oloResetMenuNotice(): void
{
    if (function_exists('WC') && WC()->session) {
        WC()->session->__unset('olo_menu_notice_shown');
    }
}

add_action('woocommerce_before_checkout_form', function () {
    echo '<div class="olo-checkout-banner"></div>';
}, 5);

add_filter('woocommerce_update_order_review_fragments', function (array $fragments) {
    $changed = WC()->session && WC()->session->get('olo_menu_changed') === '1';

    // Suppress the overlay when the menu has not changed, or when the message
    // was already shown elsewhere. Clear any stale overlay left in the DOM.
    if (!$changed || oloMenuNoticeShown()) {
        $fragments['.olo-checkout-banner'] = '<div class="olo-checkout-banner"></div>';
        return $fragments;
    }

    // First time the message surfaces for this change event.
    oloMarkMenuNoticeShown();

    ob_start();
?>
<div class="olo-checkout-banner">

    <div class="olo-checkout-overlay">
        <div class="olo-checkout-message">
            <p>
                <?php echo esc_html__('Menu changed due to time. Please start your order again.', 'olo'); ?>
            </p>

            <button type="button" class="button olo-go-home" data-redirect="<?php echo esc_url(home_url('/')); ?>">
                <?php echo esc_html__('Go to homepage', 'olo'); ?>
            </button>
        </div>
    </div>
</div>
<?php

    $fragments['.olo-checkout-banner'] = ob_get_clean();

    return $fragments;
});

add_action('woocommerce_checkout_update_order_review', function ($post_data) {

    parse_str($post_data, $data);

    if (oloActiveMenuChanged()) {
        WC()->session->set('olo_menu_changed', '1');
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
            WC()->cart->calculate_totals();
        }
    }

});

add_action('woocommerce_cart_updated', function () {
    if (oloActiveMenuChanged()) {
        WC()->session->set('olo_menu_changed', '1');
        if (!oloMenuNoticeShown()) {
            oloMarkMenuNoticeShown();
            wc_add_notice(
                __('Menu changed due to time. Please start your order again.', 'olo'),
                'notice'
            );
        }
    }
});


add_action('wp_loaded', function () {
    if (is_admin() || !function_exists('WC') || !WC()->session)
        return;

    // Nothing armed → skip the (DB-backed) menu lookup and session write on
    // the vast majority of requests where no menu change is in play.
    if (!oloMenuNoticeShown() && WC()->session->get('olo_menu_changed') !== '1') {
        return;
    }

    // Re-arm the once-only notice when the menu mismatch has resolved, so a
    // future genuine change can show the message again ("once per change event").
    if (!oloActiveMenuChanged()) {
        oloResetMenuNotice();
        WC()->session->set('olo_menu_changed', '0');
    }
});

add_action('woocommerce_after_checkout_validation', function ($data, $errors) {
    if (WC()->session && WC()->session->get('taxValidationFailed')) {
        $errors->add(
            'tax_validation',
            __('Unable to calculate taxes for your order. Please refresh the page and try again.', 'olo')
        );
    }
}, 10, 2);

add_action('woocommerce_checkout_process', function () {

    if (oloActiveMenuChanged()) {

        WC()->session->set('olo_menu_changed', '1');

        // Safety block: always empty a stale-menu cart so the order cannot be
        // placed, even when the message text is suppressed as a duplicate.
        if (WC()->cart) {
            WC()->cart->empty_cart();
            WC()->cart->calculate_totals();
        }

        if (!oloMenuNoticeShown()) {
            oloMarkMenuNoticeShown();
            wc_add_notice(
                __('Menu changed due to time. Please start your order again.', 'olo'),
                'error'
            );
        }
    }

});

/**
 * Block checkout when the selected nav-timeslot pickup time has already passed
 * in the site's wall-clock (OE-26492).
 *
 * The client-side daypart watcher normally catches the daypart rollover mid-order
 * and forces a re-pick, but a session where the watcher never ran (JS disabled,
 * backgrounded tab) could still submit an order against an expired slot. The
 * existing oloActiveMenuChanged guard above does NOT see this case: the slot
 * override pins the resolved menu to the (now past) slot's own daypart, so the
 * menu still "matches" and nothing fires.
 *
 * Nav-slot flow only (oloNavTimeslot cookie); the on-page checkout selector is
 * validated by TimeSlotsLoader::validate(). Fails OPEN on any parse/lookup gap so
 * a data edge case can never hard-block an otherwise valid checkout — ECM remains
 * the final backstop. The olo_timeslot_grace_minutes filter absorbs minor skew.
 */
add_action('woocommerce_checkout_process', function () {

    if (empty($_COOKIE['oloNavTimeslot'])) {
        return;
    }

    $siteId   = isset($_COOKIE['siteid'])             ? sanitize_text_field(wp_unslash($_COOKIE['siteid']))             : '';
    $slotDate = isset($_COOKIE['oloNavTimeslotDate']) ? sanitize_text_field(wp_unslash($_COOKIE['oloNavTimeslotDate'])) : '';
    $slotTime = isset($_COOKIE['oloNavTimeslotTime']) ? sanitize_text_field(wp_unslash($_COOKIE['oloNavTimeslotTime'])) : '';

    // Shared expiry check (also used by the daypart watcher endpoint). Returns
    // null on incomplete/malformed input or clock error → fail open (ECM is the
    // final backstop); only an explicit true blocks checkout.
    if (true === \CBSNorthStar\Helpers\SiteClock::slotHasPassed($siteId, $slotDate, $slotTime)) {
        wc_add_notice(
            __('Your selected pickup time has passed. Please select a new time slot.', 'olo'),
            'error'
        );
    }

});

/**
 * Block checkout when the selected location's kitchen is closed (OE-26385).
 *
 * The location modal already gates the Order Online button, but that check is
 * point-in-time at selection: a customer can pick a site while open, build a cart,
 * and reach checkout after closing time — and a stale siteid cookie lets them skip
 * the modal entirely. This server-side gate catches both, on every payment path,
 * before any payment is taken. The cart is NOT emptied (closed is transient — the
 * customer can finish during the next open window).
 *
 * ASAP only: a selected future timeslot is allowed even when closed right now, since
 * the order is fulfilled at the scheduled (open) slot. KitchenHours fails open, so a
 * missing site or unparseable hours never blocks a valid order.
 */
add_action('woocommerce_checkout_process', function () {

    // Never gate a timeslot order on "now": the order is fulfilled at the scheduled
    // (open) slot. Skip when the timeslot feature is enabled at all, or when a slot was
    // selected by either path — the nav popup (oloNavTimeslot cookie) or the on-page
    // checkout selector ($_POST['olo_time_slot']). This mirrors the UI, which hides the
    // closed badge / does not gate the button when timeslots are enabled.
    $timeslotsEnabled = function_exists('carbon_get_theme_option')
        && (bool) carbon_get_theme_option('olo_enable_time_slots');
    if ($timeslotsEnabled || !empty($_COOKIE['oloNavTimeslot']) || !empty($_POST['olo_time_slot'])) {
        return;
    }

    $siteId = isset($_COOKIE['siteid'])
        ? sanitize_text_field(wp_unslash($_COOKIE['siteid']))
        : '';
    if ('' === $siteId) {
        return;
    }

    global $wpdb;
    $repo = new \CBSNorthStar\Repositories\LocationsRepository($wpdb);
    $site = $repo->findBySiteId($siteId, $repo->getLatestConfigId());

    if ($site && !\CBSNorthStar\Helpers\KitchenHours::isOpenNow($site)) {
        wc_add_notice(
            __('This location is currently closed and cannot accept orders at this time. Please place your order during business hours.', 'olo'),
            'error'
        );
    }

});

/**
 * Gate the menu page itself when the selected location's kitchen is closed (OE-26385).
 *
 * The Pick Up modal and Locations page grey out the Order Online button, but the
 * header "Menu" link is a static WP nav item and any direct/bookmarked URL bypasses
 * that UI gate: both navigate straight to the menu (the /menu-items page) or to a
 * single menu item (a WooCommerce product page) using whatever site is in the
 * `siteid` cookie. This redirects those entries back to the Locations page (with a
 * closed flag the page reads to show a toast) so a closed kitchen's menu cannot be
 * browsed or ordered from. The cart/checkout pages are intentionally not gated here —
 * a cart built before closing is preserved and blocked at submit by the checkout gate.
 *
 * ASAP only: timeslot context is never gated (a closed-now kitchen still takes a
 * scheduled future slot). KitchenHours fails open, so a missing site or unparseable
 * hours never blocks. Runs on template_redirect so headers are not yet sent.
 */
add_action('template_redirect', function () {
    $isProduct = function_exists('is_product') && is_product();
    if (is_admin() || (!is_page('menu-items') && !$isProduct)) {
        return;
    }

    $timeslotsEnabled = function_exists('carbon_get_theme_option')
        && (bool) carbon_get_theme_option('olo_enable_time_slots');
    if ($timeslotsEnabled || !empty($_COOKIE['oloNavTimeslot'])) {
        return;
    }

    $siteId = isset($_COOKIE['siteid'])
        ? sanitize_text_field(wp_unslash($_COOKIE['siteid']))
        : '';
    if ('' === $siteId) {
        return; // No site selected: the menu page shows its own "no location" message.
    }

    global $wpdb;
    $repo = new \CBSNorthStar\Repositories\LocationsRepository($wpdb);
    $site = $repo->findBySiteId($siteId, $repo->getLatestConfigId());

    if (!$site || \CBSNorthStar\Helpers\KitchenHours::isOpenNow($site)) {
        return; // Open, or unknown (fail open): allow the menu.
    }

    $locationPage = get_page_by_path('locations');
    $locationsUrl = $locationPage instanceof WP_Post
        ? get_permalink($locationPage->ID)
        : home_url('/locations');

    if ( ! headers_sent() ) {
        header( 'X-CBS-Redirect-Reason: kitchen-closed-gate' );
    }
    wp_safe_redirect(add_query_arg('oloClosed', '1', $locationsUrl));
    exit;
});