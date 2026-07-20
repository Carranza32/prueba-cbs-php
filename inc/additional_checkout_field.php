<?php
/**
 * Additional checkout field
 *
 * Used to create additonal fields on checkout page and order merge functionality
 *
 */

use CBSNorthStar\Dto\CartOrderReviewDto;
use CBSNorthStar\Repositories\CartRecordRepository;
use CBSNorthStar\Order\OrderQR;


include_once plugin_dir_path(__DIR__) . 'cbs_functions.php';
require_once dirname(__FILE__).'/Repositories/CartRecordRepository.php' ;
require_once dirname(__FILE__).'/Order/OrderQR.php';
require_once dirname(__FILE__).'/Order/Check.php';

add_action( 'wp_ajax_session_request', 'setSessionCallback');
add_action( 'wp_ajax_nopriv_session_request', 'setSessionCallback');


function setSessionCallback() {

    $cartitemkey_arr = json_decode(stripslashes($_COOKIE['cartitemkey_arr_init']) , true);
    $siteid=$_COOKIE['siteid'];
    $northstar_json = $_COOKIE[ 'northstar_json' ];
    $table_num=(int)$_COOKIE['table_num'];
    $areaId = sanitize_text_field( $_COOKIE['area_external_code'] ?? '' );

    //set cookie to avoid sending double items
    foreach ($cartitemkey_arr as $itemkey => $itemvalue) {
        setcookie($itemkey,$itemvalue, time()+86400, '/', null, is_ssl() , true );
    }
    $orderQR = new OrderQR();
    $responseQR = $orderQR->qrHandler($siteid, $northstar_json , $table_num, $areaId , $cartitemkey_arr );

    wp_send_json_success($responseQR);
}

$pay_later_control=false;

if(isset($_COOKIE['pay_later_control']) && $_COOKIE['pay_later_control']=="Enabled"){
    $pay_later_control=true;
}else{
    $pay_later_control=false;
}

if(isset($_COOKIE['table_num']) && $pay_later_control==true){
    if(isset($_COOKIE['area_id'])){
        if(!isset($_COOKIE["locationinvalid"])){
            add_action( 'woocommerce_proceed_to_checkout', 'actionWoocommerceProceedToCheckout', 10);
        }
    }
}

/**
 * Adding html by using woocommerce_proceed_to_checkout hook
 * */
function actionWoocommerceProceedToCheckout() {
    if(isset($_COOKIE["checkClosed"])){
        global $woocommerce;
        $woocommerce->cart->empty_cart();
        $scMsg = "Check is finalized";
        wc_print_notice(__($scMsg), 'success');
        unset($_COOKIE["checkClosed"]);
        return 0;
    }

    $cart = WC()->cart;
    $deliveryDate=date("Y-m-d H:i:s");
    if($_COOKIE['table_num']!="" && $_COOKIE['pay_later_control']=="Enabled"){
        $orderType=0;
    }else{
        $orderType=1;
    }

    $payload = (new CartOrderReviewDto([
      'order'         => $cart,
      'orderType'     => $orderType,
      'deliveryDate'  => $deliveryDate,
      'orderItems'    => $cart->get_cart(),
      'tableNumber'   => (int) ( $_COOKIE['table_num'] ?? 0 ),
      'areaExternalCode'        => sanitize_text_field( $_COOKIE['area_external_code'] ?? '' ),
    ]))->toJson();

    setcookie('northstar_json', $payload, time()+86400, '/', null, is_ssl() , true );

    ?>

    <div style="margin-bottom: 50px">
        <form action=""  name="check_id_submit_form">
            <div class="chkid_btn" style="padding:10px 0px 10px 0px">
                <input type="text"
                       id="checkid_input"
                       name="checkid_input"
                       placeholder="Enter Check Id"
                       style="display:none">
                <input type="button"
                       id="submit_items_kitchen"
                       onclick="sendTokitchen()"
                       name="checkid_submit"
                       class="checkid_submit"
                       value="Submit"
                       style="width:33%" disabled><i class="fa fa-spinner fa-spin submit"></i>
            </div>
        </form>
        <div class="woapi-response" >
        </div>
    </div>
    </div>

    <?php
    $checkid_exist=false;

    ?>
    <script>
        <?php
        if(isset($_COOKIE['success_item_qr'])){
        ?>
        placeholder = document.querySelector('.woapi-response');
        placeholder.innerHTML = "<div class='success'> Successfully submit, Check Number: <?php echo $_COOKIE['checknumber'];?>, Location Table: <?php echo $_COOKIE['table_num'];?> </div>";
        <?php
         setcookie('success_item_qr',"", time() - 3600, '/', null, is_ssl() , true );
        }
        ?>
        <?php
        ?>
    </script>
    <?php
}

/**
 ** Remove quantity selector if item already sent to kitchen
 */


if(isset($_COOKIE['cart_item_arr']) ){
    add_filter( 'woocommerce_cart_item_quantity', 'cbsWcCartItemQuantity', 50, 3 );

    function cbsWcCartItemQuantity($productQuantity, $cartItemKey, $cartItem ){

        $cartItmArr = json_decode(stripslashes($_COOKIE['cart_item_arr']));
        if( is_cart() ){
            foreach ($cartItmArr as $key => $value){
                if($cartItemKey==$key){
                    $productQuantity = sprintf(
                            '%2$s <input type="hidden" name="cart[%1$s][qty]" value="%2$s" />',
                            $cartItemKey,
                            $cartItem['quantity']
                    );
                }
            }
        }return $productQuantity;
    } // function finished cbs_wc_cart_item_quantity

    add_filter('woocommerce_cart_item_remove_link', 'cbsCartItemRemoveLink', 20, 2 );

    function cbsCartItemRemoveLink($buttonLink, $cartItemKey){
        $items = array();
        // If the targeted product is in cart we remove the button link
        $items = json_decode(stripslashes($_COOKIE['cart_item_arr']) , true);
        
        if(in_array($cartItemKey, $items , true)){
            $buttonLink = '';
        }
        return $buttonLink;
    } //function finished cbs_cart_item_remove_link
}//finished isset $_SESSION['cart_item_arr']


add_action( "wp_loaded", function() {
    if(isset($_COOKIE['cart_item_arr']) ){
        add_filter( 'woocommerce_cart_item_quantity', 'cbsWcCartItemQuantity', 50, 3 );
        add_filter('woocommerce_cart_item_remove_link', 'cbsCartItemRemoveLink', 20, 2 );
    }
});
