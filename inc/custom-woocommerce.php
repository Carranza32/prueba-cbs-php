<?php

/**
 * Custom woocommerce
 *
 * Used to crate add ons and modify data display at single prodcut page and cart page
 *
 * @package Northstaronlineordering\Custom_Woocommerce
 * @version 1.0.0
 */

//include wp-config or wp-load.php
use CBSNorthStar\Customizer\CustomizerSetup;
use CBSNorthStar\Helpers\WoapiRequest;
use CBSNorthStar\Models\Component;
use CBSNorthStar\Views\QuickOrderButtons;
use CBSNorthStar\Views\GiftCardForm;
use CBSNorthStar\Views\LoginForm;
use CBSNorthStar\Views\LoyaltyPopup;
use CBSNorthStar\Views\LocationForm;
use CBSNorthStar\Views\OutofStockProducts;
use CBSNorthStar\Logger\CBSLogger;
use CBSNorthStar\Helpers\ComponentPricing;

$root = dirname(dirname(dirname(dirname(dirname(__FILE__)))));

include(plugin_dir_path(__DIR__) . 'cbs_functions.php');

if (file_exists($root . '/wp-load.php')) {
  // WP 2.6
  require_once($root . '/wp-load.php');
} else {
  // Before 2.6
  require_once($root . '/wp-config.php');
}

/**
   * Fetch data from api
   *
   * @param string $url url of api endpoints with required parameters.
   * @param string $token token provided.
   * @param string $token_type token type.
   * @return array
   */
function get_api_data2($url, $token, $tokenType)
{
    $response = WoapiRequest::create()->get($url, [
      'token'       => $token,
      'tokenType'  => $tokenType
    ]);

	if (!empty($response->Data)) {
		return $response;
	}
}

/**
   * Fetch all componets rule
   *
   * @param string $siteId site id.
   * @param string $apicall_count keep record of api calls.
   * @return array
   */


/**
 * Add a div with id to work with the skip links
 */
function singleProductPositionDiv()
{
    echo '<div id="product-content"></div>';
}
add_action('woocommerce_single_product_summary', 'singleProductPositionDiv', 4);

/*
*
* Fetch product componets
*
*/
function singleProductComponents()
{
    echo '<div class="component_section"></div>';
}

add_action('woocommerce_before_add_to_cart_button', 'singleProductComponents', 15);

function singleProductServingOptionsSection()
{
    echo '<div id="servingoptions_section" class="variations"></div>';
}

add_action('woocommerce_before_add_to_cart_button', 'singleProductServingOptionsSection', 9);

/**
 ** add product component on cart item
 ** $cart_item_data cart item array
 ** $product_id product id
 ** $variation_id product variation id
 ** $quantity product quantity
 **/
