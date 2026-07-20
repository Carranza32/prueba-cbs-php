## Overview

Coupon validity is checked as part of the single WOAPI `/checks/validate/` call already made by `cbs_custom_tax_surcharge()` (the `woocommerce_cart_calculate_fees` handler), rather than by a separate standalone pre-validation call. Previously, a second function (`cbsValidateCoupons()`) ran at priority 9 on every `calculate_totals()` pass whenever a coupon cookie existed — uncached and on the 45-second transport default timeout — purely to strip invalid codes before the priority-10 tax-surcharge call ran. That doubled upstream latency for every couponed session and defeated the tax-surcharge call's own snapshot cache. `cbsValidateCoupons()` has been removed; its behavior (detect an invalid coupon, strip it from the cookie, notify the customer) is now folded into `cbs_custom_tax_surcharge()`'s own failure handling via a strip-and-retry-once pattern, preserving the original "coupon corrected in the same render" behavior without the redundant call on the happy path.

The logic is implemented in `CBSNorthStar\Helpers\CheckValidate` (`inc/Helpers/CheckValidate.php`) with thin global-function delegates in `inc/woocommerce_hooks.php`, so it is unit-testable independent of the file that pulls in `wp-load.php`.

## Behavior

### Happy path: one call or zero

`cbs_custom_tax_surcharge()` computes a cache key via `CheckValidate::cacheKey()` (cart hash, site, order type, delivery context, sorted coupon codes, loyalty signature) and checks it against the `cbsValidateSnapshot` session value. On a match, it reuses the cached tax/coupon/reward fees and makes zero upstream calls. On a miss, it builds the `CartOrderDto` payload — including the current coupon codes — and makes exactly one `/checks/validate/` call. No standalone coupon pre-validation call is registered on `woocommerce_cart_calculate_fees`.

### Invalid coupon: strip and retry once

When the validate call fails, `CheckValidate::couponCodeToStrip($response, $coupons, $alreadyRetried)` inspects the response. If the failure is not coupon-shaped (no `ErrorMessage`, or the pattern `error_applying_coupon_<code>` doesn't match, or the extracted code isn't actually in the applied list), it returns `null` and normal failure handling proceeds. If the failure matches and a retry hasn't already happened this pass, it returns the offending code.

On a match, `CheckValidate::stripCouponCode($invalidCode, $coupons)`:
- Removes only that code from the coupon list (other applied codes survive).
- Rewrites the `olo_coupon_codes` cookie with the survivors (or expires the cookie entirely if none remain) and updates `$_COOKIE` in-process so later reads in the same request (payload rebuild, cache-key recomputation) see the reduced list.
- Queues `wc_add_notice('Invalid Coupon applied.', 'error')` — the exact text required by the dismiss-button matcher in `olo_coupons.js`.

`cbs_custom_tax_surcharge()` then recomputes the cache key (the coupon set changed) and retries the `/checks/validate/` call exactly once with the reduced coupon list, inside the same `while` loop that performed the original call. The retry's response flows through the identical success/failure branches as a first-attempt response: on success, fees are applied and the `cbsValidateSnapshot` is written under the recomputed key; on failure, `taxValidationFailed` is set and the existing failure notice/handling applies. No second retry is attempted regardless of the retry's outcome (`$alreadyRetried` guards this).

### Notice compatibility

The "Invalid Coupon applied." notice text is unchanged from the removed pre-pass, so `assets/src/js/components/olo-theme/olo_coupons.js`'s existing dismiss-button matcher (which attaches to any `.woocommerce-error` list item whose text includes "Invalid Coupon" or "Coupon cannot be applied" — see the `duplicate-coupon-notification` spec) continues to attach without any JS change.

### Timeout

Every `/checks/validate/` call made during fee calculation — the initial attempt and the coupon-strip retry — passes an explicit `25`-second timeout to `Connection::especialPostData()` (the bound OE-26589 raised from 10s to 25s on staging), instead of the 45-second transport default.

### No session write on failure

The failure branch of `cbs_custom_tax_surcharge()` does not write `olo_coupon_codes` to the WooCommerce session. The `olo_coupon_codes` cookie is the sole source of truth for applied coupon codes; coupon state changes only via the cookie strip described above (or via `CouponsLoader::updateCheckout()` on the add/remove path).

## Requirements

1. A `woocommerce_cart_calculate_fees` pass MUST perform at most one `/checks/validate/` call when all applied coupons are valid, and zero calls when the `cbsValidateSnapshot` cache key matches the current cart state. No standalone coupon pre-validation call SHALL be registered on `woocommerce_cart_calculate_fees`.
2. When the validate call fails with an `error_applying_coupon_<code>` message for a code that is actually applied, the system MUST remove that code from the `olo_coupon_codes` cookie (preserving other applied codes) and from `$_COOKIE` for the remainder of the request, and MUST queue an error notice whose text begins with "Invalid Coupon applied."
3. After stripping an invalid coupon, the system MUST retry the validate call exactly once in the same fee-calculation pass with the reduced coupon list, and the retry's response MUST flow through the same success/failure handling as a first-attempt response (fees applied, snapshot written under the recomputed cache key, `taxValidationFailed` set on failure).
4. If the retry also fails (including a second invalid coupon), no further retry MUST occur in that pass; `taxValidationFailed` MUST be set and the existing validate-failure handling applies unchanged.
5. When the validate call fails with an error that does not match `error_applying_coupon_<code>` for an applied code, no coupon MUST be stripped, no retry MUST occur, and the existing failure handling (notice, `taxValidationFailed`) applies unchanged.
6. The invalid-coupon error notice MUST begin with "Invalid Coupon" so the existing dismiss-button matcher in `olo_coupons.js` continues to attach.
7. Every `/checks/validate/` call made during fee calculation, including the coupon-strip retry, MUST pass an explicit 25-second timeout to the connection layer (the OE-26589 bound).
8. The failure branch of the fee-calculation validate handling MUST NOT write `olo_coupon_codes` to the WooCommerce session; the cookie remains the sole source of truth for applied coupon codes.

## Out of Scope

- Coupon add/duplicate-detection behavior in `CouponsLoader::updateCheckout()` — covered by the `duplicate-coupon-notification` capability; untouched by this change.
- The order-review-before-submit validate call — covered by the `checkout-review-validation` capability.
- Changing WOAPI payload shapes or the `cbsValidateSnapshot` cache key recipe beyond what's needed to key on the corrected coupon set after a strip.
- Stripping more than one invalid coupon per fee-calculation pass; a second invalid code (if any) is stripped on the next pass, matching prior behavior.
