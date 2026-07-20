<?php
namespace CBSNorthStar\Controllers;

use CBSNorthStar\Repositories\ConfigurationRepository;
use CBSNorthStar\Models\ProductDetail;
use CBSNorthStar\Woapi\Connection;
use CBSNorthStar\Models\Sites;
use CBSNorthStar\Services\TimeSlotService;
use CBSNorthStar\Logger\CBSLogger;
use CBSNorthStar\Helpers\TimeSlotReservationSession;
use CBSNorthStar\Helpers\KitchenHours;
use CBSNorthStar\Helpers\SiteScope;
use CBSNorthStar\Helpers\TimeSlotAreaResolver;
use CBSNorthStar\Helpers\MenuScope;
use CBSNorthStar\Helpers\ProductScope;
use CBSNorthStar\Helpers\MenuRenderCache;
use CBSNorthStar\Helpers\MenuItemActiveWindow;
use WP_Query;
use WP_REST_Response;

class MainController {

    /** Products per page for the /loadmore infinity-scroll endpoint (OE-26548). */
    private const LOADMORE_PER_PAGE = 12;

    private function getRestNonceFromRequest(\WP_REST_Request $request): string {
        return (string) ( $request->get_header( 'X-WP-Nonce' ) ?: $request->get_param( '_wpnonce' ) );
    }

