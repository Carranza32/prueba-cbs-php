// webpack.mix.js

let mix = require('laravel-mix');

mix.combine([
    'assets/src/js/components/olo-theme/olo_render.js',
    'assets/src/js/components/olo-theme/olo_servingoptions.js',
    'assets/src/js/components/olo-theme/olo_calculate_totals.js',
    'assets/src/js/components/olo-theme/olo_giftcards.js',
    'assets/src/js/components/olo-theme/olo_coupons.js',
    'assets/src/js/components/olo-theme/oloLoginPopup.js',
    'assets/src/js/components/olo-theme/olo_loyalty.js',
    'assets/src/js/components/olo-theme/olo_infinity_scroll.js',
    'assets/src/js/components/olo-theme/olo_timeslots.js',
], 'assets/dist/js/olo_components.bundle.js')
.combine([
    'assets/src/js/components/kiosk-theme/kiosk_render.js',
    'assets/src/js/components/kiosk-theme/kiosk_guest_identifier.js',
    'assets/src/js/components/kiosk-theme/kiosk_checkout.js',
    'assets/src/js/components/kiosk-theme/kiosk_cart.js',
    'assets/src/js/components/kiosk-theme/kiosk_calculate_totals.js',
    'assets/src/js/components/kiosk-theme/kiosk_modal.js',
    'assets/src/js/components/kiosk-theme/kiosk_serving_options.js',
],'assets/dist/js/kiosk_components.bundle.js');