function productComponentAddCartItem($cart_item_data, $product_id, $variatin_id, $quantity){

	$customComponentIdSession = WC()->session->get( 'custom_product_component_id' );
	$productComponentSession = WC()->session->get( 'custom_product_component');
    $productServingoptionsSession = WC()->session->get( 'custom_product_serving_options' );
   	$productServingOptionsHtmlSession = WC()->session->get( 'custom_product_serving_html' );
	
   if ( is_array( $productServingoptionsSession ) ) {
        $productServingoptionsSession = array_map(
            static function( $opt ) {
                return array(
                    'optionId'   => sanitize_text_field( $opt['optionId']   ?? '' ),
                   'optionName' => sanitize_text_field( $opt['optionName'] ?? '' ),
               );
           },
           $productServingoptionsSession
       );
    } else {
       $productServingoptionsSession = array();
    }

	$totalPriceSession = WC()->session->get( 'total_price');

	// The quick-add batch loop (AddToCartLoader::ajaxHandler) calls add_to_cart()
	// more than once per request, one per item — but filter_input(INPUT_POST, ...)
	// snapshots the request body once and never reflects a later $_POST mutation,
	// so every iteration would otherwise see the same (first/legacy) component
	// payload. The loop stashes each item's data here right before its add_to_cart()
	// call; every other caller (Customize page form POST, cart edit, REST /cart)
	// never sets it, so they fall through to the original filter_input() reads unchanged.
	$batchOverride = $GLOBALS['cbsQuickAddBatchItemOverride'] ?? null;
	if (is_array($batchOverride)) {
		$productPrice = $batchOverride['product_price_input'] ?? null;
		$productComponent = $batchOverride['selComponents'] ?? null;
		$productComponentPrice = $batchOverride['selComponentsPrice'] ?? null;
		$productComponentQty = $batchOverride['selComponentsQty'] ?? null;
		// These three always resolve to null for a batch request, by design, not
		// by omission: AddToCartLoader's batch loop is the quick-add button's only
		// caller, and QuickOrderButtons never renders a quick-add button for a
		// product with serving-option requirements (verifyComponentsRequirements()
		// forces "Customize" instead whenever $servingOptions or an unmet
		// per-component servingOptions rule exists) — so a quick-add batch item can
		// never carry selServingOptions/selectedComponents to begin with. Per-component
		// *default* serving options (the compatible case) already travel inside
		// selComponents itself via getDefaultServingOptions(). Don't wire these into
		// QuickAddBatch's normalizer/override "to match the legacy path" — there is no
		// data loss here to fix, only dead plumbing for a path that can't be reached.
		$productServingOptions = $batchOverride['selServingOptions'] ?? null;
		$servingOptionsPrice = $batchOverride['selServingOptionsPrice'] ?? null;
		$componentSelectedByCategory = $batchOverride['selectedComponents'] ?? null;
	} else {
		$productPrice = filter_input(INPUT_POST, 'product_price_input');
		$productComponent = filter_input(INPUT_POST, 'selComponents');

		$productComponentPrice = filter_input(INPUT_POST, 'selComponentsPrice');
		$productComponentQty = filter_input(INPUT_POST, 'selComponentsQty');

		$productServingOptions = filter_input(INPUT_POST, 'selServingOptions');
		$servingOptionsPrice = filter_input(INPUT_POST, 'selServingOptionsPrice');
		$componentSelectedByCategory = filter_input(INPUT_POST, 'selectedComponents');
	}

	if (!empty($productComponentQty)) {
		$productComponentQty = json_decode($productComponentQty,true);
	}
	if (!empty($productComponent)) {
		$productComponent = json_decode($productComponent,true);
		$componentSelectedByCategory = json_decode($componentSelectedByCategory,true);
		$componentHtml = '';
		$componentname[] = '';
		$componentids = [];

		$result = processProductComponents($productComponent, $productComponentQty);
		$componentname = $result['componentname'];
		$componentids = $result['componentids'];
		$componentHtml = generateComponentHtml($componentname);
		

		//Add item data here

		$cart_item_data['product_component'] = $componentHtml;
		$cart_item_data['product_component_id'] = json_encode($componentids);

		// This field is use only on kiosk
		$cart_item_data['product_component_selected_by_category'] = $componentSelectedByCategory ?? [];

		$product = wc_get_product($product_id);
		if($product->get_price()){
			$price = number_format($product->get_price(),2);
		}else{
			$price = 0.00;
		}

		$servingOptionsPrice = !empty($servingOptionsPrice) ? $servingOptionsPrice : 0;
		$componentPrice =  $productComponentPrice;
		$cart_item_data['total_price'] = $price + $componentPrice + $servingOptionsPrice;
	}
	if($customComponentIdSession){
		if($totalPriceSession){
			$cart_item_data['total_price'] = $totalPriceSession;
		}
		$cart_item_data['product_component'] = $productComponentSession;
		$cart_item_data['product_component_id'] = $customComponentIdSession;

		WC()->session->__unset( 'custom_product_component_id' );
		WC()->session->__unset( 'custom_product_component' );
		WC()->session->__unset( 'total_price' );
	}
	if ( $productServingoptionsSession ) {
		if ( $totalPriceSession ) {
			$cart_item_data['total_price'] = $totalPriceSession;
		}

		$cart_item_data['product_serving_options']      = $productServingoptionsSession;
		$cart_item_data['product_serving_options_html'] = wp_kses_post( $productServingOptionsHtmlSession );

		WC()->session->__unset( 'custom_product_serving_options' );
		WC()->session->__unset( 'custom_product_serving_html' );
		WC()->session->__unset( 'total_price' );
	}

	if(!empty(json_decode($productServingOptions, true))){
		
		$productServingOptions = json_decode($productServingOptions, true);
		$productServingOptions = array_map(function($servingOption) {
			return [
				'optionId' => $servingOption['optionId'],
				'optionName' => sanitize_text_field($servingOption['optionName'] ?? ''),
				'optionPrice' => (float) ($servingOption['optionPrice'] ?? 0)
			];
		}, $productServingOptions);
		if(!empty($productServingOptions)){
			$productServingOptions = array_values($productServingOptions);
		}
		$cart_item_data['product_serving_options'] = $productServingOptions;
		$cart_item_data['product_serving_options_html'] = buldServingOptionHtlmSection($productServingOptions);
	}
	return $cart_item_data;
}
add_filter('woocommerce_add_cart_item_data', 'productComponentAddCartItem', 10, 4);

/**
 ** dispaly product component on cart page
 **
 */
function get_product_component_data($item_data, $cart_item)
{
	if (empty($cart_item['product_component'])) {
		return $item_data;
	}
	$item_data[] = array(
		'key' => __('Product Components', 'CustomBusiness'),
		'value' => ($cart_item['product_component']),
		'display' => '',
	);

	if (!empty($cart_item['product_serving_options_html'])) {
		$item_data[] = array(
			'key' => __('Serving Options', 'CustomBusiness'),
			'value' => ($cart_item['product_serving_options_html']),
			'display' => '',
		);
	}

	return $item_data;

}
add_filter('woocommerce_get_item_data', 'get_product_component_data', 10, 2);

/**
 ** update the component price on cart page
 ** @param $cart_objects is cart object
 */
function calculates_components_price($cart_object)
{
	if (is_admin() && !defined('DOING_AJAX')) {
		return;
	}
	foreach ($cart_object->get_cart() as $key => $value) {
		if (isset($value['total_price'])) {
			$componentprice = $value['total_price'];
			$value['data']->set_price($componentprice);
		}
	}
}