    private function validateRestNonce(\WP_REST_Request $request): ?\WP_REST_Response {
        $nonce = $this->getRestNonceFromRequest($request);
        if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'message' => __( 'Security check failed.', 'olo' ) ],
                403
            );
        }

        return null;
    }

    public function registerRoutes() {
        $baseUrl = 'northstaronlineordering/v1';
        register_rest_route($baseUrl, '/sites', array(
            'methods' => 'GET',
            'callback' => [$this, 'getSitesActive'],
        ));
        register_rest_route($baseUrl, '/products', array(
            'methods' => 'GET',
            'callback' => [$this, 'showProducts'],
        ));
        register_rest_route($baseUrl, '/deleteItem', array(
            'methods' => 'POST',
            'callback' => [$this, 'deleteItem'],
        ));
        register_rest_route($baseUrl, '/product', array(
            'methods' => 'GET',
            'callback' => [$this, 'showDetails'],
        ));
        register_rest_route($baseUrl, '/cart', array(
            'methods' => 'POST',
            'callback' => [$this, 'addToCart'],
        ));
        register_rest_route($baseUrl, '/pages', array(
            'methods' => 'GET',
            'callback' => [$this, 'getPages'],
        ));
        register_rest_route($baseUrl, '/validate-pin/(?P<pin>[a-zA-Z0-9]+)', array(
            'methods' => 'GET',
            'callback' => [$this, 'validatePin'],
        ));
        register_rest_route($baseUrl, '/cart', array(
            'methods' => 'DELETE',
            'callback' => [$this, 'resetCart'],
        ));
        register_rest_route($baseUrl, '/areas', array(
            'methods' => 'GET',
            'callback' => [$this, 'getAreas'],
        ));
        register_rest_route($baseUrl, '/productsLoop', array(
            'methods' => 'GET',
            'callback' => [$this, 'getProductsHTML'],
            'args'     => [
                'site_id' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'required' => false],
            ],
        ));
        register_rest_route($baseUrl, '/searchProducts', array(
            'methods' => 'GET',
            'callback' => [$this, 'getProductsHTMLSearch'],
            'args'     => [
                'site_id' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'required' => false],
            ],
        ));
        register_rest_route($baseUrl, '/getTotal', array(
            'methods' => 'GET',
            'callback' => [$this, 'getCartTotal'],
        ));
        register_rest_route($baseUrl, '/loadmore', array(
            'methods' => 'GET',
            'callback' => [$this, 'loadMoreProducts'],
            'permission_callback' => '__return_true',
            'args'     => [
                'site_id' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'required' => false],
            ],
        ));
        register_rest_route($baseUrl, '/daypart-menu', array(
            'methods'             => 'GET',
            'callback'            => [$this, 'getDaypartMenuForSlot'],
            'permission_callback' => '__return_true',
            'args'                => [
                'siteId' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'required' => true],
                'date'   => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'required' => true],
                'time'   => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'required' => true],
            ],
        ));
        // OE-26492: current daypart menu by site wall-clock (no slot override), so the
        // client can detect a daypart boundary crossing for an in-progress order.
        register_rest_route($baseUrl, '/active-daypart-menu', array(
            'methods'             => 'GET',
            'callback'            => [$this, 'getActiveDaypartMenuNow'],
            'permission_callback' => '__return_true',
            'args'                => [
                'siteId'   => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'required' => true],
                // Optional selected-slot params: when supplied the response reports
                // whether that slot has already passed, so the watcher only migrates
                // an expired order (not a legitimate future slot in another daypart).
                'slotDate' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'required' => false],
                'slotTime' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'required' => false],
            ],
        ));
        // OE-26492: inspect the cart against a new daypart menu without mutating it.
        register_rest_route($baseUrl, '/cart/menu-transition', array(
            'methods'             => 'GET',
            'callback'            => [$this, 'previewMenuTransition'],
            'permission_callback' => '__return_true',
            'args'                => [
                'siteId'    => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'required' => true],
                'newMenuId' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'required' => true],
            ],
        ));
        // OE-26492: apply the daypart transition (reprice survivors, remove missing).
        register_rest_route($baseUrl, '/cart/menu-transition', array(
            'methods'             => 'POST',
            'callback'            => [$this, 'applyMenuTransition'],
            'permission_callback' => '__return_true',
            'args'                => [
                'siteId'    => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'required' => true],
                'newMenuId' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'required' => true],
            ],
        ));
        register_rest_route($baseUrl, '/search', array(
            'methods' => 'GET',
            'callback' => [$this, 'searchLocations'],
            'permission_callback' => '__return_true',
            'args'                => [
						'keyword' => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'required'          => false,
						],
						'radius'  => [
							'type'              => 'number',
							'sanitize_callback' => [ $this, 'sanitizeRadius' ],
							'required'          => false,
						],
						'lat'     => [
							'type'     => 'number',
							'required' => false,
						],
						'lng'     => [
							'type'     => 'number',
							'required' => false,
						],
					],
        ));

        register_rest_route( $baseUrl, '/timeslots', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'oloRestTimeslotsByDate' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $baseUrl, '/timeslots/reserve', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'releaseTimeslotReservation' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $baseUrl, '/timeslots/reserve', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'reserveTimeSlot' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'date'        => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static function ( $v ) {
                        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v );
                    },
                ],
                'timeSlotId'  => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static function ( $v ) {
                        return (bool) preg_match(
                            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                            $v
                        );
                    },
                ],
                'slotTime'    => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'siteId'      => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

    }

    public function getAreas() {
        $configuration = ConfigurationRepository::create();
        $token = ($configuration->getDetails())->token;
        $instance = ($configuration->getDetails())->instance;
        $instanceUrl = $configuration->getInstance($instance)->instance_oeapiurl;
        $site = $configuration->getDetails();
        $siteDetails = $configuration->getSiteDetails($site->id);
        $siteId = $siteDetails[0]->siteid;
        $url = '/areas';
        $connection = new Connection();

        $areas = (new Sites())->requestSiteAreaDayParts($siteId , $token , $instanceUrl);


        return rest_ensure_response($areas);
    }


    public function getCartTotal( \WP_REST_Request $req ) {
    if ( ! class_exists('WooCommerce') ) {
        return new \WP_Error('no_wc', 'WooCommerce not found', ['status' => 500]);
    }


    if ( function_exists( 'wc_load_cart' ) ) {
        wc_load_cart(); 
    }


    if ( method_exists( WC(), 'initialize_session' ) && ! WC()->session ) {
        WC()->initialize_session();
    }

    if ( ! WC()->cart ) {
        return rest_ensure_response(['cartTotal' => '$0.00', 'cartCount' => 0]);
    }


    WC()->cart->calculate_totals();

    return rest_ensure_response([
        'cartTotal' => WC()->cart->get_total(),        
        'cartCount' => WC()->cart->get_cart_contents_count(), 
    ]);
}

    private function clearDerivedCartState(): void {
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

    private function clearRemoteCheckIfPresent(): void {
        $checkId = isset($_COOKIE['checkid'])
            ? sanitize_text_field(wp_unslash((string) $_COOKIE['checkid']))
            : '';
        $siteId = isset($_COOKIE['siteid'])
            ? sanitize_text_field(wp_unslash((string) $_COOKIE['siteid']))
            : '';

        if ( '' === trim($checkId) || '' === trim($siteId) ) {
            return;
        }

        CBSLogger::orders()->info('Deleting order', ['checkId' => $checkId]);

        try {
            $url = '/checks/' . rawurlencode($checkId);
            $responseDelete = (new Connection())->deleteData(rawurlencode($siteId), $url, 'Token');

            if ( ! empty($responseDelete->Ok) ) {
                CBSLogger::orders()->debug('Delete order response', ['response' => $responseDelete]);
                setcookie('checkid', '', time() - 3600, '/', '', is_ssl(), true);
                setcookie('checknumber', '', time() - 3600, '/', '', is_ssl(), true);
                unset($_COOKIE['checkid'], $_COOKIE['checknumber']);
            }
        } catch (\Throwable $e) {
            CBSLogger::orders()->error('Delete order exception', [
                'checkId' => $checkId,
                'siteId' => $siteId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function buildCartStateResponse(): array {
        if ( ! WC()->cart ) {
            return [
                'success' => true,
                'cartTotal' => '$0.00',
                'cartCount' => 0,
                'cartEmpty' => true,
            ];
        }

        return [
            'success' => true,
            'cartTotal' => WC()->cart->get_cart_total(),
            'cartCount' => WC()->cart->get_cart_contents_count(),
            'cartEmpty' => WC()->cart->is_empty(),
        ];
    }

    public function resetCart(\WP_REST_Request $request) {
        $nonceError = $this->validateRestNonce($request);
        if ( null !== $nonceError ) {
            return $nonceError;
        }

        if ( is_null( WC()->cart ) ) {
            WC()->frontend_includes();
            wc_load_cart();
        }
        if ( ! WC()->session ) {
            WC()->session = new \WC_Session_Handler();
            WC()->session->init();
        }
        $cart = WC()->cart;
        $cart->empty_cart();
        $cart->calculate_totals();
        $this->clearDerivedCartState();
        $this->clearRemoteCheckIfPresent();

        return rest_ensure_response($this->buildCartStateResponse());
    }

    /**
     * Release the PHP session file lock (OE-26548). `start_session()` (priority 1
     * on `init`, wp_cbs_shortcode.php) opens a session on every request including
     * REST, and PHP's default session handler holds an exclusive file lock for
     * the life of the request — serializing concurrent menu REST reads that
     * would otherwise run in parallel (e.g. a sticky-bar click fanning out N
     * `/loadmore` requests). `$_SESSION` is only written on the checkout/payment
     * paths (Woapi/Payment.php, cart/checkout-gated webhook), never in the
     * read-only handlers this is called from, so making it read-only here is safe.
     */
    private function releaseSessionLock(): void {
        if ( session_id() ) {
            session_write_close();
        }
    }

    /**
     * Return the daypart menu ID that matches a given date + time for a site.
     * Used by the time-slot popup to detect menu changes before navigating.
     *
     * GET /wp-json/northstaronlineordering/v1/daypart-menu?siteId=&date=YYYY-MM-DD&time=HH:MM
     */
    public function getDaypartMenuForSlot(\WP_REST_Request $request) {
        $this->releaseSessionLock();
        $siteId = $request->get_param('siteId');
        $date   = $request->get_param('date');    // YYYY-MM-DD
        $time   = $request->get_param('time');    // e.g. "14:00" or "2026-03-26T14:00:00+00:00"

        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt) {
            return rest_ensure_response(['menuId' => null]);
        }
        $overrideDay = $dt->format('l');

        // The CBS available-slots API returns slotTime as the site's local wall-clock
        // time tagged with a +00:00 offset (e.g. "2026-06-15T09:18:00+00:00" is 09:18
        // local, not 09:18 UTC), and the slot picker displays that literal wall-clock.
        // date_parse reads the literal HH:MM:SS and ignores the offset so the resolver
        // matches the time the user saw; running the string through a timezone would
        // shift it and select the wrong menu.
        $parsed = date_parse( $time );
        if ( ! $parsed || $parsed['error_count'] > 0 ) {
            return rest_ensure_response( ['menuId' => null] );
        }
        $overrideTime = sprintf( '%02d:%02d:%02d', $parsed['hour'], $parsed['minute'], $parsed['second'] );

        $repo   = \CBSNorthStar\Repositories\DaypartMenusRepository::create();
        $menuId = $repo->getActiveDaypartMenu($siteId, $overrideTime, $overrideDay);

        return rest_ensure_response(['menuId' => $menuId]);
    }

    /**
     * Current daypart menu for a site by site wall-clock — NO slot override — plus
     * when the active daypart window ends. The slot popup pins the rendered menu to
     * the selected slot's daypart; this endpoint reports the genuine current daypart
     * so the client can detect a boundary crossing for an in-progress order (OE-26492).
     *
     * GET /wp-json/northstaronlineordering/v1/active-daypart-menu?siteId=&slotDate=&slotTime=
     */
    public function getActiveDaypartMenuNow(\WP_REST_Request $request) {
        $this->releaseSessionLock();
        $siteId = $request->get_param('siteId');

        // Whether the caller's selected slot has already passed (site clock). The
        // watcher gates on this so it only migrates an expired order — a legitimate
        // future slot in a different daypart must NOT be force-switched to ASAP.
        // null when slot params are absent/malformed → the client leaves the cart be.
        $slotExpired = \CBSNorthStar\Helpers\SiteClock::slotHasPassed(
            (string) $siteId,
            (string) $request->get_param('slotDate'),
            (string) $request->get_param('slotTime')
        );

        $row = \CBSNorthStar\Repositories\DaypartMenusRepository::create()
            ->getActiveDaypartRow($siteId, null, null);

        if (null === $row) {
            return rest_ensure_response(['menuId' => null, 'endsAt' => null, 'slotExpired' => $slotExpired]);
        }

        return rest_ensure_response([
            'menuId'      => (string) $row->menuid,
            'endsAt'      => isset($row->endtime) ? (string) $row->endtime : null,
            'slotExpired' => $slotExpired,
        ]);
    }

    /**
     * Preview the daypart cart transition WITHOUT mutating the cart (OE-26492).
     * Returns { survivors:[{key,name}], missing:[{key,name}] }.
     *
     * GET /wp-json/northstaronlineordering/v1/cart/menu-transition?siteId=&newMenuId=
     */
    public function previewMenuTransition(\WP_REST_Request $request) {
        $this->releaseSessionLock();
        if (is_null(WC()->cart)) {
            WC()->frontend_includes();
            wc_load_cart();
        }

        $result = (new \CBSNorthStar\Services\CartMenuTransitionService())->preview(
            (string) $request->get_param('siteId'),
            (string) $request->get_param('newMenuId')
        );

        return rest_ensure_response($result);
    }

    /**
     * Apply the daypart cart transition: reprice surviving lines to the new menu and
     * remove lines absent from it, then re-pin the session to the new daypart by
     * setting the currentMenu cookie and clearing the now-expired nav-timeslot cookies
     * so the client can force a fresh slot selection (OE-26492).
     *
     * The area cookie (oloNavAreaId) is intentionally preserved — the serving area is
     * site-scoped, not daypart-scoped, so the forced re-pick reuses it.
     *
     * POST /wp-json/northstaronlineordering/v1/cart/menu-transition  body: siteId, newMenuId
     */
    public function applyMenuTransition(\WP_REST_Request $request) {
        // Nonce check — this route mutates the cart (reprice/remove lines), so it
        // requires the per-session WP REST nonce like the other mutating cart
        // routes (reserveTimeSlot / releaseTimeslotReservation). The client sends
        // it via the X-WP-Nonce header.
        $nonce = (string) ( $request->get_header( 'X-WP-Nonce' ) ?: $request->get_param( '_wpnonce' ) );
        if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => __( 'Security check failed.', 'olo' ) ],
                403
            );
        }

        $siteId    = (string) $request->get_param('siteId');
        $newMenuId = (string) $request->get_param('newMenuId');

        // Server-side menu validation. The nonce stops CSRF, but a same-session
        // tampered POST could still pass an arbitrary newMenuId and reprice the
        // cart to a different (e.g. cheaper) daypart's menu. Resolve the genuine
        // current daypart from the site clock and reject anything that does not
        // match it, so the migration can only ever target the real active menu.
        $activeRow   = \CBSNorthStar\Repositories\DaypartMenusRepository::create()
            ->getActiveDaypartRow($siteId, null, null);
        $activeMenu  = $activeRow ? (string) $activeRow->menuid : '';
        if ('' === $activeMenu || $newMenuId !== $activeMenu) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => __( 'The requested menu is not the current active daypart.', 'olo' ) ],
                409
            );
        }

        if (is_null(WC()->cart)) {
            WC()->frontend_includes();
            wc_load_cart();
        }

        // apply() always succeeds (it mutates the cart in place and returns the
        // survivors/missing breakdown — never a failure payload), so the cookie
        // pinning below is deliberately decoupled from $result. The new menu is
        // pinned even when every item was missing and the cart is now empty: the
        // forced ASAP re-pick must still land in the new daypart, and pinning stops
        // the legacy olo_clear_cart full-wipe path from firing on the next load.
        $result = (new \CBSNorthStar\Services\CartMenuTransitionService())->apply($siteId, $newMenuId);

        if (!headers_sent() && '' !== $newMenuId) {
            // Re-pin to the new daypart. JS reads this cookie (getCookie), so it must
            // stay non-HttpOnly; mark it Secure on HTTPS so it is not sent in clear.
            setcookie('currentMenu', $newMenuId, time() + 86400, '/', '', is_ssl(), false);
            // Drop the expired slot AND its reservation handle so no ghost ECM
            // reservation lingers (the client reserves a fresh slot via auto-ASAP).
            setcookie('oloNavTimeslot',        '', time() - 3600, '/', '', is_ssl(), false);
            setcookie('oloNavTimeslotId',      '', time() - 3600, '/', '', is_ssl(), false);
            setcookie('oloNavTimeslotTime',    '', time() - 3600, '/', '', is_ssl(), false);
            setcookie('oloNavTimeslotDate',    '', time() - 3600, '/', '', is_ssl(), false);
            setcookie('oloNavTimeslotOrderId', '', time() - 3600, '/', '', is_ssl(), false);
            setcookie('oloNavTimeslotSiteId',  '', time() - 3600, '/', '', is_ssl(), false);
        }

        return rest_ensure_response($result);
    }

    public function addToCart($request) {
        $productId = absint($request['product_id']);
        $quantity = absint($request['quantity']);
        $variationId = isset($request['variation_id']) ? absint($request['variation_id']) : 0;
        $cartItemKey = isset($request['cart_item_key']) ? sanitize_text_field($request['cart_item_key']) : null;

        if ( is_null( WC()->cart ) ) {
            WC()->frontend_includes();
            wc_load_cart();
        }

        $cart = WC()->cart;
        $added = $cart->add_to_cart($productId, $quantity, $variationId);

        if ( ! $added ) {
            return new \WP_Error(
                'add_to_cart_failed',
                __( 'Could not add the product to the cart.', 'northstaronlineordering' ),
                [ 'status' => 400 ]
            );
        }

        // Validate the new item BEFORE removing the edited one, so a failed edit
        // keeps the original item and any pre-existing items in the cart (OE-25549).
        $validation = cbsValidateAddedItem($added);
        if ( ! $validation['ok'] ) {
            return new \WP_Error(
                'validate_failed',
                $validation['message'],
                [ 'status' => 409 ]
            );
        }

        if ( $cartItemKey ) {
            $cart->remove_cart_item($cartItemKey);
        }

        do_action('woocommerce_ajax_added_to_cart', $productId);
        wc_add_to_cart_message(array($productId => $quantity), true);
        return rest_ensure_response($cart->get_cart());
    }

    public function showProducts() {
        $products = wc_get_products(array(
            'status' => 'publish',
            'limit' => -1
        ));
    
        $productsArray = array();
        foreach ($products as $product) {
            $productsArray[] = array(
                'id' => $product->get_id(),
                'name' => $product->get_name(),
            );
        }


        return rest_ensure_response($productsArray);
    }
    public function deleteItem($request) {
        $nonceError = $this->validateRestNonce($request);
        if ( null !== $nonceError ) {
            return $nonceError;
        }

        $cartItemKey = sanitize_text_field($request['post_id']); 

        // Ensure WooCommerce cart is initialized
        if ( is_null( WC()->cart ) ) {
            WC()->frontend_includes();
            wc_load_cart();
        }
    
        // Get cart instance
        $cart = WC()->cart;
    
        // Check if the item exists in the cart
        if ( isset($cart->cart_contents[$cartItemKey]) ) {
            // Remove item
            $cart->remove_cart_item($cartItemKey);
            $cart->calculate_totals();

            if ( 0 === (int) $cart->get_cart_contents_count() ) {
                $this->clearDerivedCartState();
                $this->clearRemoteCheckIfPresent();
            }
    
            $response = $this->buildCartStateResponse();
    
            return rest_ensure_response([
                'success' => $response['success'],
                'message' => 'Item removed successfully',
                'cartItemKey' => $cartItemKey,
                'cartTotal' => $response['cartTotal'],
                'cartCount' => $response['cartCount'],
                'cartEmpty' => $response['cartEmpty'],
            ]);
        }
    
        return rest_ensure_response([
            'success' => false,
            'message' => 'Item not found in cart',
            'cartItemKey' => $cartItemKey
        ]);
    }

    public function showDetails($request) {
        $slug = $request['slug'] ?? null;
        $id = $request['product_id'] ?? null;
        if(!$slug && !$id) {
            return rest_ensure_response('No product found');
        }
        $productId = $slug ?get_page_by_path($slug, OBJECT, 'product')->ID : $id;
        $product = wc_get_product($productId);
        //generate product for Kiosk 
        $details = new ProductDetail($product);
        return rest_ensure_response($details);
    }

    public function getSitesActive() {
        $configuration = ConfigurationRepository::create();
        $site = $configuration->getDetails();
        $id = $site->id;
        $siteDetails = $configuration->getSiteDetails($id);

        return rest_ensure_response($siteDetails);
    }

    public function getPages() {
        $pages = get_pages(['post_status' => 'publish']);
        $response = array();
        foreach ($pages as $page) {
            $response[] = array(
                'slug' => $page->post_name,
                'title' => $page->post_title,
            );
        }
        return rest_ensure_response($response);
    }

    public function validatePin($request) {
        $pin = $request->get_param('pin');
        $configuration = ConfigurationRepository::create();
        $site = $configuration->getDetails();
        $siteDetails = $configuration->getSiteDetails($site->id);
        $siteId = $_COOKIE['siteid'] ?? $siteDetails[0]->siteid;
        $url = '/devices/register/'.$pin;
        $connection = new Connection();

        try {
            $response   = $connection->getData($siteId, $url, 'Token');
            CBSLogger::orders()->info('Device pin response', ['response' => $response]);
        } catch(\Exception $e) {
            CBSLogger::orders()->error('Device pin validation exception', ['message' => $e->getMessage()]);
        }

        if($response) {
            $data = $response->Data;
            setcookie('kioskcbs_registrationId', $data->RegistrationId, time() + (86400 * 30), '/');
            setcookie('kioskcbs_deviceId', $data->DeviceId, time() + (86400 * 30), '/');
        }
        else{
            $data['Error'] = 'No response';
        }

        return rest_ensure_response($data);
    }

    public function getProductsHTML($request) {
        $this->releaseSessionLock();
        $encodedCategories = $request['cat_slug'];
        $decodedCategories = urldecode($encodedCategories);
        $categories = explode(',', stripslashes($decodedCategories));

        $parent_cat = $request['parent_cat_slug'] ?? null;
        $page = $request['page'];
        $paged = $page ? absint($page) : 1;

        if( ! function_exists('wc_get_products') ){ return[];}

        // Resolve the active site, failing closed: if it cannot be determined we
        // return no products rather than leaking every site's items onto a menu
        // that only shares category names with the active site (OE-26387).
        $activeSiteId = SiteScope::resolveActiveSiteId( $request );
        if ( '' === $activeSiteId ) {
            CBSLogger::products()->debug(
                'getProductsHTML: active site could not be resolved — returning empty product list'
            );
            return rest_ensure_response([
                'html'          => '',
                'category'      => $categories,
                'total'         => 0,
                'totalPages'    => 0,
                'currentPage'   => $paged,
                'parentTerm'    => '',
                'categoryNames' => array_map(static function() { return ''; }, $categories),
            ]);
        }

        // Resolve the active daypart menu so products are scoped to it as well
        // as the site. Menu membership was previously enforced only at the
        // category level, so items in a category whose product_cat term is
        // shared across menus leaked between menus (OE-26399). When no menu can
        // be resolved this is '' and the menu clause below matches nothing.
        $activeMenuId = MenuScope::resolveActiveMenuId( $activeSiteId );
        $catalogCacheVersion = (string) get_option( 'cbs_catalog_cache_version', '0' );

        // Computed once per request, not once per category: cacheTtl() runs an
        // uncached boundary query (MenuItemActiveWindow::secondsUntilNextSiteBoundary())
        // every call, and $activeSiteId doesn't change across the loop below, so
        // calling it per-category would repeat the same DB query needlessly.
        $activeWindowCacheTtl = MenuItemActiveWindow::cacheTtl( $activeSiteId, HOUR_IN_SECONDS );

        // Resolve the catalog ordering before building the cache key. Ordering is
        // per-session (WC session / query var) while the object cache is shared
        // across sessions, so it must be part of the key or one session's sort
        // selection can serve stale HTML to another.
        $ordering = WC()->query->get_catalog_ordering_args();
        $orderbyParts = explode(' ', $ordering['orderby']);
        $ordering['orderby'] = array_shift($orderbyParts);
        $ordering['orderby'] = stristr($ordering['orderby'], 'price') ? 'meta_value_num' : $ordering['orderby'];

        // Include site, menu and ordering in the cache key so a persistent object
        // cache (Redis/Memcached) never serves one site's, menu's or sort order's
        // products to another.
        $cache_key = 'products_html_' . md5(
            $encodedCategories . $parent_cat . $paged . $activeSiteId . $activeMenuId
            . $ordering['orderby'] . $ordering['order'] . $catalogCacheVersion
        );
        $cached_results = get_transient($cache_key);

        if (false !== $cached_results) {
            return rest_ensure_response($cached_results);
        }
        $productsPerPage  = -1;
        $allProducts= [];
        $totalProducts= 0;
        $maxNumPages= 0;

        $termObjects = [];
        foreach ($categories as $category) {
            $termObjects[$category] = get_term_by('slug', $category, 'product_cat');
        }
        $parentTerm = $parent_cat ? get_term_by('slug', $parent_cat, 'product_cat') : null;

        foreach ($categories as $category) {
            $args = array(
                'status' => 'publish',
                'visibility' => 'visible',
                'limit' => $productsPerPage,
                'page' => $paged,
                'paginate' => true,
                'category' => $category,
                'return' => 'ids',
                'orderby' => $ordering['orderby'],
                'order' => $ordering['order'],
                'stock_status' => 'instock',
                'tax_query' => array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field' => 'slug',
                        'terms' => $category,
                        'operator' => 'IN',
                        'include_children' => false
                    ),
                ),
            );

            // Filter to products belonging to BOTH the active site and the
            // active menu. $activeSiteId is guaranteed non-empty here
            // (fail-closed above); the menu clause fails closed to a never-match
            // sentinel when $activeMenuId is '', so a category shared across
            // menus never leaks another menu's items (OE-26399 / OE-26387).
            $args['meta_query'] = ProductScope::metaQuery( $activeSiteId, $activeMenuId );

            // Key on the stable inputs only — NOT json_encode($args), which would
            // embed the active-date-window clause's live "now" (MenuItemActiveWindow::
            // metaQueryClause()) and make this key different on every single request,
            // silently defeating the cache entirely (always a miss). Correctness for
            // the date window instead comes from capping the TTL below.
            $transient_key = 'wc_products_' . md5(
                $category . $activeSiteId . $activeMenuId . $ordering['orderby'] . $ordering['order'] . $catalogCacheVersion
            );
            $customProducts = get_transient($transient_key);

            if (false === $customProducts) {
                $customProducts = wc_get_products($args);
                // Capped so this cache can never outlive an item's next active-date-window
                // start/stop crossing — see MenuItemActiveWindow::cacheTtl().
                set_transient(
                    $transient_key,
                    $customProducts,
                    $activeWindowCacheTtl
                );
            }
            $totalProducts += $customProducts->total;
            $maxNumPages += max($maxNumPages, $customProducts->max_num_pages);
            $allProducts[] = $this->renderCategoryProducts($request,$category,$customProducts,$termObjects);

        }

        $response['html'] = implode('', $allProducts);
        $response['category'] = $categories;
        $response['total'] = $totalProducts;
        $response['totalPages'] = $maxNumPages;
        $response['currentPage'] = $paged;
        $response['parentTerm'] = $parentTerm ? $parentTerm->name : '';
        $response['catalogVersion'] = $catalogCacheVersion;
        $response['categoryNames'] = array_map(function($slug) use ($termObjects) {
            return $termObjects[$slug]->name ?? '';
        }, $categories);

        // Cache results — a transient, not the object cache: these installs run
        // without a persistent object cache drop-in (no Redis/Memcached), which
        // made wp_cache_get/set a per-request no-op in production (OE-26548).
        // Capped so this cache can never outlive an item's next active-date-window
        // start/stop crossing — see MenuItemActiveWindow::cacheTtl().
        set_transient($cache_key, $response, $activeWindowCacheTtl);

        return rest_ensure_response($response);
    }
    public function getProductsHTMLSearch($request) {
        $this->releaseSessionLock();
        $search_term = sanitize_text_field($request->get_param('search'));
        $search_term_lower = strtolower($search_term);
        $category = "test";
        $categoryName = "Test Category";

        $searchArgs = [
            'status'       => 'publish',
            'limit'        => 100,
            'stock_status' => 'instock',
            'return'       => 'ids',
        ];

        // Apply the same site filter as getProductsHTML, failing closed: an
        // unresolved site returns no search results instead of every site's.
        $activeSiteId = SiteScope::resolveActiveSiteId( $request );
        if ( '' === $activeSiteId ) {
            CBSLogger::products()->debug(
                'getProductsHTMLSearch: active site could not be resolved — returning empty search results'
            );
            return rest_ensure_response('');
        }
        // Scope search to the active site AND menu — without the menu clause a
        // search at lunch would surface breakfast-only items (OE-26399).
        $activeMenuId = MenuScope::resolveActiveMenuId( $activeSiteId );
        $searchArgs['meta_query'] = ProductScope::metaQuery( $activeSiteId, $activeMenuId );

        $products = wc_get_products( $searchArgs );

        $data = [];
        ob_start();

        echo '<li id="' . esc_attr($search_term) . '" class="product-category-container">';
        echo '<div class="products-category">';
        echo '<h2 class="woocommerce-loop-category-title">' . esc_html($search_term) . '</h2>';
        echo '<ul class="product-list-container">';

        foreach ($products as $product_id) {
            $product = wc_get_product($product_id);
            $product_name = $product->get_name();

            if ($search_term === '' || strpos(strtolower($product_name), $search_term_lower) !== false) {
                // Correctly set global $post as WP_Post
                global $post;
                $post = get_post($product_id);
                setup_postdata($post);

                wc_get_template_part('content', 'product');

                wp_reset_postdata();
            }
        }

        echo '</ul>';
        echo '</div></li>';
        $html = ob_get_clean();
        return rest_ensure_response($html);
    }

    private $productlinkContext = null;
    /**
     * Changes the product link url.
     *
     * @return void
     */
    public function renderLoopProductLinkOpen() {
        $base_url  = $this->productlinkContext['base_url']  ?? '';
        $classes = 'woocommerce-LoopProduct-link woocommerce-loop-product__link';
        $slug = '';

        global $product;
        $product_id = is_object($product) ? $product->get_id() : get_the_ID();
        $url = get_permalink($product_id);

        $linkedItems = get_post_meta($product_id, '_link_to_category', true);
        $data = json_decode($linkedItems, true) ?: [];

        $siteMode = get_option('siteMode','olo') === 'kiosk';
        if (!empty($data)) {
            $slug = $data['slug'] ?? '';
            $name = $data['name'] ?? '';
            $classes.=' linkto';
            $base = $base_url ?: home_url('/menu-items/');
            $url = add_query_arg(
                array(
                    'cat_slug' => $slug,
                    'cat_name' => $name,
                ),
                $base
            );
            
        }

        echo '<a href="' . esc_url($url) . '" class="' . esc_attr($classes) . '" data-categoryslug="'. esc_attr($slug).'" data-is-kiosk-mode="' . ($siteMode ? 'true' : 'false') . '">';
    }

    private function renderCategoryProducts($request,$category,$customProducts,$termObjects): string {
        $htmlResult = '';
        if($customProducts) {
            $categoryName = $termObjects[$category]->name;
            $htmlResult.= '<li id="'.esc_attr($category).'" class="product-category-container"> <div class="products-category">';
            $htmlResult.= '<h2 class="woocommerce-loop-category-title">'.esc_html($categoryName).'</h2>';
            $htmlResult.= '<ul class="product-list-container">';
            
            $base_url = $request->get_param('base_url');
            if (!$base_url) {
                $base_url = wp_get_referer();
            }
            if (!$base_url) {
                $shop_id = function_exists('wc_get_page_id') ? wc_get_page_id('shop') : 0;
                $base_url = $shop_id > 0 ? get_permalink($shop_id) : home_url('/');
            }
            $base_url = strtok($base_url, '?');

            $this->productlinkContext = array(
                'base_url'   => $base_url,
            );
            remove_action('woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open', 10);
            add_action('woocommerce_before_shop_loop_item', [$this, 'renderLoopProductLinkOpen'], 10);


            ob_start();
            $this->renderProductsContent($customProducts);
            $htmlResult .= ob_get_clean();

            remove_action('woocommerce_before_shop_loop_item', [$this, 'renderLoopProductLinkOpen'], 10);
            add_action('woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open', 10);
            $this->productlinkContext = null;

            wp_reset_postdata();
            $htmlResult.= '</ul>';
            $htmlResult.= '</div></li>';
        } else {
            do_action('woocommerce_no_products_found');
        }
        return $htmlResult;
    }

    private function renderProductsContent($customProducts) {
        foreach($customProducts->products as $item) {
            $post_object = get_post($item);
            setup_postdata($GLOBALS['post'] =& $post_object);
            wc_get_template_part('content', 'product');
        }
    }
    public function loadMoreProducts(\WP_REST_Request $request) {
        $this->releaseSessionLock();

    $category = sanitize_text_field($request->get_param('category'));
    $page     = max(1, (int) $request->get_param('page'));

    if (empty($category)) {
        return rest_ensure_response(['error' => 'Category is required']);
    }

    // Fail closed: without a resolvable site, return no products rather than
    // every site's items (this endpoint also feeds the legacy infinity scroll).
    $activeSiteId = SiteScope::resolveActiveSiteId( $request );
    if ( '' === $activeSiteId ) {
        CBSLogger::products()->debug(
            'loadMoreProducts: active site could not be resolved — returning no products'
        );
        header('Content-Type: text/html; charset=utf-8');
        echo '';
        exit;
    }
    // Scope to the active site AND menu so paged loads match the first page and
    // never leak items from a menu the category is also shared with (OE-26399).
    $activeMenuId = MenuScope::resolveActiveMenuId( $activeSiteId );

    // Cache the rendered HTML per scope (OE-26548): this endpoint had zero
    // caching, so every sticky-bar click re-ran the query + per-product N+1
    // render. Empty HTML is a valid, cacheable result — the client treats ''
    // as "no more pages" — so a plain false-check distinguishes miss from hit.
    $cacheKey = MenuRenderCache::loadmoreKey(
        $category,
        $page,
        $activeSiteId,
        $activeMenuId,
        self::LOADMORE_PER_PAGE,
        (string) get_option( 'cbs_catalog_cache_version', '0' )
    );
    $html = get_transient( $cacheKey );

    if ( false === $html ) {
        $loadMoreMetaQuery = ProductScope::metaQuery(
            $activeSiteId,
            $activeMenuId,
            [ [ 'key' => '_stock_status', 'value' => 'instock', 'compare' => '=' ] ]
        );

        $query = new WP_Query([
            'post_type'      => 'product',
            'posts_per_page' => self::LOADMORE_PER_PAGE,
            'post_status'    => 'publish',
            'paged'          => $page,
            'tax_query'      => [[
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => $category,
                'include_children' => false,
            ]],
            'meta_query'     => $loadMoreMetaQuery,
        ]);

        ob_start();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                wc_get_template_part('content', 'product');
            }
        }

        wp_reset_postdata();

        $html = ob_get_clean();

        set_transient( $cacheKey, $html, (int) apply_filters( 'cbs_loadmore_cache_ttl', HOUR_IN_SECONDS ) );
    }

    // FORCE RAW OUTPUT, BYPASS ALL JSON
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
    }
    	public function searchLocations( \WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$repository = new \CBSNorthStar\Repositories\LocationsRepository($wpdb);
		$configId = $repository->getLatestConfigId();

		if ( ! $configId ) {
			return new WP_REST_Response(
				[
					'success' => true,
					'items'   => [],
				],
				200
			);
		}

		$keyword = (string) $request->get_param( 'keyword' );
		$radius  = (float) $request->get_param( 'radius' );
		$lat     = $request->get_param( 'lat' );
		$lng     = $request->get_param( 'lng' );

		if ( null !== $lat && null !== $lng && '' === $keyword ) {
			$items = $repository->getLocationsByCoordinates(
				(float) $lat,
				(float) $lng,
				$radius,
				$configId
			);
		} else {
			$items = $repository->searchLocations(
				$keyword,
				$radius,
				$configId
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'items'   => array_map( [ $this, 'prepareLocationForResponse' ], $items ),
			],
			200
		);
	}

	public function sanitizeRadius( $value ): float {
		$radius = (float) $value;

		if ( $radius <= 0 ) {
			return 5.0;
		}

		return min( max( $radius, 0.1 ), 50 );
	}

	private function prepareLocationForResponse( array $item ): array {
		$nextPage = 'menu-items';

		if ( function_exists( 'carbon_get_theme_option' ) && carbon_get_theme_option( 'olo_enable_rsm' ) ) {
			$nextPage = carbon_get_theme_option( 'olo_next_page' ) ?: 'menu-items';
		}

		$url = add_query_arg(
			[
                'site_id' => $item['siteid'],
			],
			home_url( '/' . $nextPage )
		);

		return [
			'siteid'            => (string) $item['siteid'],
			'areaid'            => (string) ($item['areaid'] ?? ''),
			'site_name'         => (string) $item['site_name'],
			'address1'          => (string) $item['address1'],
			'city'              => (string) $item['city'],
			'state'             => (string) $item['state'],
			'zipcode'           => (string) $item['zipcode'],
			'countrycode'       => (string) $item['countrycode'],
			'phone'             => ! empty( $item['phone'] ) ? (string) $item['phone'] : '--',
			'area_name'         => (string) $item['area_name'],
			'latitude'          => isset( $item['latitude'] ) ? (float) $item['latitude'] : null,
			'longitude'         => isset( $item['longitude'] ) ? (float) $item['longitude'] : null,
			'menu_type'         => (string) $item['menu_type'],
			'shipping_control'  => isset( $item['shipping_control'] ) ? (string) $item['shipping_control'] : '',
			'distance'          => isset( $item['distance'] ) ? round( (float) $item['distance'], 1 ) : null,
			'kitchenopentime'   => ! empty( $item['kitchenopentime'] ) ? mysql2date( 'h:i A', $item['kitchenopentime'] ) : '--',
			'kitchenclosetime'  => ! empty( $item['kitchenclosetime'] ) ? mysql2date( 'h:i A', $item['kitchenclosetime'] ) : '--',
			'is_open'           => KitchenHours::isOpenNow( $item ),
			'url'               => esc_url_raw( $url ),
		];
	}



	/**
	 * DELETE /wp-json/northstaronlineordering/v1/timeslots/reserve
	 *
	 * Releases the active timeslot reservation stored in the WC session.
	 * Called by the navigation popup when the user changes their slot selection
	 * so the previously held slot is freed immediately.
	 *
	 * @param  \WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function releaseTimeslotReservation( \WP_REST_Request $request ): WP_REST_Response {
		$nonce = (string) ( $request->get_header( 'X-WP-Nonce' ) ?: $request->get_param( '_wpnonce' ) );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			CBSLogger::api()->warning( 'releaseTimeslotReservation: nonce check failed' );
			return new WP_REST_Response(
				[ 'success' => false, 'message' => __( 'Security check failed.', 'olo' ) ],
				403
			);
		}

		$prior = TimeSlotReservationSession::get();
		CBSLogger::api()->debug( 'releaseTimeslotReservation: session data', [ 'prior' => $prior ] );

		$timeSlotsOrderId = ! empty( $prior['times_slots_order_id'] )
			? $prior['times_slots_order_id']
			: sanitize_text_field( (string) $request->get_param( 'timeSlotsOrderId' ) );

		$timeSlotId = ! empty( $prior['time_slot_id'] )
			? $prior['time_slot_id']
			: sanitize_text_field( (string) $request->get_param( 'timeSlotId' ) );

		$businessDate = ! empty( $prior['business_date'] )
			? $prior['business_date']
			: sanitize_text_field( (string) $request->get_param( 'businessDate' ) );

		$slotTime = ! empty( $prior['slot_time'] )
			? $prior['slot_time']
			: sanitize_text_field( (string) $request->get_param( 'slotTime' ) );

		if ( ! $timeSlotsOrderId || ! $timeSlotId ) {
			CBSLogger::api()->warning( 'releaseTimeslotReservation: no timeSlotsOrderId to delete', [
				'prior_exists'         => ! empty( $prior ),
				'times_slots_order_id' => $timeSlotsOrderId,
				'time_slot_id'         => $timeSlotId,
			] );
			TimeSlotReservationSession::clear();
			return new WP_REST_Response( [ 'success' => true ], 200 );
		}

		$siteId = ! empty( $prior['site_id'] )
			? $prior['site_id']
			: sanitize_text_field( (string) $request->get_param( 'siteId' ) );
		if ( '' === $siteId && ! empty( $_COOKIE['siteid'] ) ) {
			$siteId = sanitize_text_field( $_COOKIE['siteid'] );
		}

		CBSLogger::api()->info( 'releaseTimeslotReservation: calling CBS delete', [
			'siteId'           => $siteId,
			'timeSlotsOrderId' => $timeSlotsOrderId,
			'timeSlotId'       => $timeSlotId,
			'businessDate'     => $businessDate,
		] );

		$service = TimeSlotService::create();
		$deleted  = $service->deleteTimeSlotReservation(
			$siteId,
			$timeSlotsOrderId,
			$businessDate,
			$slotTime,
			$timeSlotId
		);

		CBSLogger::api()->info( 'releaseTimeslotReservation: CBS delete result', [ 'deleted' => $deleted ] );

		if ( $deleted ) {
			TimeSlotReservationSession::clear();
		}

		return new WP_REST_Response( [ 'success' => $deleted ], $deleted ? 200 : 502 );
	}

	/**
	 * POST /wp-json/northstaronlineordering/v1/timeslots/reserve
	 *
	 * Validates the selected slot, calls the CBS reserve API via the service
	 * layer, and stores the confirmed reservation in the WC session so that
	 * checkout validation can verify it before placing the order.
	 *
	 * @param  \WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function reserveTimeSlot( \WP_REST_Request $request ): WP_REST_Response {
		// Nonce check — WP REST nonce is issued per-session for both guests and
		// logged-in users.  The frontend sends it via the X-WP-Nonce header.
		$nonce = (string) ( $request->get_header( 'X-WP-Nonce' ) ?: $request->get_param( '_wpnonce' ) );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_REST_Response(
				[ 'success' => false, 'message' => __( 'Security check failed.', 'olo' ) ],
				403
			);
		}

		// Params are already sanitised by the route `args` callbacks.
		$date       = (string) $request->get_param( 'date' );
		$timeSlotId = (string) $request->get_param( 'timeSlotId' );
		$slotTime   = (string) $request->get_param( 'slotTime' );
		$siteId     = (string) $request->get_param( 'siteId' );

		// Fall back to cookie when the frontend omits siteId.
		if ( '' === $siteId && ! empty( $_COOKIE['siteid'] ) ) {
			$siteId = sanitize_text_field( $_COOKIE['siteid'] );
		}

		if ( '' === $siteId ) {
			return new WP_REST_Response(
				[ 'success' => false, 'message' => __( 'Site could not be determined.', 'olo' ) ],
				400
			);
		}

		// Enforce the configured max-days-ahead window server-side.
		if ( function_exists( 'carbon_get_theme_option' ) ) {
			$maxDays = (int) carbon_get_theme_option( 'olo_time_slot_max_days_ahead' );
			if ( $maxDays > 0 ) {
				$today   = current_time( 'Y-m-d' );
				$maxDate = date( 'Y-m-d', strtotime( "+{$maxDays} days", current_time( 'timestamp' ) ) );
				if ( $date < $today || $date > $maxDate ) {
					return new WP_REST_Response(
						[ 'success' => false, 'message' => __( 'Selected date is outside the allowed booking window.', 'olo' ) ],
						400
					);
				}
			}
		}


		$service = TimeSlotService::create();
		$prior   = TimeSlotReservationSession::get();

		if (
			$prior &&
			! empty( $prior['times_slots_order_id'] ) &&
			! empty( $prior['time_slot_id'] )/*  &&
			// Skip if the user is re-submitting the exact same slot.
			$prior['time_slot_id'] !== $timeSlotId */
		) {
			// Use the siteId that was active when the prior reservation was made,
			// not the current request's siteId, to avoid targeting the wrong site.
			$priorSiteId = ! empty( $prior['site_id'] ) ? $prior['site_id'] : $siteId;

			$deleted = $service->deleteTimeSlotReservation(
				$priorSiteId,
				$prior['times_slots_order_id'],
				$prior['business_date'] ?? $date,
				$prior['slot_time']     ?? '',
				$prior['time_slot_id']
			);

			// Only clear the session when the delete was confirmed so that a
			// failed delete does not silently orphan the prior reservation.
			if ( $deleted ) {
				TimeSlotReservationSession::clear();
			}
		}

		$result = $service->reserveTimeSlot( $siteId, $date, $slotTime, $timeSlotId );

		if ( ! $result['success'] ) {
			$errorMessage = ! empty( $result['message'] )
				? $result['message']
				: __( 'This time slot is no longer available. Please choose another.', 'olo' );

			return new WP_REST_Response(
				[ 'success' => false, 'message' => $errorMessage ],
				422
			);
		}

		// Extract the timeSlotsOrderId returned by the CBS reserve API so we
		// can release the slot if the user switches to a different time.
		$timeSlotsOrderId = '';
		if ( ! empty( $result['response'] ) ) {
			$apiResponse = $result['response'];
			if ( isset( $apiResponse->reservedTimeslot->timeSlotsOrderId ) ) {
				$timeSlotsOrderId = (string) $apiResponse->reservedTimeslot->timeSlotsOrderId;
			}
		}
        if ( '' === $timeSlotsOrderId ) {
           return new WP_REST_Response(
               [ 'success' => false, 'message' => __( 'Reservation response was incomplete.', 'olo' ) ],
               502
           );
       }

		// Persist confirmed reservation to session so checkout validation can
		// verify it even after a page reload.
		$rawValue = $timeSlotId . '|' . $slotTime;
		TimeSlotReservationSession::set( [
			'site_id'              => $siteId,
			'time_slot_id'         => $timeSlotId,
			'slot_time'            => $slotTime,
			'business_date'        => $date,
			'raw_value'            => $rawValue,
			'reserved_at'          => time(),
			'times_slots_order_id' => $timeSlotsOrderId,
		] );

		return new WP_REST_Response(
			[
				'success'     => true,
				'message'     => __( 'Time slot reserved.', 'olo' ),
				'reservation' => [
					'timeSlotId'        => $timeSlotId,
					'slotTime'          => $slotTime,
					'businessDate'      => $date,
					'timeSlotsOrderId'  => $timeSlotsOrderId,
					'siteId'            => $siteId,
				],
			],
			200
		);
	}

    public function oloRestTimeslotsByDate(\WP_REST_Request $request) {
        $siteId = sanitize_text_field($request->get_param('siteId'));
        $areaId = sanitize_text_field($request->get_param('areaId'));
        $date    = sanitize_text_field($request->get_param('date'));

        if (!$siteId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid params'], 400);
        }

        // OE-26541: the visited site's area is authoritative. Each site maps to exactly one
        // area in cbs_site_details, so derive the areaId from siteId (pure DB read, no cookie
        // side effects) and override any stale/mismatched areaId the client may still carry
        // from a previously visited site. Falls back to the client value only when the site
        // has no configured area, preserving prior behavior.
        $siteAreaData    = ConfigurationRepository::create()->getAreaId($siteId);
        $canonicalAreaId = isset($siteAreaData['areaid']) ? sanitize_text_field((string) $siteAreaData['areaid']) : '';
        $areaId          = TimeSlotAreaResolver::resolveForSite($areaId, $canonicalAreaId);

        if (!$areaId) {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid params'], 400);
        }
        // OE-26541: anchor "today" and the cutoff to the SITE's own clock (cbs_site_details
        // timezone via SiteClock), not WP's timezone — otherwise, when they differ, slots near
        // "now" are over- or under-trimmed. Falls back to WP current_time() for sites with no
        // configured timezone. Same clock the service uses to drop passed slots.
        $siteNow = \CBSNorthStar\Helpers\SiteClock::nowForSite($siteId);
        $today   = $siteNow->format('Y-m-d');

            if ($date === $today) {

                    $slotDate = $date . ' ' . $siteNow->format('H:i:s');
            } else {
                    $slotDate = $date . ' 00:00:00';
            }

        $options   = TimeSlotService::create()->oloGetAvailableTimeslotOptions($siteId, $areaId, $slotDate);

        return new WP_REST_Response([
            'success'  => true,
            'slotDate' => $slotDate,
            'options'  => $options,
        ], 200);
    }

}
