## Overview

`CouponsLoader::updateCheckout()` merges a newly submitted `olo_coupon_code` into the `olo_coupon_codes` cookie, deduplicating against codes already applied. Previously, submitting a code that was already applied was silently dropped with no feedback to the customer. This spec covers surfacing a WooCommerce error notice when that happens, reusing the existing checkout-notice pipeline instead of adding new plumbing.

## Behavior

### Duplicate detection (`CouponsLoader::updateCheckout`)

`updateCheckout()` is hooked to `woocommerce_checkout_update_order_review` and runs on every AJAX `update_checkout` request. It normalizes the submitted `olo_coupon_code` (`strtoupper(trim(...))`) and compares it against the normalized codes already present in the `olo_coupon_codes` cookie.

- If the normalized code is **not** already in the list, it is appended (existing behavior, unchanged).
- If the normalized code **is** already in the list, the list is left unchanged and `wc_add_notice(__('Coupon cannot be applied. This coupon has already been added.', 'olo'), 'error')` is called.

The comparison is case-insensitive and whitespace-insensitive because both the stored codes and the incoming code go through the same `strtoupper(trim(...))` normalization already used for storage.

### Notice delivery

No new frontend or fragment code is needed. `wc_add_notice()` calls made during `woocommerce_checkout_update_order_review` are flushed by WooCommerce's own `WC_AJAX::update_order_review()` handler into the `messages` key of the same AJAX response — the same mechanism that already surfaces "Invalid Coupon applied." (`inc/woocommerce_hooks.php`, `cbsValidateCoupons()`) and "Coupon cannot be applied. Your order total is already covered." notices.

### Dismiss-button reuse

`assets/src/js/components/olo-theme/olo_coupons.js` already attaches a dismiss (×) button to any `.woocommerce-error` list item whose text includes `Invalid Coupon` or `Coupon cannot be applied`. The new notice text starts with `Coupon cannot be applied`, so it gets the dismiss control automatically with no JS changes.

## Requirements

1. Submitting a coupon code that normalizes (uppercase + trim) to a code already present in the `olo_coupon_codes` cookie MUST NOT add a duplicate entry to the cookie (already true prior to this change).
2. Submitting a coupon code that already matches an applied code (per requirement 1's normalization) MUST call `wc_add_notice()` with level `error` and message text beginning with `Coupon cannot be applied`.
3. Submitting a genuinely new coupon code MUST NOT trigger the duplicate notice and MUST continue to be appended to the cookie silently, as before.
4. The duplicate comparison MUST be case-insensitive and whitespace-insensitive, matching the existing normalization used when the code was first stored.
5. Removing a coupon and then resubmitting the same code MUST succeed silently (not treated as a duplicate), since the code is no longer present in the cookie at submission time.
6. The duplicate notice MUST require no new notice-rendering or fragment code — it must rely on WooCommerce's existing `update_order_review` notice pipeline.
7. The duplicate notice's message prefix MUST remain compatible with the existing dismiss-button matcher in `olo_coupons.js` (`Invalid Coupon` / `Coupon cannot be applied`).

## Out of Scope

- External-API coupon validity checks (`cbsValidateCoupons()`, "Invalid Coupon applied." notice) — unrelated to detecting a duplicate re-add of an already-accepted code.
- Coupon removal (`olo-remove-coupon`) behavior.
- Adding a success notice for a normal, non-duplicate coupon apply.
- Behavior when cookies are disabled/blocked (no coupon list can be tracked client-side; unchanged pre-existing limitation).