add_filter('woocommerce_before_calculate_totals', 'calculates_components_price', 10, 1);

/**
 ** add custom component on cart order item
 **
 */

function addProductComponentOnOrder($item, $_cart_item_key, $values, $_order)
{
	$hasComponentHtml = !empty($values['product_component']);
	$hasThankYouOverride = get_option('thank_you_page_message') === "";
	$rawComponentId = $values['product_component_id'] ?? null;

	if($hasComponentHtml || $hasThankYouOverride){
		$item->add_meta_data(__('Product Component', 'cbs'), $values['product_component']);
		$componentIds = json_decode($values['product_component_id'], true);
		$item->add_meta_data(__('Product Component Id', 'cbs'), $componentIds);

		if (empty($componentIds) && !empty($rawComponentId)) {
			CBSLogger::orders()->error('[CBS_COMPONENTS] addProductComponentOnOrder: json_decode produced empty result from non-empty input', ['rawType' => gettype($rawComponentId), 'rawValue' => $rawComponentId]);
		}
	} else {
		if (!empty($rawComponentId)) {
			CBSLogger::orders()->debug('[CBS_COMPONENTS] addProductComponentOnOrder: Skipped storing component meta despite product_component_id being present', ['productComponentEmpty' => empty($values['product_component']) ? 'yes' : 'no', 'thankYouPageMessage' => get_option('thank_you_page_message'), 'rawComponentId' => $rawComponentId]);
		}
	}

	if(!empty($values['product_serving_options'])){
		$item->add_meta_data("servingOptions", $values['product_serving_options']);
		$item->add_meta_data("Serving Options", $values['product_serving_options_html']);
	}

	// Store taxable status for thank you page display
	if (function_exists('cbsItemHasTax') && cbsItemHasTax($values, $_cart_item_key)) {
		$item->add_meta_data('_cbs_is_taxable', '1', false);
	}
}
add_action('woocommerce_checkout_create_order_line_item', 'addProductComponentOnOrder', 10, 4);


add_action('woocommerce_before_add_to_cart_form', 'cbs_after_add_to_cart_form');

function cbs_after_add_to_cart_form()
{
	echo '<span style="color:red" id="minrequiredmsg"></span>';

}


/**
 ** Adding user name field for selcted item
 */

function add_order_user_name_field()
{
	$customerLabel = 'customer name';
	if(!empty(get_theme_mod( 'customer-name-label-text' ))){
		$customerLabel = get_theme_mod( 'customer-name-label-text' ); 
	}
	echo '<table class="variations costumer-name" cellspacing="0" role="presentation">
      <tbody>
          <tr>
          <td class="label" role="presentation"><label for="customer-name">'.$customerLabel.'</label><br><small id="cname_sub">(Optional)</small></td>
      </tr>
	  <tr>
	  <td class="value">
	  <input type="text" name="item_selected_for" value="" id="customer-name" placeholder="Enter customer name or a note" maxlength="250"/>
  </td>
	  </tr>
      </tbody>
  </table>';

 
}
add_action('woocommerce_before_add_to_cart_button', 'add_order_user_name_field',10);

function save_order_user_name_field($cart_item_data, $product_id)
{
	if (isset($_REQUEST['item_selected_for'])) {
		$cart_item_data['item_selected_for'] = substr(sanitize_text_field($_REQUEST['item_selected_for']), 0, 250);
		/* below statement make sure every add to cart action as unique line item */
		$cart_item_data['unique_key'] = md5(microtime() . rand());
	}
	return $cart_item_data;
}
add_action('woocommerce_add_cart_item_data', 'save_order_user_name_field', 10, 2);


/**
 ** displaying custom field on cart item table
 */
function render_meta_on_cart_and_checkout($cart_data, $cart_item = null)
{
	$custom_items = array();
	/* Woo 2.4.2 updates */
	if (!empty($cart_data)) {
		$custom_items = $cart_data;
	}
	if (isset($cart_item['item_selected_for']) && $cart_item['item_selected_for']!="") {
		$custom_items[] = array("name" => 'Item selected for', "value" => $cart_item['item_selected_for']);
	}

	return $custom_items;
}
add_filter('woocommerce_get_item_data', 'render_meta_on_cart_and_checkout', 10, 2);

function cbsProductOrderMetaHandler($item, $cart_item_key, $values, $order)
{
	if (isset($values['item_selected_for']) && !empty($values['item_selected_for'])) {
		$item->add_meta_data('Item selected for', $values['item_selected_for']);
	}
}
add_action('woocommerce_checkout_create_order_line_item', 'cbsProductOrderMetaHandler', 11, 4);

remove_action('woocommerce_before_main_content', 'woocommerce_breadcrumb', 20, 0);

add_filter( 'woocommerce_loop_add_to_cart_link', 'replacing_add_to_cart_button', 10, 2 );
function replacing_add_to_cart_button( $button, $product  )
{
	  return (new QuickOrderButtons($product))->render();

}

function cbs_excerpt_in_product_archives() {
    $exerpt = "<p class='product-description'>";
	$exerpt .= wp_trim_words( get_the_excerpt(), 5);
	$exerpt .= "</p>";
	echo $exerpt; 
          
}

