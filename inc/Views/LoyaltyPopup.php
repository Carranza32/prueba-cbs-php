<?php
namespace CBSNorthStar\Views;

class LoyaltyPopup {
    public function render(){
        $disableLoyalty = carbon_get_theme_option('olo_disable_olo_rewards');
        if (!$disableLoyalty) {
             ?>
             <button id="loyalty-button" class="button"  type="button">Rewards</button>
             <?php
        }
        ?>
        <dialog id="loyalty-dialog" class="rewards-dialog">
                <div class="rewards-dialog-top">
                    <button id="rewards-close" class='rewards-dialog-close'><span class="sr-only">Close</span>&times;</button>
                </div>
                    <div class='rewards-dialog-title'>
                        <h1>Rewards</h1>
                    </div>
                    
                    <div id="loader-box" class='rewards-dialog-loading'>
                        <span class="loader"></span>
                    </div>

                    <div id="rewards-content" class="rewards-dialog-content" style="display: none;">
                    </div>

                    <div class='rewards-dialog-bottom'>
                        <button id="rewards-dialog-done" class='reward-button done button' type="button">Done</button>
                    </div>
            </dialog>

            <dialog id="phone-number-dialog" class="rewards-dialog">
                <div class="rewards-dialog-top">
                    <button id="phone-number-dialog-close" class='rewards-dialog-close'><span class="sr-only">Close</span>&times;</button>
                </div>
                    <div class='rewards-dialog-title'>
                        <h1>Please enter your phone number</h1>
                    </div>

                    <input type="tel" name="customerPhone" id="customerPhoneNumber" inputmode="numeric" maxlength="14" autofocus>
                    <div id="phone-error-message" class="phone-error-message">
                        Please enter a valid phone number
                    </div>

                    <div class='rewards-dialog-bottom'>
                        <button id="phone-number-done" class='dialog-button button'>Done</button>
                    </div>
            </dialog>
    <?php }
}
