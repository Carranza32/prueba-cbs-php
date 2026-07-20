<?php
//session_start();
if(isset($_SESSION['siteid']))
{
  $siteid = $_SESSION['siteid'];  
}
else
{
 $siteid='';
}

function fetch_json_callback()
{

  global $wpdb;
  $get_json_callback = $wpdb->get_row("SELECT callbackdata FROM cbs_webhook_registration where siteid='" . $_SESSION['siteid'] . "' and webhooktype='UnavailableItemsChanged'");


  if (!empty($get_json_callback->callbackdata)) {
    $response = json_decode($get_json_callback->callbackdata);
    $SiteId = $response->Notifications[0]->SiteId;

    $MenuItems = $response->Notifications[0]->Arguments->MenuItems;
    $removeditems = "";
    foreach ($MenuItems as $key => $ItemId) {
      $product_id = get_post_id_by_itemid($SiteId, $ItemId);

      make_product_hidden($product_id);

      if (is_cart()) {

        $pid = show_product_not_available_cartpage($product_id);
        if ($pid) {
          $_SESSION['pid'] = $pid;
          $prod_name = get_the_title($pid);             
          if ($prod_name != "") {
            wc_print_notice(__('Item unavailable: ' . $prod_name), 'error');
          }
        }
      }

      if (is_checkout()) {
        $is_remove = remove_product_from_cart($product_id);
        if ($is_remove) {
          $prod_name = get_the_title($product_id);
          $removeditems .= "\\n" . $prod_name;
          wc_print_notice(__('Product removed from cart: ' . $prod_name), 'error');
        }
      }
      if (is_product()) {
        global $product;

        if ($product->id == $product_id) {
          remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);

          add_action('woocommerce_single_product_summary', 'cbs_show_return_policy', 20);

          function cbs_show_return_policy()
          {
            echo '<p class="rtrn" style="color:red">Item unavailable.</p>';
          }
        }
      }
    }
  }
}


if (!function_exists('get_post_id_by_itemid')) {
  function get_post_id_by_itemid($siteid, $itemid)
  {
    global $wpdb;
    $meta = $wpdb->get_results("SELECT * FROM " . $wpdb->postmeta . " WHERE meta_key='_itemid' AND meta_value='" . $wpdb->escape($itemid) . "'");
    if (is_array($meta) && !empty($meta) && isset($meta[0])) {
      $meta = $meta[0];
    }
    if (is_object($meta)) {
      $Site_id = get_post_meta($meta->post_id, '_siteid', true);
      if ($Site_id == $siteid) {
        return $meta->post_id;
      } else {
        return false;
      }
    } else {
      return false;
    }
  }
}

function remove_product_add_to_cart_button()
{

  fetch_json_callback();
}


add_action('woocommerce_before_single_product', 'remove_product_add_to_cart_button', 40);



function remove_product_from_cart($product_id)
{
  // Run only in the Cart or Checkout Page

  foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
    if ($cart_item['product_id'] == $product_id) {
      $is_removed = WC()->cart->remove_cart_item($cart_item_key);
      return $is_removed;
    }
  }
}

function show_product_not_available_cartpage($product_id)
{
  // Run only in the Cart or Checkout Page

  foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
    if ($cart_item['product_id'] == $product_id) {

      return $product_id;
    }
  }
}

add_action('woocommerce_before_checkout_form', 'fetch_json_callback');

add_action('woocommerce_before_cart_table', 'fetch_json_callback');

function make_product_hidden($product_id)
{
  $terms = array('exclude-from-search', 'exclude-from-catalog'); // for hidden..
  $prod_ID = $product_id;
  wp_set_post_terms($prod_ID, $terms, 'product_visibility', false);
}

function make_product_visible($product_id)
{
  $terms = array('', ''); // for hidden..
  $prod_ID = $product_id;
  wp_set_post_terms($prod_ID, $terms, 'product_visibility', false);
}

function fetch_json_callback2()
{

  global $wpdb;
  $get_json_callback = $wpdb->get_row("SELECT callbackdata FROM cbs_webhook_registration where siteid='" . $_SESSION['siteid'] . "' and webhooktype='UnavailableItemsChanged'");


  if (!empty($get_json_callback->callbackdata)) {
    $response = json_decode($get_json_callback->callbackdata);
    $SiteId = $response->Notifications[0]->SiteId;

    $MenuItems = $response->Notifications[0]->Arguments->MenuItems;
    $removeditems = "";
    foreach ($MenuItems as $key => $ItemId) {
      $product_id = get_post_id_by_itemid($SiteId, $ItemId);

      make_product_visible($product_id);
    }
  }
}


function cart_product_title($title, $values, $cart_item_key)
{

  if ($values['product_id'] == $_SESSION['pid']) {
    return wc_print_notice(__('Item unavailable. ' . $title), 'error');
  } else {
    return $title;
  }
}
add_action("load_menuitems", "fetch_json_callback");
//add_action("wp_footer", "fetch_json_callback2");