/**
 * Northstar Cart Edit Handler
 *
 * Pre-populates product page with cart item data when editing.
 *
 * @package NorthstarOnlineOrdering
 */

(function($) {
    'use strict';

    if (typeof northstarCartEdit === 'undefined' || !northstarCartEdit.isEditing) {
        return;
    }

    const CartEditManager = {
        init() {
            this.updateButtonText();
            this.prePopulateQuantity();
            this.prePopulateSelectedFor();
            this.handleFormSubmit();
        },

        updateButtonText() {
            const $button = $(".single_add_to_cart_button");
            if ($button.length) {
                $button.text(northstarCartEdit.i18n.updateCart);
            }
        },

        prePopulateQuantity() {
            const quantityDisplay = document.querySelector(".quantity-number");
            const quantityInput = document.querySelector('form.cart input[name="quantity"]');
            const quantityValue = northstarCartEdit.quantity;

            if(!quantityValue) {
                return;
            }
            if (quantityDisplay) {
                quantityDisplay.textContent = quantityValue;
            }
            if (quantityInput) {
                quantityInput.value = quantityValue;
            }
        },

        handleFormSubmit() {
            $("form.cart").on("submit", (e) => {
                const $form = $(e.currentTarget);

                // Add hidden field with cart item key for removal
                if (!$form.find('input[name="edit_cart_item_key"]').length) {
                    $form.append(
                        $("<input>", {
                            type: "hidden",
                            name: "edit_cart_item_key",
                            value: northstarCartEdit.cartItemKey,
                        })
                    );
                }

                // Add nonce field for security
                if (!$form.find('input[name="edit_cart_item_nonce"]').length) {
                    $form.append(
                        $("<input>", {
                            type: "hidden",
                            name: "edit_cart_item_nonce",
                            value: northstarCartEdit.nonce,
                        })
                    );
                }
            });
        },
        prePopulateSelectedFor() {
            const selectedForInput = document.querySelector('form.cart input[name="item_selected_for"]');
            const selectedForValue = northstarCartEdit.selectedFor;

            if (selectedForInput && selectedForValue) {
                selectedForInput.value = selectedForValue;
            }
        },
    };

    $(document).ready(() => {
        CartEditManager.init();
    });

})(jQuery);
