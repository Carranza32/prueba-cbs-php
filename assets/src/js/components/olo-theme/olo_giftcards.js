//handle giftcards in online ordering

(function() {
    // Exit early if in kiosk mode
    if (document.querySelector('.checkout-block.kiosk')) {
        return;
    }

    const giftCardNumberInput = document.getElementById('gift-card');
    const giftCardErrorMesssage = document.querySelector('.validation-message');
    const submitGiftCardBtn = document.getElementById('submit-gift-card');
    const giftCardSpinner = document.getElementById('giftcard-spinner');


    const handleGifCardVerify = async (e) => {
        e.preventDefault();
        if(!giftCardNumberInput.value.trim()) {
            handleBadRequest({message: 'Please enter a gift card number'});
            return;
        }

        giftCardSpinner.style.display = 'flex';
        giftCardErrorMesssage.style.display = 'none';
        const bodyData = {
            action: 'add_gift_card_action',
            giftcard: giftCardNumberInput.value.trim(),
        };
        try {
            const response = await fetch(olo_vars_object.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams(bodyData)
            });

            giftCardSpinner.style.display = 'none';
            const data = await response.json();
            if(response.ok) {
                const {error} = data.data;
                if(!error){
                    handleGiftCardSuccess(data);
                    return;
                }
            }

            handleBadRequest(data.data);
        } catch (error) {
            giftCardSpinner.style.display = 'none';
            handleBadRequest({message: 'An unexpected error occurred. Please try again.', error});
        }
    }
    const handleGiftCardSuccess = (data) => {
        console.log(data);
        jQuery(document.body).trigger('update_checkout');
        giftCardNumberInput.value = '';
    }

    jQuery(document).ready(function($) {
        $(document.body).on('update_checkout', function() {
            $('.overlay-update-checkout').removeClass('hide');
            console.log('update_checkout triggered');
        });
    });


    const handleBadRequest = (data) => {
        giftCardErrorMesssage.textContent = data.message;
        giftCardErrorMesssage.style.display = 'block';
        console.error(data.message, data.error);
    }

    submitGiftCardBtn?.addEventListener('click', handleGifCardVerify);
    giftCardNumberInput?.addEventListener('input', (e) => {
        if(e.target.value.trim()) {
            submitGiftCardBtn.disabled = false;
        } else {
            submitGiftCardBtn.disabled = true;
        }
    });

    document.addEventListener('DOMContentLoaded', () => {
        if(giftCardSpinner) {
            giftCardSpinner.style.display = 'none';
        }
        if(giftCardErrorMesssage) {
            giftCardErrorMesssage.style.display = 'none';
        }
    });

    async function handleDeleteGiftCard(event) {
        if(!event.target || !event.target.dataset.cardnumber) {
            console.error('Gift card number not found on delete button.');
            return;
        }
        const giftCard = event.target.dataset.cardnumber;

        const bodyData = {
            action: 'delete_gift_card_action',
            giftcard: giftCard,
        };
        event.target.innerHTML = '<span id="loader-spinner"></span>';
        try {
            const response = await fetch(olo_vars_object.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams(bodyData)
            });
            const data = await response.json();
            if(response.ok) {
                jQuery(document.body).trigger('update_checkout');
                return;
            }
            console.error(data);
        } catch (error) {
            console.error('Network error occurred. Please try again.', error);
        }
    }

    function setupDeleteGiftcardButtons() {
        const deleteGiftCard = document.querySelectorAll('.delete-giftcard');
        deleteGiftCard?.forEach((element) => {
            element.addEventListener('click', handleDeleteGiftCard);
        });

    }

    jQuery(document).ready(function($) {
        $(document).ajaxComplete(function(event, xhr, settings) {
            if (settings.url.indexOf('wc-ajax=update_order_review') > -1) {
                setupDeleteGiftcardButtons();
            }
        });
    });
})();