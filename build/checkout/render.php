<?php
use CBSNorthStar\Models\Cart;



if (!function_exists('WC') || is_null(WC()->cart)) {
    ?> <h3> CBS Checkout Block </h3> <?php
    return [];
  }
  $cart = (new Cart())->load();

foreach($cart["items"] as $product){
    $variations = "";
    $variationsData = "";
    foreach ($product["variation"] as $key => $value) {
        $variations .= '<li>' . $value . '</li>';
        $variationsData .= $value . " ";
    }
}

$promocodehide = get_option('disable_promo_code') ? 'hide' : '';
$giftcardhide = get_option('disable_gift_card') ? 'hide' : '';

$tipIcon = plugin_dir_path(__FILE__) .'../../img/tip-icon.svg';
$promoCodeIcon = plugin_dir_path(__FILE__) .'../../img/promo-code-icon.svg';
$giftCardIcon = plugin_dir_path(__FILE__) .'../../img/giftcard-icon.svg';
?>
<div class="checkout-block kiosk">
    <div class="checkout-cart">
    <div id="cart-list" class="cart-list-container" >
        <h1>Order Summary </h1>
        <div class="cart-list">
            <?php
            foreach($cart["items"] as $product){
                $variations = "";
                $variationsData = "";
                foreach ($product["variation"] as $key => $value) {
                    $variations .= '<li>' . $value . '</li>';
                    $variationsData .= $value . " ";
                }
                ?>

                <div class="cart-item">
                    <div class="cart-item-image empty">
                        <img src="<?php echo $product["image"] ? $product["image"] : $attributes["defaultimage"] ; ?>" alt="">
                    </div>
                    <div class="cart-item-description">
                        <div class="cart-item-quantity">
                        X<?php echo $product["quantity"] ; ?>
                        </div>
                        <div class="cart-item-name">
                            <p class="item-title"><?php echo $product["name"] ; ?></p>
                            <div class="item-components">
                                <?php foreach($product['product_serving_options'] as $servingOption){ ?>
                                        <?php if(count($product['product_serving_options']) > 1 &&  (reset($product['product_serving_options'])['optionId'] != $servingOption['optionId'])) { ?>
                                            <span class="serving-option-separator"> , </span>
                                        <?php } ?>
                                    <?php echo $servingOption['optionName']; ?>
                                <?php } ?>
                                <p><?php echo  $variations ;?></p>
                                <p><?php echo $product["product_component"] ? $product["product_component"] : ""  ;?></p>
                            </div>
                        </div>
                    </div>
                    <div class="cart-item-price">
                        <p class="item-price"> $ <?php echo number_format($product["line_total"],2); ?> </p>
                        <?php
                        $item['quantity'] = $product["quantity"];
                        $item['cartItemKey'] = $product["key"];
                        $item['product_id'] = $product["product_id"];
                        $item['selectedComponents']=$product["product_component_id"];
                        $item['variationId']= $product["variation_id"];
                        $item['variationsData'] = $variationsData;
                        $itemData= htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8');
                        ?>
                        <div id="edit-icon" class="edit-icon" product-id="<?php echo $product["product_id"];?>" data-item="<?php echo $itemData ?>"></div>
                        <?php
                        echo sprintf('<a id="delete-icon" href="%s" product-id="%s" class="delete-icon" ></a>',
                            esc_url( wc_get_cart_remove_url( $product["key"] ) ),
                            $product["key"]
                        );
                        ?>
                    </div>
                </div>
        <?php  } ?>
        </div>
    </div>
    </div>
    <div class="cart-total-container" >
        <?php woocommerce_order_review(); ?>
    </div>
    <div class="order-buttons-container affiliate">
        <button class="button" id="checkout-tip" style="display:none;"><span><?php echo file_get_contents($tipIcon)?></span><span>Add tip</span></button>
        <div id="loyalty-container" data-promocode="<?php echo $promocodehide; ?>" data-giftcard-hide="<?php echo $giftcardhide; ?>"></div>
        <button class="button <?php echo $giftcardhide ? 'disabled' : ''; ?>" id="checkout-gift-card"><span><?php echo file_get_contents($giftCardIcon)?></span><span>Gift Card</span> </button>
    </div>

    <?php $availableGateways = WC()->payment_gateways()->get_available_payment_gateways(); ?>
    <?php $gatewaysCount = isset($availableGateways['northstar']) ? 2 : count($availableGateways); ?>
    <?php $actionButtons = $gatewaysCount > 1 ? "cbs-quick-action-buttons" : "cbs-quick-action-button"; ?>


    <div id="cbs-quick-action-buttons" class="order-buttons-container payment-step" data-gateways-quantity="<?php echo $gatewaysCount; ?> " slug="<?php echo $attributes["slug"]; ?>" data-paymentlabel="<?php echo esc_attr(get_option('payment_button_label')) ? esc_attr(get_option('payment_button_label')) : 'Payment'; ?>">
        <button class="button" id="cancel-button">Cancel</button>
        <button class="button" onClick="closeModal" id="checkout-button">Payment</button>
    </div>
    <div >

    </div>

