## Overview

`action_woocommerce_review_order_before_submit()` (`inc/woocommerce_hooks.php`) runs on `woocommerce_review_order_before_submit`, which fires on every checkout order-review render (every `update_order_review` AJAX call, e.g. a checkout field change). Historically it always issued its own WOAPI `/checks/validate/` call via `CartOrderReviewDto`, even though `cbs_custom_tax_surcharge()` (the `woocommerce_cart_calculate_fees` handler) already runs earlier in the same request via `calculate_totals()` and performs the authoritative validate call for the full cart. `CartOrderReviewDto` sends only the first cart line (`$items[0]`), omits coupons, and ignores timeslot overrides, so its validation coverage is strictly weaker than the tax-surcharge call — its only unique value is the table/area location check used in pay-later (order-at-table) mode.

This spec covers gating that redundant call: in standard mode, the hook skips its own upstream call when the cart was already validated earlier in the same request, relying instead on the `taxValidationFailed` session flag set by `cbs_custom_tax_surcharge()`. Pay-later mode keeps the call unconditionally, since the table/area check has no other source. All local side effects of the hook (guest phone, stale-menu detection, no-location overlay, the `CartOrderReviewDto` payload build itself) are unaffected by the gate.

## Behavior

### Request-scoped validation signal

`cbs_custom_tax_surcharge()` resets a request-scoped flag, `$GLOBALS['cbsValidateOkThisRequest']`, at the start of every fee pass, and sets it to `true` on a `cbsValidateSnapshot` cache hit or a successful `/checks/validate/` response. The flag — not a recomputed snapshot cache-key comparison — is what the review-order gate reads, because `WC_Cart::get_cart_hash()` folds in `get_total('edit')`, which is an intermediate value during the fee hook but the final value by the time the review template renders; a key recomputed at render time can never match the key written at fee time. Since `calculate_totals()` always runs before the review template renders, the flag reliably reflects whether the current cart state was validated this request.

### Gate logic (`CheckValidate::shouldSkipReviewValidate()`)

`action_woocommerce_review_order_before_submit()` computes three inputs and delegates to `CheckValidate::shouldSkipReviewValidate($payLaterActive, $validatedThisRequest, $validationFailed)` (thin global delegate: `cbsReviewOrderShouldSkipValidate()`):

- `$payLaterActive` — `true` when the `pay_later_control` cookie is set AND a non-empty `table_num` cookie AND a non-empty area external code are present (the same condition under which `CartOrderReviewDto` would attach `LocationExternalCode`/`AreaExternalCode`).
- `$validatedThisRequest` — `!empty($GLOBALS['cbsValidateOkThisRequest'])`.
- `$validationFailed` — `WC()->session->get('taxValidationFailed') === true`.

The call is skipped only when `$payLaterActive` is `false`, `$validationFailed` is `false`, and `$validatedThisRequest` is `true`. Any other combination performs the upstream call (fail-open).

### Local side effects always run

Guest-phone assignment from the `guestPhone` cookie, stale-menu detection (comparing the `currentMenu` cookie against the active daypart menu and setting `olo_clear_cart`/`olo_menu_changed` on both cookie and session), the no-site-selected overlay, and the `CartOrderReviewDto` payload build (which also writes the `cartitemkey_arr_init` cookie read by `setSessionCallback()` and the QR order-at-table flow) all execute regardless of whether the upstream call is skipped. Only the network call itself is gated.

### Pay-later table/area validation

When `$payLaterActive` is `true`, the hook always performs the `/checks/validate/` call, preserving the existing `no_locations_available_for_area` error path (the "No locations available" notice and overlay) verbatim.

### Timeout

The upstream call, when made, passes an explicit `25`-second timeout to `Connection::postData()`, matching the bound applied to the tax-surcharge call (raised from 10s to 25s by OE-26589 on staging), instead of the 45-second transport default.

### Documented payload limitation

`CartOrderReviewDto::toArray()` carries a code comment (`inc/Dto/CartOrderReviewDto.php`) stating that `$items[0]` sends only the first cart line, that a multi-line cart is never fully represented by this payload, and that authoritative full-cart validation is `cbs_custom_tax_surcharge()`'s `CartOrderDto` payload.

## Requirements

1. In standard mode (pay-later condition absent), `action_woocommerce_review_order_before_submit()` MUST skip its `/checks/validate/` call when `cbs_custom_tax_surcharge()` completed a successful validation (fresh call or `cbsValidateSnapshot` cache hit) earlier in the same request, signaled by the request-scoped flag `$GLOBALS['cbsValidateOkThisRequest']` — not a snapshot cache-key comparison recomputed at render time, since `WC_Cart::get_cart_hash()` yields different values at fee-hook time versus review-render time for the same validated state.
2. When no successful validation occurred this request, or `taxValidationFailed` is `true`, the hook MUST fail open: it performs the `/checks/validate/` call, logs the reason it fired (`pay_later` / `validation_failed` / `not_validated_this_request`), and applies the existing error-overlay behavior on failure.
3. When pay-later (order-at-table) context is active — `pay_later_control` cookie set AND a table number AND an area external code present — the hook MUST perform the upstream validate call regardless of the request-scoped validation signal, preserving the `no_locations_available_for_area` error path exactly.
4. Guest-phone assignment, stale-menu detection (`currentMenu` cookie comparison setting `olo_clear_cart`/`olo_menu_changed` on cookie and session), and the no-site-selected overlay MUST behave identically whether or not the upstream call is skipped.
5. The `CartOrderReviewDto` payload MUST still be built unconditionally (including its `cartitemkey_arr_init` cookie side effect) even on the skip path, since other flows depend on that side effect independent of the network call.
6. Every `/checks/validate/` call issued by this hook (standard-mode fail-open and pay-later paths) MUST pass an explicit 25-second timeout to the connection layer, the same bound as the fee-pass validate call (OE-26589).
7. `CartOrderReviewDto`'s first-line-only payload behavior (`$items[0]`) MUST remain documented via a code comment at the DTO stating that the payload contains only the first cart line and that authoritative full-cart validation lives in `cbs_custom_tax_surcharge()`; it MUST NOT be silently relied upon as full-cart validation anywhere.

## Out of Scope

- Fixing `CartOrderReviewDto`'s `$items[0]` payload to represent the full cart — documented as a known limitation only; the standard-mode call that would benefit is gated off instead.
- Changing WOAPI payload shapes, the `cbsValidateSnapshot` cache key recipe, or the 25-second timeout value (owned by OE-26589).
- The order-at-table QR submission flow (`Check::validateMenuItems()`) or order submission (`OrderProcess::validateOrder()`) — both remain on their existing timeout defaults, outside this hook.
- Coupon validation behavior — covered by the `coupon-validation-consolidation` capability.
