<?php

namespace CBSNorthStar\Init\Loaders;
use CBSNorthStar\Order\Check;
use CBSNorthStar\Services\ProductManager;
use CBSNorthStar\Repositories\CartRecordRepository;
use function wp_create_nonce;
use CBSNorthStar\Helpers\BuildNumberHelper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OrderAtTableLoader implements JavaScriptLoaderContract
{
    private static $instance = null;

    public static function create(): ?OrderAtTableLoader
    {
        if (self::$instance === null) {
            self::$instance = new OrderAtTableLoader();
            add_action('wp_enqueue_scripts', [self::$instance, 'registerScriptsNonce']);
        }

        return self::$instance;
    }
    
    public function registerScripts()
    {
        add_action('wp_ajax_set_area_context_action', array($this, 'ajaxHandler'));
        add_action('wp_ajax_nopriv_set_area_context_action', array($this, 'ajaxHandler'));

    }
    public function registerScriptsNonce()
    {
        wp_enqueue_script(
            'order_at_table_handle',
            plugins_url('/js/orderAtTable.js', CBS_PLUGIN_FILE),
            ['jquery'],
            BuildNumberHelper::getBuildNumber(),
            true
        );
        $nonce = wp_create_nonce('set_area_context_action');

        wp_localize_script('order_at_table_handle', 'northStarOAT', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => $nonce,
        ));
    }

    public function ajaxHandler(): void
    {
         if (isset($_POST['action']) && $_POST['action'] === 'set_area_context_action') {
            $siteId = sanitize_text_field($_POST['site_id'] ?? '');
            $location = sanitize_text_field($_POST['location'] ?? '');
            $area = sanitize_text_field($_POST['area'] ?? '');


            $cookieOptions = array(
                'expires'  => time() + DAY_IN_SECONDS,
                'path'     => defined('COOKIEPATH') ? COOKIEPATH : '/',
                'domain'   => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            );

            setcookie("table_num", $validateLocation->Data->tableNumber, $cookieOptions );
            setcookie("area_external_code",$area , $cookieOptions );


            $checkHandler = new Check($siteId);
            $validateLocation = $checkHandler->validateLocation($location, $area);

             if (is_object($validateLocation) && isset($validateLocation->Data->CheckId)) {
                WC()->cart->empty_cart();

                setcookie("checkid", $validateLocation->Data->CheckId, $cookieOptions );
                setcookie("checknumber", $validateLocation->Data->checkNumber, $cookieOptions );


               $items = $checkHandler->addExistingCart($validateLocation) ?? [];
               setcookie("cart_item_arr", json_encode($items), $cookieOptions);
               if (is_array($items)) {
                   foreach ($items as $itemkey => $itemvalue) {
                       setcookie($itemkey, $itemvalue, $cookieOptions);
                   }
               }


                wp_send_json_success("current_check");
            }
            if($validateLocation == "invalid_check") {
                CartRecordRepository::create()->deleteCartData($location,$area);
            }

           wp_send_json_success($validateLocation);
        }
    }
}
