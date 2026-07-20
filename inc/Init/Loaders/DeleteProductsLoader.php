<?php

namespace CBSNorthStar\Init\Loaders;

use CBSNorthStar\Logger\CBSLogger;
use CBSNorthStar\Woapi\Connection;

class DeleteProductsLoader implements JavaScriptLoaderContract
{
    private static $instance = null;

    public static function create(): ?DeleteProductsLoader
    {
        if (self::$instance === null) {
            self::$instance = new DeleteProductsLoader();
        }
        return self::$instance;
    }



    public function registerScripts()
    {
        add_action('wp_ajax_load_delete_action_cbs', [$this, 'ajaxHandler']);
        add_action('wp_ajax_nopriv_load_delete_action_cbs', [$this, 'ajaxHandler']);
    }

    private function clearDerivedCartState(): void
    {
        if ( ! WC()->session ) {
            return;
        }

        WC()->session->set('cbsValidateSnapshot', null);
        WC()->session->set('taxValidationFailed', false);
        WC()->session->set('lastValidateError', null);
        WC()->session->set('orderpayload', null);
        WC()->session->set('orderpayloadKiosk', null);
        WC()->session->set('giftCardData', null);
        WC()->session->set('loyaltyData', null);
        WC()->session->set('RewardsData', null);
        WC()->session->set('customerData', null);

        setcookie('TAX', '', time() - 3600, '/', '', is_ssl(), true);
        unset($_COOKIE['TAX']);
    }