$my_theme = wp_get_theme();
if($my_theme=="OceanWP")
{
add_action( 'ocean_after_archive_product_title', 'cbs_excerpt_in_product_archives', 11 );	
}
else
{
/* add_action( 'woocommerce_after_shop_loop_item_title', 'cbs_excerpt_in_product_archives', 11 );	 */
}

  /**
   * Function to add custom breadcrumbs and categories on product page
   *
   */

function show_breadcrumbs_cbs()
{
	if( is_product()){
		do_shortcode('[cbs_breadcrumbs]');	
	}
	else if(is_page('menu-items')){
		//do_shortcode('[cbs_breadcrumbs]');
	//	echo '<div class="cbs_menu_category">';
	//  do_shortcode('[categories]');
		echo '</div>';
	}
	

	

}
add_action('woocommerce_before_single_product', 'show_breadcrumbs_cbs');


/**
 ** Filter to show products according to site id
 */

add_filter( 'woocommerce_product_query_meta_query', 'show_only_products_with_specific_metakey', 10, 2 );
function show_only_products_with_specific_metakey( $meta_query, $query ) {
    // Only on shop pages
    if(! is_page( 'menu-items' )) return $meta_query;

    // Fail closed: an unresolved site/menu yields a never-match clause so no
    // cross-site or cross-menu items appear (OE-26387 / OE-26399); also avoids
    // an undefined-index notice. The combo-product exclusion is ANDed in via the
    // extra-clause argument so all three conditions stay in one nested group.
    $siteId = \CBSNorthStar\Helpers\SiteScope::resolveActiveSiteId();
    $menuId = \CBSNorthStar\Helpers\MenuScope::resolveActiveMenuId( $siteId );

    $meta_query[] = \CBSNorthStar\Helpers\ProductScope::metaQuery(
        $siteId,
        $menuId,
        array(
            array(
                'key'     => '_type',
                'value'   => 10, // hide combo products
                'compare' => '!=',
            ),
        )
    );
    return $meta_query;
}

/**
 * Changes the redirect URL for the Return To Shop button in the cart.
 *
 * @return string
 */
function wc_empty_cart_redirect_url() {

	return home_url('/locations');
}
add_filter( 'woocommerce_return_to_shop_redirect', 'wc_empty_cart_redirect_url' );

/**
 * Changes the text for the Return To Shop button in the cart.
 *
 * @return string
 */

add_filter( 'gettext', 'change_woocommerce_return_to_shop_text', 20, 3 );

function change_woocommerce_return_to_shop_text( $translated_text, $text, $domain ) {

        switch ( $translated_text ) {

            case 'Return to shop' :

				if(isset($_COOKIE['table_num'])){
					$translated_text = __( 'Return to Menu', 'woocommerce' );
				}else{
					$translated_text = __( 'Return to Locations', 'woocommerce' );
				}
                break;
        }

    return $translated_text;
}

remove_filter('woocommerce_return_to_shop_redirect', 'wc_empty_cart_redirect_url' );
function custom_empty_cart_redirect_url(){

	if(isset($_COOKIE['table_num'])){
		return home_url("/menu-items");
	}

	return home_url("/locations");
	
	}
add_filter( 'woocommerce_return_to_shop_redirect', 'custom_empty_cart_redirect_url' );

add_filter('woocommerce_update_order_review_fragments','custom_review_checkout');
function custom_review_checkout($arr){
		 $arr['.woocommerce-error'] = '<div class="woocommerce-error">' . __( 'Sorry, your session has expired.', 'woocommerce' ) . ' <a href="' . esc_url( wc_get_page_permalink( 'home' ) ) . '" class="wc-backward">' . __( 'Return to shop', 'woocommerce' ) . '</a></div>';
		 return $arr;
	
}

//Register Customizer Options
add_action('customize_register', [CustomizerSetup::class, 'init']);

function olo_tipping_custom() {
	// Hide "no, thanks" option
	$setting_value = carbon_get_theme_option('hide_no_thanks_olo_tipping');
	if ($setting_value) {
		echo '<style>
			.wpcot-tip-value-none {
				display: none;
			}
		</style>';
	}

	if (carbon_get_theme_option('olo_no_thanks_tip')) {
		if (is_page('checkout') && WC()->session && WC()->session->get('wpcot_tips') === null) {
			WC()->session->set('wpcot_tips', null);
		}
		return;
	}

	// Set a default tipping option from OLO plugin
	$tip_custom = [];
	$tips = array();
	if(class_exists('Wpcot_Helper')){
		$tips = Wpcot_Helper::get_tips();
	}

	if (is_page('checkout')) {
		$default_amount = sanitize_text_field(carbon_get_theme_option('olo_default_tipping_amount'));
		if ($default_amount !== '') {
			foreach ($tips as $key => $value) {
				if ($value["name"] === "tip") {
					$tip_custom[$key] = [
						'name'  => sanitize_text_field($value["name"]),
						'value' => $default_amount,
					];
					$wc_session = WC()->session;
					$wc_session->set('wpcot_tips', $tip_custom);
					break;
				}
			}
		}
	}
}
add_action('wp_head', 'olo_tipping_custom');