<div class="background-overlay"
    data-public-token="<?php echo esc_attr($attributes["publicToken"] ?? ""); ?>"
    data-instance-id="<?php echo esc_attr($attributes["instanceId"]); ?>"
>

<div id="cbs-payment-modal-container" class="payment  modal-container" data-button="<?php echo $attributes["button"] ?>">
    
    <div class="modal-content">
        <button id="cbs-payment-modal-close" class="close-button">
                <span id="close-dialog">&times;</span>
        </button>
        <h2 class="payment-method-dialog-title">Payment Method</h2>
        <form name="checkout" method="post" class="checkout woocommerce-checkout" action="<?php echo esc_url(wc_get_checkout_url()); ?>" enctype="multipart/form-data">
        <?php do_action('woocommerce_checkout_before_order_review_heading'); ?>
        <?php do_action('woocommerce_checkout_before_order_review'); ?>
        <?php do_action( 'woocommerce_checkout_order_review' );?>
        </form>
        <?php do_action( 'woocommerce_after_checkout_form', $checkout??'' ); ?>
        <div id="cbs-loader-overlay" class="modal-loader" >
            <div class="loader">
                <img src="<?php echo esc_url( $attributes["loader"]); ?>" alt="loading">
            </div>
        </div>
    </div>

</div>

<div class="gift-card-popup">
    <dialog id="gift-card-modal">
        <div id="giftcard-spinner" class="giftcard-spinner hide"><span>loading..</span></div>
        <div class="gift-card-modal-controls">
            <button id="close-gift-card-modal" class="close close-button">
                <span>&times;</span>
            </button>
        </div>
        <div id="gift-card-container" class="gift-card-container">
            <h2 class="gift-card-title title">Gif Card Number</h2>
            <div class="gift-card-field">
                <input type="text" id="gift-card" class="gift-card" name="gift-card" autofocus>
            </div>
            <div class="validation-message-container">
                <p class="validation-message hide">Invalid gift card number. Try again.</p>
            </div>
            <div class="numeric-pad">
                <button class="numeric-pad-button" value="1">1</button>
                <button class="numeric-pad-button" value="2">2</button>
                <button class="numeric-pad-button" value="3">3</button>
                <button class="numeric-pad-button" value="4">4</button>
                <button class="numeric-pad-button" value="5">5</button>
                <button class="numeric-pad-button" value="6">6</button>
                <button class="numeric-pad-button" value="7">7</button>
                <button class="numeric-pad-button" value="8">8</button>
                <button class="numeric-pad-button" value="9">9</button>
                <button class="numeric-pad-button zero-button" value="0">0</button>
                <button id="backspace-button" class="numeric-pad-button backspace-button" value="backspace"></button>
            </div>
        </div>
        <button id="submit-gift-card" class="submit-gift-card">Add</button>
    </dialog>
</div>

<div id="promo-code-popup">
</div>

</div>

 