    private function clearRemoteCheckIfPresent(): void
    {
        $checkId = isset($_COOKIE['checkid'])
            ? sanitize_text_field(wp_unslash((string) $_COOKIE['checkid']))
            : '';
        $siteId = isset($_COOKIE['siteid'])
            ? sanitize_text_field(wp_unslash((string) $_COOKIE['siteid']))
            : '';

        if ( '' === trim($checkId) || '' === trim($siteId) ) {
            return;
        }

        try {
            $responseDelete = (new Connection())->deleteData(
                rawurlencode($siteId),
                '/checks/' . rawurlencode($checkId),
                'Token'
            );

            if ( ! empty($responseDelete->Ok) ) {
                setcookie('checkid', '', time() - 3600, '/', '', is_ssl(), true);
                setcookie('checknumber', '', time() - 3600, '/', '', is_ssl(), true);
                unset($_COOKIE['checkid'], $_COOKIE['checknumber']);
            }
        } catch (\Throwable $e) {
            CBSLogger::orders()->error('Delete order exception from ajax cart delete', [
                'checkId' => $checkId,
                'siteId' => $siteId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function ajaxHandler(): void
    {
        if (isset($_POST['action']) && $_POST['action'] === 'load_delete_action_cbs') {
            if ( ! check_ajax_referer('ajax-login-nonce', 'nonce', false) ) {
                wp_send_json_error('Security check failed.', 403);
                wp_die();
            }

            if ( ! isset($_POST['cart_item_key']) ) {
                wp_send_json_error('No cart item key provided.');
                wp_die();
            }

            if ( is_null( WC()->cart ) ) {
                WC()->frontend_includes();
                wc_load_cart();
            }

            if ( ! WC()->session ) {
                WC()->session = new \WC_Session_Handler();
                WC()->session->init();
            }

            // Serialize this delete against any other cart-mutation request for the same
            // session so one save_data() cannot blind-overwrite another request's removal
            // — the OE-26589 "deleted item returns on refresh" race. Same per-session lock
            // the add-to-cart batch endpoint uses.
            $sessionId = (string) WC()->session->get_customer_id();
            if ( '' === $sessionId || ! cbsAcquireCartSessionLock($sessionId) ) {
                wp_send_json_error(array('error' => true, 'retryable' => true, 'message' => 'Please try again.'));
                wp_die();
            }

            // wp_send_json_*() calls wp_die() (exit), which would skip a finally — so
            // compute the response while holding the lock, release it in finally, and only
            // then emit the response. Mirrors AddToCartLoader::ajaxHandler().
            $result = null;
            $cartEmptied = false;

            try {
                // A request that had to wait on the lock still holds the cart hydrated at
                // wp_loaded; re-read the committed session so we mutate current state.
                cbsRehydrateCartFromSession($sessionId);

                $cart_item_key = sanitize_text_field(wp_unslash($_POST['cart_item_key']));
                $removed = WC()->cart->remove_cart_item($cart_item_key);

                if ($removed) {
                    WC()->cart->calculate_totals();
                    WC()->cart->set_session();
                    WC()->cart->maybe_set_cart_cookies();

                    // Keep cart totals/shipping UI in sync after delete without
                    // relying on a second fragments request.
                    //
                    // Guard with TWO buffers:
                    // 1) inner render buffer for collaterals HTML
                    // 2) outer guard buffer to catch accidental flushes from hooked
                    //    callbacks (e.g. ob_end_flush()), so leaked output is
                    //    discarded before wp_send_json_success.
                    $collateralsHtml = '';
                    $collateralsRendered = false;
                    $guardLeakOutput = '';

                    $bufferLevelBeforeGuard = ob_get_level();
                    $guardBufferLevel = $bufferLevelBeforeGuard + 1;
                    ob_start();

                    $bufferLevelBeforeRender = ob_get_level();
                    $renderBufferLevel = $bufferLevelBeforeRender + 1;
                    add_filter( 'woocommerce_is_cart', '__return_true' );
                    ob_start();
                    try {
                        do_action( 'woocommerce_cart_collaterals' );

                        // Some hooked callbacks may open nested buffers and fail
                        // to close them; unwind extras so we can safely consume
                        // only this render scope output.
                        while ( ob_get_level() > $renderBufferLevel ) {
                            ob_end_clean();
                        }

                        if ( ob_get_level() === $renderBufferLevel ) {
                            $collateralsHtml = '<div class="cart-collaterals">' . ob_get_clean() . '</div>';
                            $collateralsRendered = true;
                        }
                    } catch (\Throwable $e) {
                        CBSLogger::cart()->error('Failed rendering cart collaterals during delete AJAX', [
                            'cart_item_key' => $cart_item_key,
                            'message' => $e->getMessage(),
                        ]);
                    } finally {
                        while ( ob_get_level() > $bufferLevelBeforeRender ) {
                            ob_end_clean();
                        }
                        remove_filter( 'woocommerce_is_cart', '__return_true' );

                        // Discard any direct output leaked into the guard scope.
                        while ( ob_get_level() > $guardBufferLevel ) {
                            ob_end_clean();
                        }
                        if ( ob_get_level() === $guardBufferLevel ) {
                            $guardLeakOutput = (string) ob_get_clean();
                        }
                    }

                    if ( '' !== trim( $guardLeakOutput ) ) {
                        CBSLogger::cart()->warning('Discarded leaked collaterals output during delete AJAX', [
                            'cart_item_key' => $cart_item_key,
                            'bytes' => strlen( $guardLeakOutput ),
                        ]);
                    }

                    $count = (int) WC()->cart->get_cart_contents_count();

                    if (0 === $count) {
                        $cartEmptied = true;
                        $this->clearDerivedCartState();
                    }

                    if (WC()->session) {
                        WC()->session->save_data();
                    }

                    $result = array(
                        'success' => true,
                        'data' => array(
                            'message' => 'Item removed successfully',
                            'cart_key' => $cart_item_key,
                            'total' => WC()->cart->get_total(),
                            // Grand total incl. TAX/fees for the sticky footer (#olo-cart-footer
                            // .price). The client paints the footer from cartTotalHtml and only
                            // falls back to cartTotal (products subtotal, no fees) when it is
                            // absent — omitting this key showed the footer without tax after a
                            // delete while the collaterals table showed the correct total.
                            // Same key/semantic the quantity_change handler returns.
                            'cartTotalHtml' => WC()->cart->get_total(),
                            'count' => $count,
                            'taxValidationFailed' => (WC()->session ? (bool) WC()->session->get('taxValidationFailed') : false),
                            'cartTotal' => WC()->cart->get_cart_total(),
                            'collateralsHtml' => $collateralsHtml,
                            'cartCount' => $count,
                            'cartEmpty' => WC()->cart->is_empty(),
                        ),
                    );
                } else {
                    $result = array('success' => false, 'data' => 'Item could not be removed.');
                }
            } finally {
                cbsReleaseCartSessionLock($sessionId);
            }

            // Cancelling the remote check touches cookies + WOAPI only, not the WC session,
            // so run it after releasing the lock to avoid holding it across a round-trip.
            if ($cartEmptied) {
                $this->clearRemoteCheckIfPresent();
            }

            if ($result['success']) {
                wp_send_json_success($result['data']);
            } else {
                wp_send_json_error($result['data']);
            }
            wp_die();
        }
    }
}