add_action('wp_ajax_get_cart_total', 'getcartTotal');
add_action('wp_ajax_nopriv_get_cart_total', 'getcartTotal');

function getcartTotal() {
    if ( class_exists( 'WooCommerce' ) ) {
        global $woocommerce;
        $cart_total = $woocommerce->cart->get_total();
        wp_send_json($cart_total);
    } else {
        error_log('WooCommerce not found');
        wp_send_json_error('WooCommerce not found');
    }
}

function customAjaxVariationThreshold( ) {
	return 400;
	}
	
	add_filter( 'woocommerce_ajax_variation_threshold', 'customAjaxVariationThreshold', 10, 2 );


    add_action('rest_api_init', function () {
		register_rest_route('northstaronlineordering/v1', '/totals', array(
			'methods' => 'GET',
			'callback' => 'getCartTotals',
		));
	});
	
	function getCartTotals() {
	  if (class_exists('WooCommerce')) {
		  $cart = WC()->cart;
		  if ($cart) {
			  $cartTotal = $cart->get_total();
			  return rest_ensure_response($cartTotal);
		  } else {
			  return rest_ensure_response('Cart not available');
		  }
	  } else {
		  return rest_ensure_response('WooCommerce not found');
	  }
  }


function customLoginDialog () {
	if ( is_cart() && ! is_user_logged_in() && get_option('cbs_login_cart') ) {
        return (new LoginForm)->render();
    }

}
add_action('wp_footer', 'customLoginDialog');


function giftcardForm() {
	if(get_option('siteMode', 'olo') === 'olo'){
		return (new GiftCardForm())->render();
	}
}
add_action('woocommerce_review_order_before_payment', 'giftcardForm');
 

function loyaltyPopup() {
	if(get_option('siteMode', 'olo') === 'olo'){
    return (new LoyaltyPopup())->render();
	}
}
add_action('woocommerce_review_order_before_payment', 'loyaltyPopup');


function processProductComponents($productComponent, $productComponentQty) {

	$componentname = [];
	$componentids = [];

	foreach ($productComponent as $componentvalue) {
		$compqty = getComponentQuantity($productComponentQty, $componentvalue['componentId']);
		$componentName = formatComponentNameWithLocation($componentvalue['componentName'], $componentvalue['componentId'], $compqty);
		$componentServingOptions = getComponentServingOptions($componentvalue['servingOptions']);

		if ($compqty != 0) {
			$componentCatname = $componentvalue['key'];
			        $componentData = [
				'name' => $componentName,
				'price' => isset($componentvalue['rulePrice'])
					? (float) $componentvalue['rulePrice']
					: ComponentPricing::calculateLineTotal($componentvalue, $compqty),
				'servingOptions' => $componentServingOptions
			];
			if (!empty($componentname[$componentCatname])) {
				array_push($componentname[$componentCatname], $componentData);
			} else {
				$componentname[$componentCatname] = [$componentData];
			}
			$servingOptionIds = [];
			if (!empty($componentServingOptions)) {
				foreach ($componentServingOptions as $option) {
					$servingOptionIds[] = $option['servingOptionId'];
				}
			}
			$componentids[$componentvalue['componentId']] = [
				'quantity' => $compqty,
				'servingOptionIds' => $servingOptionIds
			];
		}
	}


	return ['componentname' => $componentname, 'componentids' => $componentids];
}

function getComponentServingOptions($servingOptions) {
	$componentServingOptions = [];
	if (!empty($servingOptions)) {
		foreach ($servingOptions as $option) {
			$componentServingOptions[] = [
				'servingOptionId' => $option['servingOptionId'],
				'servingOptionName' => $option['servingOptionName'],
				'price' => (float) ($option['price'] ?? $option['servingOptionPrice'] ?? 0)
			];
		}
	}
	return $componentServingOptions;
}

function getComponentQuantity($productComponentQty, $componentId) {
	foreach ($productComponentQty as $pcq) {
		if ($pcq['componentId'] == $componentId) {
			return $pcq['quantity'];
		}
	}
	return 0;
}

function formatComponentNameWithLocation($componentName, $componentId, $compqty) {
	$addon_loc = "";
	if (strpos($componentId, "left") !== false) {
		$addon_loc = "(Left)";
	} elseif (strpos($componentId, "right") !== false) {
		$addon_loc = "(Right)";
	}
	return $componentName . $addon_loc . "(" . $compqty . ")";
}

function generateComponentHtml($componentname) {
	$componentHtml = '';
	foreach ($componentname as $key => $componentsvalue) {
		if (!empty($componentsvalue)) {
				$componentHtml .= buildComponentHtmlSection($componentsvalue);
		}
	}
	return $componentHtml;
}

/**
 * `<span>` with the formatted price, or '' when the price is $0.00 (included/free items
 * keep showing just name + quantity, unchanged from before this price display was added).
 */
function formatComponentPriceHtml($price) {
	if (empty($price) || (float) $price <= 0) {
		return '';
	}
	return '<span class="component-price">' . wc_price((float) $price) . '</span>';
}

