#!/usr/bin/env node
/**
 * Simple file concatenation — replaces laravel-mix's mix.combine() for
 * projects that only use that feature and can't run mix on modern Node.js.
 *
 * Usage:
 *   node bundle.js           — build all bundles once
 *   node bundle.js --watch   — rebuild on any source file change
 */

const fs   = require('fs');
const path = require('path');

const bundles = [
  {
    out: 'assets/dist/js/olo_components.bundle.js',
    src: [
      'assets/src/js/components/olo-theme/olo_render.js',
      'assets/src/js/components/olo-theme/olo_servingoptions.js',
      'assets/src/js/components/olo-theme/olo_calculate_totals.js',
      'assets/src/js/components/olo-theme/olo_giftcards.js',
      'assets/src/js/components/olo-theme/olo_coupons.js',
      'assets/src/js/components/olo-theme/oloLoginPopup.js',
      'assets/src/js/components/olo-theme/olo_loyalty.js',
      'assets/src/js/components/olo-theme/olo_infinity_scroll.js',
      'assets/src/js/components/olo-theme/olo_timeslots.js',
    ],
  },
  {
    out: 'assets/dist/js/kiosk_components.bundle.js',
    src: [
      'assets/src/js/components/kiosk-theme/kiosk_render.js',
      'assets/src/js/components/kiosk-theme/kiosk_guest_identifier.js',
      'assets/src/js/components/kiosk-theme/kiosk_checkout.js',
      'assets/src/js/components/kiosk-theme/kiosk_cart.js',
      'assets/src/js/components/kiosk-theme/kiosk_calculate_totals.js',
      'assets/src/js/components/kiosk-theme/kiosk_modal.js',
      'assets/src/js/components/kiosk-theme/kiosk_serving_options.js',
    ],
  },
];

function build() {
  bundles.forEach(({ src, out }) => {
    const combined = src.map(f => fs.readFileSync(f, 'utf8')).join('\n');
    fs.mkdirSync(path.dirname(out), { recursive: true });
    fs.writeFileSync(out, combined);
    console.log(`Built ${out} (${(combined.length / 1024).toFixed(1)} KB)`);
  });
}

build();

if (process.argv.includes('--watch')) {
  const allSrc = bundles.flatMap(b => b.src);
  console.log('Watching for changes…');
  allSrc.forEach(f => {
    fs.watch(f, () => {
      console.log(`Changed: ${f}`);
      build();
    });
  });
}
