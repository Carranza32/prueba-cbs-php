<?php
namespace CBSNorthStar\Views;

class GiftCardForm{
    public function render(){
    if (function_exists('carbon_get_theme_option') && carbon_get_theme_option('olo_disable_olo_giftcard')) {
        return;
    }
        ?>
        <div id="giftcard-form">
            <h2>Gift Card</h2>
            <div id="giftcard-section" class="giftcard-section">
                <div>
                    <input type="text" id="gift-card" name="giftcard" placeholder="Enter Gift Card Number" required>
                    <p class="error-message validation-message" style="display: none;">Error</p>
                </div>
                <button id="submit-gift-card" class="button submit-button" type="button" title="Add Gift Card" disabled>Add <span id="giftcard-spinner" style="display: none;"></span></button>
            </div>
        </div>

    <?php }
}