function buildComponentHtmlSection($componentsvalue) {
	$leftstatus = $rightstatus = $wholestatus = 0;
	$componentHtml = '<div class="cart_components"><p class="head_cart_comp"></p><dl>';
	$componentHtmlLeft = $componentHtmlRight = $componentHtmlWhole = '';

	foreach ($componentsvalue as $value) {
		$servingOptionsHtml = '';
		$name = $value['name'];
		$priceHtml = formatComponentPriceHtml($value['price'] ?? 0);
		$servingOptions = $value['servingOptions'] ?? [];

        if (!empty($servingOptions)) {
            $servingOptionsHtml .= '<ul class="serving-options">';
            foreach ($servingOptions as $option) {
                $servingOptionsHtml .= '<li><span class="component-row"><span class="component-name">' . esc_html($option['servingOptionName']) . '</span>' . formatComponentPriceHtml($option['price'] ?? 0) . '</span></li>';
            }
            $servingOptionsHtml .= '</ul>';
        }

		list($leftstatus, $rightstatus, $wholestatus, $componentHtmlLeft, $componentHtmlRight, $componentHtmlWhole) =
		processComponentValue($name, $leftstatus, $rightstatus, $wholestatus, $componentHtmlLeft, $componentHtmlRight, $componentHtmlWhole, $servingOptionsHtml, $priceHtml);

	}

	if ($leftstatus) {
		$componentHtml .= '<dt>Left</dt>' . $componentHtmlLeft;
	}
	if ($rightstatus) {
		$componentHtml .= '<dt>Right</dt>' . $componentHtmlRight;
	}
	if ($wholestatus) {
		$wholeTitle = $leftstatus || $rightstatus ? '<dt>Whole</dt>' : '';
		$componentHtml .= $wholeTitle . $componentHtmlWhole;
	}

	$componentHtml .= '</dl></div>';

	return $componentHtml;
}

function buldServingOptionHtlmSection($servingOptions) {
	$componentHtml = '<div class="cart_servingoptions"><dl>';

	foreach ($servingOptions as $value) {
		$componentHtml .= '<dd><span class="component-row"><span class="component-name">' . esc_html($value['optionName']) . '</span>' . formatComponentPriceHtml($value['optionPrice'] ?? 0) . '</span></dd>';
	}

	$componentHtml .= '</dl></div>';

	return $componentHtml;
}

/**
 * Process component value and generate proper HTML structure.
 *
 * @param string $value Component name with location suffix.
 * @param int    $leftstatus Left section status flag.
 * @param int    $rightstatus Right section status flag.
 * @param int    $wholestatus Whole section status flag.
 * @param string $componentHtmlLeft Accumulated left HTML.
 * @param string $componentHtmlRight Accumulated right HTML.
 * @param string $componentHtmlWhole Accumulated whole HTML.
 * @param string $servingOptionsHtml Serving options HTML.
 * @param string $priceHtml Formatted component price HTML (empty when $0.00).
 * @return array Updated status flags and HTML strings.
 */
function processComponentValue(
	string $value,
	int $leftstatus,
	int $rightstatus,
	int $wholestatus,
	string $componentHtmlLeft,
	string $componentHtmlRight,
	string $componentHtmlWhole,
	string $servingOptionsHtml,
	string $priceHtml = ''
): array {
	$leftflag = strpos($value, '(Left)');
	$rightflag = strpos($value, '(Right)');
	$cleanValue = str_replace(['(Left)', '(Right)'], '', $value);

	// Build component item: name + optional price + optional serving options
	$componentItem = '<dd><span class="component-row"><span class="component-name">' . esc_html($cleanValue) . '</span>' . $priceHtml . '</span>';
	if (!empty($servingOptionsHtml)) {
		$componentItem .= $servingOptionsHtml;
	}
	$componentItem .= '</dd>';

	if ($leftflag !== false) {
		$leftstatus = 1;
		$componentHtmlLeft .= $componentItem;
	} elseif ($rightflag !== false) {
		$rightstatus = 1;
		$componentHtmlRight .= $componentItem;
	} else {
		$wholestatus = 1;
		$componentHtmlWhole .= $componentItem;
	}

	return [$leftstatus, $rightstatus, $wholestatus, $componentHtmlLeft, $componentHtmlRight, $componentHtmlWhole];
}
add_filter('woocommerce_checkout_fields', 'addLocationField',15);

function addTipSection() {
	if(carbon_get_theme_option('olo_tip_over_payment') && class_exists('Wpcot_Helper')){
		echo '<div class="olo-tipping-section">';
		echo do_shortcode('[wpcot]');
		echo '</div>';
	}
}
add_filter('woocommerce_review_order_before_payment', 'addTipSection');

