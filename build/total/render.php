<?php
global $woocommerce;
$total = "$0.00";
$empty = true;
$phonePromptRedirect = get_option('disable_phone_prompt', false);
$phonePromptField = get_option('disable_phone_field', false);
if (class_exists('WooCommerce') && $woocommerce && is_object($woocommerce->cart)) {
    $total = $woocommerce->cart->get_total();
    $empty= $woocommerce->cart->is_empty();
} else {
    $total = "$0.00";
}
?>



<div class="cbs-block-total-container">
<div id="cbsphone" ><span id="cbsphone"  class="dashicons dashicons-phone"></span></div>
<div id="cbsuser" class="<?php echo $phonePromptRedirect ? 'hide-icon' : ''; ?> "> <span  class="dashicons dashicons-admin-users"></span> </div>
<div class="cart-total"> Total: <?php echo $total; ?> </div>
<div id="checkout-button" class="checkout-button"  data-isempty="<?php echo $empty? 'true': 'false'; ?>" data-prompt-phone="<?php echo $phonePromptRedirect ? 'true':'false'; ?>" data-prompt-phone-field="<?php echo $phonePromptField ? 'true':'false'; ?>" >   </div>

<div id="upsell-modal" class="modal-container" >
    <div class="modal-content">
        <div class="modal-header">
           <div id="close-icon" class="close-icon">  </div>
        </div>
        <?php echo do_shortcode($attributes["shortcodes"]); ?>
        <div id="upsells-modal-footer" class="modal-footer" >
            <button class="button-close">Proceed to Checkout</button>
        </div>
    </div>
</div>
</div>
