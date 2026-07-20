<?php

ob_start();

if ( isset( $_GET['echo'] ) ) {
    ob_end_clean();
    header( 'Content-Type: text/plain' );
    $echo_val = substr( strip_tags( $_GET['echo'] ), 0, 256 );
    echo $echo_val;
    exit;
}

ob_end_clean();

header('Access-Control-Allow-Origin: *');
header('Content-Type: text/plain');

$_wp_root = dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) );
if ( file_exists( $_wp_root . '/wp-load.php' ) ) {
    require_once $_wp_root . '/wp-load.php';
} else {
    require_once $_wp_root . '/wp-config.php';
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    global $wpdb;

    // fetch RAW input
    $json = file_get_contents('php://input');

    // decode json
    $object = json_decode($json);

    // expecting valid json
    if (json_last_error() !== JSON_ERROR_NONE) {
        die(header('HTTP/1.0 415 Unsupported Media Type'));
    }

    // Diagnostic: log all incoming HTTP headers when the setting is on.
    if ( function_exists( 'carbon_get_theme_option' ) && (bool) carbon_get_theme_option( 'olo_log_webhook_headers' ) ) {
        $incomingHeaders = array_filter(
            $_SERVER,
            static fn( $key ) => strncmp( $key, 'HTTP_', 5 ) === 0,
            ARRAY_FILTER_USE_KEY
        );
        \CBSNorthStar\Logger\CBSLogger::webhooks()->info( 'UnavailableItemsChanged webhook — incoming headers', [
            'headers' => $incomingHeaders,
        ] );
    }

    // Authenticate before mutating product stock/availability.
    $bypassSignature = function_exists( 'carbon_get_theme_option' ) && (bool) carbon_get_theme_option( 'olo_disable_webhook_signature' );
    if ( $bypassSignature ) {
        \CBSNorthStar\Logger\CBSLogger::webhooks()->warning( 'UnavailableItemsChanged webhook — signature verification bypassed by admin setting' );
    } elseif ( ! \CBSNorthStar\Helpers\WebhookVerifier::verify( $json, 'UnavailableItemsChanged' ) ) {
        \CBSNorthStar\Logger\CBSLogger::webhooks()->warning('Rejected unauthenticated UnavailableItemsChanged webhook');
        status_header( 401 );
        die( 'Invalid webhook signature' );
    }

    \CBSNorthStar\Logger\CBSLogger::webhooks()->info('webhook active unavailable items');

    $menuItems = $object->Notifications[0]->Arguments->MenuItems;

    $components = $object->Notifications[0]->Arguments->Components ?? [];

    updateProductComponents($components);


    if (empty($menuItems)) {
        $product_id = '';
        set_menu_items_stock($product_id);
    }

    $products_arr = get_the_ids_inside_woocommerce($menuItems);

    // Access each menu item
    foreach ($products_arr as $product_id) {
        set_menu_items_stock($product_id);
    }

    foreach ($products_arr as $product_id) {
        set_menu_items_outstock($product_id);
    }

    file_put_contents('callback_unavail.txt', $json);

    if (isset($object->Id) && $object->Id != '') {
        $wpdb->update(
            'cbs_webhook_registration',
            [ 'callbackdata' => $json ],
            [
                'siteid'      => $object->Properties->OrderEntrySiteId,
                'webhooktype' => $object->Notifications[0]->Action,
            ]
        );
    }
}

function set_menu_items_outstock($product_id)
{
    try {
        $product = wc_get_product($product_id);
        $product->set_stock_quantity(0);
        $product->save();
        \CBSNorthStar\Logger\CBSLogger::webhooks()->info('Set item outstock', ['product_id' => $product_id]);
        return $product_id;
    } catch (Exception $e) {
        \CBSNorthStar\Logger\CBSLogger::webhooks()->error('Message: on check itemid exist', ['exception' => $e->getMessage()]);
    }
}

function set_menu_items_stock($product_id)
{
    $args = array(
        'post_type'    => 'product',
        'post_status'  => array('trash', 'publish'),
        'fields'       => 'ids',
        'meta_query'   => array(
            array(
                'key'     => '_stock',
                'value'   => '0',
                'compare' => '=',
                'type'    => 'NUMERIC',
            ),
        ),
    );

    $productlist = get_posts($args);

    foreach ($productlist as $product_id_outstock) {
        if ($product_id_outstock != $product_id) {
            $product = wc_get_product($product_id_outstock);
            \CBSNorthStar\Logger\CBSLogger::webhooks()->info('changed product_id to stock', ['product_id' => $product_id_outstock]);
            $product->set_stock_quantity(999);
            $product->save();
        }
    }
}

function get_the_ids_inside_woocommerce($menu_items_array)
{
    $product_ids_array = array();

    foreach ($menu_items_array as $menu_item) {
        $args = array(
            'post_type'    => 'product',
            'numberposts'  => 1,
            'post_status'  => array('trash', 'publish'),
            'fields'       => 'ids',
            'meta_query'   => array(
                array(
                    'key'   => '_itemid',
                    'value' => $menu_item,
                ),
            ),
        );
        $postslist = get_posts($args);

        if (!empty($postslist)) {
            $product_ids_array[] = $postslist[0];
        }
    }

    return $product_ids_array;
}
 function updateProductComponents(array $incomingComponents): void
  {
      // Normalize incoming IDs for fast lookup
      $incoming = array_fill_keys(array_map('strval', $incomingComponents), true);

      $product_ids = get_posts([
          'post_type'      => 'product',
          'post_status'    => ['publish', 'draft', 'private'],
          'posts_per_page' => -1,
          'fields'         => 'ids',
          'meta_query'     => [
              [
                  'key'     => '_components',
                  'compare' => 'EXISTS',
              ],
          ],
      ]);

      if (empty($product_ids)) {
          return;
      }

      foreach ($product_ids as $product_id) {

          $components_json = get_post_meta($product_id, '_components', true);
          if (empty($components_json) || !is_string($components_json)) {
              continue;
          }

          $components = json_decode($components_json, true);
          if (!is_array($components)) {
              continue;
          }

          $updated = false;

          foreach ($components as $ruleId => &$group) {
              if (!is_array($group)) continue;

              foreach ($group as &$component) {
                  if (!is_array($component)) continue;

                  $cid = isset($component['componentId']) ? (string) $component['componentId'] : '';
                  if ($cid === '') continue;

                  $newVal = isset($incoming[$cid]);

                  $oldVal = isset($component['outofstock']) ? (bool) $component['outofstock'] : false;

                  if ($oldVal !== $newVal) {
                      $component['outofstock'] = $newVal;
                      $updated = true;
                  } else {
                      $component['outofstock'] = $oldVal;
                  }
              }
          }

          if ($updated) {
              update_post_meta($product_id, '_components', wp_slash(wp_json_encode($components)));
          }
      }
  }