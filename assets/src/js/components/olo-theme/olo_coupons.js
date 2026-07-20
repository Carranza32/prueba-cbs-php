jQuery(document).on('click', '#olo-apply-coupon', function(e){
            e.preventDefault();

            jQuery(document.body).one('updated_checkout', function() {
                const couponInput = document.querySelector('input[name="olo_coupon_code"]');
                if (couponInput) couponInput.value = '';
            });

            jQuery(document.body).trigger('update_checkout');
        });



    document.addEventListener('click', function (e) {
    const btn = e.target.closest('.olo-remove-coupon');
    if (!btn) return;

    const coupon = (btn.dataset.coupon || '').trim();

    const form = document.querySelector('form.checkout');
    if (!form) return;

    const couponInput = form.querySelector('input[name="olo_coupon_code"]');
    if (couponInput) {
        couponInput.value = '';
    }


    let input = form.querySelector('input[name="olo_coupon_delete"]');
    if (!input) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'olo_coupon_delete';
        form.appendChild(input);
    }
    input.value = coupon;

    if (window.jQuery) {
        jQuery(document.body).one('updated_checkout', function () {
        input.value = '';
        btn.disabled = false;
        });

        jQuery('body').trigger('update_checkout');
    } else {
        window.location.reload();
    }
    });

    jQuery(document.body).on('updated_checkout', function() {
        jQuery('.woocommerce-error li').each(function() {
            const $li = jQuery(this);
            const text = $li.clone().children().remove().end().text().trim();
            // olo-coupon-error-seen is a non-visual marker (no dismiss button --
            // the error clears itself on the next successful update_checkout, same
            // as any other WooCommerce notice) used only to gate the scroll below
            // to once per fresh error occurrence.
            if ((text.includes('Invalid Coupon') || text.includes('Coupon cannot be applied')) && !$li.hasClass('olo-coupon-error-seen')) {
                $li.addClass('olo-coupon-error-seen');

                // The custom coupon widget triggers WooCommerce's own update_checkout
                // event rather than the native coupon form's submit, and on this page
                // that path's own scroll_to_notices() call isn't visibly scrolling the
                // customer to the resulting error. Trigger it explicitly here.
                //
                // Uses scrollIntoView(), not jQuery.scroll_to_notices()/offset()-based
                // math: this theme renders .woocommerce-error as `position: fixed`
                // (a floating toast, see _checkout.scss), and offset() is unreliable
                // for fixed-position elements -- confirmed live, it measured a
                // nonsensical negative value here, which produces a scrollTop below
                // the browser's minimum of 0 and so never visibly moves the page.
                // scrollIntoView() is correct regardless of fixed positioning or any
                // transformed ancestor.
                //
                // Gated by the same "not already seen" check above, so this only
                // fires once per fresh error -- not on every later update_checkout
                // cycle while a stale error is still on the page.
                const noticeEl = document.querySelector('.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-error');
                if (noticeEl) {
                    noticeEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        });
    });