function addLocationField($fields) {
	$locationNumber = WC()->session->get( 'location_number' ) ? WC()->session->get( 'location_number' ) : '';
	if ( isset( $_COOKIE['cbs_location_number'] ) ) {
		$locationNumber = sanitize_text_field( $_COOKIE['cbs_location_number'] );
  	} elseif (WC()->session && WC()->session->has_session()) {
		$locationNumber = WC()->session->get('location_number');
	}

	$label = 'Location';
	if(!empty(get_option('olo_location_field_label'))){
		$label = get_option('olo_location_field_label');
	}
	if(get_option('olo_enable_location_field')){
		$fields['billing']['location-input'] = array(
			'type'        => 'textarea',
			'label'       => $label,
			'placeholder' => __('Add location'),
			'required'    => true,
			'class'       => array('form-row-wide'),
			'priority'    => 120,
		);

		if ( ! empty( $locationNumber ) && get_option('olo_disable_location_field', false) ) {
			$fields['billing']['location-input']['custom_attributes'] =  array('readonly' => 'readonly');
		}
	}
    return $fields;
}
add_filter('woocommerce_checkout_get_value', 'setDefaultLocationValue', 10, 2);

function setDefaultLocationValue($input, $key) {
	global $woocommerce;
	if ( isset( $_COOKIE['cbs_location_number'] ) ) {
		$locationNumber = sanitize_text_field( $_COOKIE['cbs_location_number'] );
		if ( $key === 'location-input' && !empty( $locationNumber ) ) {
			return $locationNumber;
		}
	}

    return $input;
}



add_action( 'woocommerce_checkout_update_order_meta', 'saveLocationNameCheckouField' );

function saveLocationNameCheckouField( $order_id ) {
    if ( ! empty( $_POST['location-input'] ) ) {
        update_post_meta( $order_id, 'location-name', sanitize_text_field( $_POST['location-input'] ) );
    }
}

new OutofStockProducts();
add_action('woocommerce_checkout_process', 'validateCheckoutPhoneNumber' );

/**
 * Whether $phone matches the digits-only, 10-15 character format required for
 * billing_phone. Shared by checkout (woocommerce_checkout_process) and My
 * Account address save (woocommerce_after_save_address_validation) so both
 * places enforce the same rule instead of drifting apart.
 */
function isValidPhoneNumberFormat( $phone ) {
	return (bool) preg_match( '/^[0-9]{10,15}$/', $phone );
}

function getInvalidPhoneNumberMessage() {
	return __( 'Please enter a valid phone number. Only digits are allowed, and the number must be between 10 and 15 characters long.', 'cbs' );
}

function validateCheckoutPhoneNumber() {

	$fieldBillingRequired = checkBillingFieldRequired('billing_phone');

	if($fieldBillingRequired){
		if ( isset( $_POST['billing_phone'] ) && ! empty( $_POST['billing_phone'] ) ) {
        $phone = sanitize_text_field( $_POST['billing_phone'] );

        if ( ! isValidPhoneNumberFormat( $phone ) ) {
            wc_add_notice( getInvalidPhoneNumberMessage(), 'error' );
        }
    }
	}
	if ( empty($_POST['location-input']) && get_option('olo_enable_location_field') ) {
        wc_add_notice( __( 'Please enter your location.' ), 'error' );

    }
}

/**
 * Enforce the same billing_phone format on My Account -> Edit Address (billing)
 * that checkout already enforces. WooCommerce's own default check
 * (WC_Validation::is_phone()) only rejects a handful of stray characters, with
 * no length or shape requirement, so without this My Account and checkout
 * disagree on what counts as a valid phone number.
 *
 * woocommerce_after_save_address_validation fires from
 * WC_Form_Handler::save_address(); adding an 'error' notice here blocks the
 * save (WooCommerce checks wc_notice_count('error') immediately after this
 * hook and returns before $customer->save() if any are present).
 */
add_action( 'woocommerce_after_save_address_validation', 'validateMyAccountBillingPhoneNumber', 10, 2 );

function validateMyAccountBillingPhoneNumber( $user_id, $address_type ) {
	if ( 'billing' !== $address_type ) {
		return;
	}

	if ( ! checkBillingFieldRequired( 'billing_phone' ) ) {
		return;
	}

	if ( empty( $_POST['billing_phone'] ) ) {
		return;
	}

	$phone = sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) );

	if ( ! isValidPhoneNumberFormat( $phone ) ) {
		wc_add_notice( getInvalidPhoneNumberMessage(), 'error' );
	}
}

/**
 * Enforce the same phone format on My Account -> Edit Address (shipping) that
 * billing already enforces, on the sites where a shipping_phone field is
 * present. WooCommerce core only auto-adds a phone field for billing_
 * (WC_Countries::get_address_fields()), so shipping_phone only exists where a
 * site's own theme adds one — $_POST['shipping_phone'] is simply absent
 * everywhere else, making this a no-op there.
 *
 * Unlike validateMyAccountBillingPhoneNumber(), this does NOT gate on
 * checkBillingFieldRequired('shipping_phone'): a value a customer actually
 * typed in should be checked for shape regardless of whether that site marks
 * the field required.
 */
add_action( 'woocommerce_after_save_address_validation', 'validateMyAccountShippingPhoneNumber', 10, 2 );

function validateMyAccountShippingPhoneNumber( $user_id, $address_type ) {
	if ( 'shipping' !== $address_type ) {
		return;
	}

	if ( empty( $_POST['shipping_phone'] ) ) {
		return;
	}

	$phone = sanitize_text_field( wp_unslash( $_POST['shipping_phone'] ) );

	if ( ! isValidPhoneNumberFormat( $phone ) ) {
		wc_add_notice( getInvalidPhoneNumberMessage(), 'error' );
	}
}

