function sendToCheckout()
{
    noCat=1;
    var ordertype = localStorage.getItem("ordertype");
    openDropdown();
    spinnerON();
    jQuery("#productsdiv").html(' ');
    jQuery.ajax({
        type: "POST",
        url: wc_add_to_cart_params.ajax_url,
        data: {
            action: 'checkout_kiosk',
            ordertype : ordertype
        },
        success: function (data) {
            jQuery("#productsdiv").html('');
            jQuery("#productsdiv").html(data);

            var dropdowns = document.getElementsByClassName("dropdown-content");
            var i;
            for (i = 0; i < dropdowns.length; i++) {
                var openDropdown = dropdowns[i];
                if (openDropdown.classList.contains('show')) {
                    openDropdown.classList.remove('show');
                }
            }
        }
    }).done(function(){
        spinnerOFF();
        jQuery(".woocommerce-billing-fields").html("");
        localStorage.removeItem("ordertype");
    });

}

jQuery(document).ready(function($) {
    jQuery(document).on('click', '#place_order', function(e) {
        spinnerON();
    });
});