function checkBillingFieldRequired($field){
	if(function_exists('WC')){
		$allFields = WC()->checkout->get_checkout_fields();
		foreach($allFields as $section){
			if(isset($section[$field]) && isset($section[$field]['required']) && $section[$field]['required']){
				return ! empty( $section[$field]['required'] );
			}
		}
	}
}



add_action('wp_loaded', function() {
    if ( isset($_GET['location']) ) {
       $location  = sanitize_text_field($_GET['location']);

/*             if ( WC()->session ) {
                WC()->session->set('location_number',  $location);
            } */
			setcookie(
				'cbs_location_number',
				$location,
				time()+86400,
				'/',
				"",
				is_ssl(),
				true
			);
    }
});

function addProductCategoryDescriptionVisibleField($term, $taxonomy) {

    $visibility = get_term_meta($term->term_id, 'category_description_visibility', true);
    ?>
    <tr class="form-field">
        <th scope="row">
            <label for="category_description_visibility"><?php _e('Show Product Category Description', 'cbs'); ?></label>
        </th>
        <td>
            <input type="checkbox" name="category_description_visibility" id="category_description_visibility" value="1" <?php checked(1, $visibility); ?> />
            <p class="description"><?php _e('Check this box to show the product category description.', 'cbs'); ?></p>
        </td>
    </tr>
    <?php
}
add_action('product_cat_edit_form_fields', 'addProductCategoryDescriptionVisibleField', 10, 2);


function saveProductCategoryDescriptionVsibleField($term_id) {

    if (isset($_POST['category_description_visibility'])) {
        update_term_meta($term_id, 'category_description_visibility', 1);
    } else {
        delete_term_meta($term_id, 'category_description_visibility');
    }
}
add_action('edited_product_cat', 'saveProductCategoryDescriptionVsibleField');

/**
 * Add taxable badge to order item display name
 * 
 * @param string $item_name Order item name
 * @param WC_Order_Item_Product $item Order item object
 * @param bool $is_visible Whether the item is visible
 * @return string Modified item name with taxable badge if applicable
 */
function cbsAddTaxableBadgeToOrderItem(string $item_name, WC_Order_Item_Product $item, bool $is_visible): string {
	if (!$is_visible) {
		return $item_name;
	}

	if (!function_exists('carbon_get_theme_option') || !carbon_get_theme_option('olo_show_taxable_tag')) {
		return $item_name;
	}

	$isTaxable = $item->get_meta('_cbs_is_taxable', true);
	if ($isTaxable === '1') {
		$item_name .= ' <span class="cbs-taxable-badge">Taxable</span>';
	}

	return $item_name;
}

/**
 * Register taxable badge filter for order display after Carbon Fields is loaded
 * 
 * @return void
 */
function cbsRegisterOrderTaxableBadgeFilter(): void {
	if (function_exists('carbon_get_theme_option') && carbon_get_theme_option('olo_show_taxable_tag')) {
		add_filter('woocommerce_order_item_name', 'cbsAddTaxableBadgeToOrderItem', 20, 3);
	}
}
add_action('carbon_fields_fields_registered', 'cbsRegisterOrderTaxableBadgeFilter');

/**
 * Enhance billing phone field accessibility by adding ARIA attributes
 */
add_filter('woocommerce_form_field_args', 'enhanceBillingPhoneAccessibility', 10, 2);
add_filter('woocommerce_form_field', 'injectPhoneLabelId', 10, 4);

/**
 * Add label_id and aria-labelledby to billing phone field arguments
 *
 * @return array Modified field arguments
 */
function enhanceBillingPhoneAccessibility(array $args, string $key): array
{
	if ($key !== 'billing_phone') {
		return $args;
	}

	$args['label_id'] = 'billing_phone_label';
	$args['custom_attributes']['aria-labelledby'] = 'billing_phone_label';

	return $args;
}

/**
 * Inject id attribute into billing phone field label element
 *
 * @return string Modified field HTML
 */
function injectPhoneLabelId(string $field, string $key, array $args, $value): string
{
	if ($key !== 'billing_phone' || empty($args['label_id'])) {
		return $field;
	}

	return str_replace(
		'<label',
		'<label id="' . esc_attr($args['label_id']) . '"',
		$field
	);
}

// Pre-fill checkout fields with user meta data if available
add_filter('woocommerce_checkout_get_value', function ($value, $input) {

    if (!empty($value)) {
        return $value;
    }

    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        $meta = get_user_meta($user_id, $input, true);

        if (!empty($meta)) {
            return $meta;
        }
    }

    return $value;

}, 10, 2);

/**
 * Format Product Component display in emails to match thank you page format.
 * Adds CSS to make the label take full width so components appear on the next line.
 */
add_filter('woocommerce_email_styles', 'cbs_add_product_component_email_styles');
function cbs_add_product_component_email_styles($css) {
    $css .= '
        .wc-item-meta li strong.wc-item-meta-label {
            display: block;
            width: 100%;
        }
    ';
    return $css;
}