## Task 0 — Environment Setup & Baseline Validation

Successfully configured and validated the local development and testing environment running under PHP 8.4 and macOS.

* **Environment & Bootstrapping Resolution:** Resolved local environment discrepancies during initial setup, specifically addressing BSD/macOS `sed` command syntax incompatibilities in the test automation scripts and configuring the correct MySQL Unix domain socket path for local database connections.
* **Baseline Verification:** Executed the existing PHPUnit test suite to establish a green baseline prior to refactoring. Verified that all pre-existing unit tests and Brain Monkey WordPress/WooCommerce function stubs executed cleanly without deprecation notices or fatal errors under PHP 8.x.

## Task 1 — `FreeEvery` Pricing Rule Implementation

Implemented the `FreeEvery` promotional rule within `ComponentFreeRules::isInstanceFree()` to support modular "nth item free" pricing (e.g., `FreeEvery = 3` makes every 3rd selected component instance free across the category).

* **Precedence & Interaction Semantics:** Follows a strict short-circuit evaluation order (`FreeUpTo` -> `DefaultComponentsAreFree` -> `FirstDefaultComponentsLevelsFree` -> `FreeAfter` -> `FreeEvery`). If an instance qualifies as free under an earlier threshold, evaluation halts immediately, preventing double-counting or stacked discounts.
* **Defensive Math:** Added strict boundary checks (`(int) $rule['FreeEvery'] > 0`) before modulo calculation (`$position % FreeEvery === 0`). Zero, negative, or malformed values act as a safe no-op without throwing `DivisionByZeroError` exceptions under PHP 8.x.
* **Unit Test Coverage:** Extended `ComponentFreeRulesTest` with cases covering exact multiples, non-multiple paid positions, zero/negative resilience, multi-quantity orders via `computeFreeInstanceKeys()`, and combined rule interaction (`FreeUpTo` + `FreeEvery`).
* **Frontend (`view.js`) Synchronization Strategy:** To maintain parity without runtime coupling, both codebases should validate against a shared JSON fixture of test vectors (input payloads vs. expected free keys). On the JS side (`src/product-detail/view.js`), frontend tests should assert:
  1. UI reactivity (cart totals updating dynamically when crossing the $N$th threshold).
  2. Visual state (rendering "Free" badges on the correct DOM elements).
  3. Array mutation handling (re-evaluating modulo positions correctly when an earlier item is removed from the cart).



  ## Task 2 — HPOS-Compatible Order Meta & Audit

Introduced the `CBSNorthStar\Helpers\OrderMeta` wrapper class to centralize and manage all read/write operations for internal CBS order metadata (`cbs_orderid`, `cbs_siteid`, `cbs_checknumber`, and `cbs_orderFinalized`). This abstraction enforces schema whitelist validation, supports both `WC_Order` objects and raw numeric order IDs, and leverages the native WooCommerce CRUD API (`$order->get_meta()` and `$order->update_meta_data()`). Legacy direct calls to `update_post_meta()` across `inc/Woapi/OrderProcess.php` and `inc/woocommerce_hooks.php` were successfully refactored to utilize this layer, optimizing database access by deferring persistent writes until all keys are staged in memory. Furthermore, official High-Performance Order Storage (HPOS) compatibility was declared via `FeaturesUtil::declare_compatibility()` within the `before_woocommerce_init` action hook.

* **Production HPOS Readiness Audit:** While working through the refactoring of `OrderProcess.php`, I identified significant remaining legacy dependencies on direct post metadata functions outside the core CBS check IDs. Specifically, the `getDeliveryDate()` method relies heavily on direct `get_post_meta()` calls to retrieve order delivery timestamps (`_orddd_lite_timeslot_timestamp`, `_orddd_timeslot_timestamp`) as well as custom OLO navigation time slots (`_olo_time_slot_time`, `_olo_time_slot_business_date`). Before enabling HPOS on a production store, a codebase-wide audit must be performed to migrate all remaining `get_post_meta()` and `update_post_meta()` references to the WC CRUD API. Additionally, any direct `$wpdb` SQL queries joining `wp_posts` with `wp_postmeta` for order reporting, along with legacy `get_posts()` arguments querying the `shop_order` post type, must be audited and updated to use `wc_get_orders()` or the `wc_orders` custom table schema.


## Task 3 — Transient-Failure Resilience on Order Submit

Implemented an inline bounded retry loop directly within `OrderProcess::submitOrder()` and isolated error classification in `OrderProcess::isTransientError()`, keeping the Git diff minimal and strictly avoiding over-engineering.

* **Classification Strategy:** Only transient transport errors (`WP_Error`, timeouts, DNS drops) and temporary HTTP stress statuses (`408`, `429`, `500`, `502`, `503`, `504`) trigger a retry. Non-transient business/validation errors (e.g., HTTP `400 Bad Request` or stock validation failures) fail fast without retrying.
* **Bounded Backoff Justification:** An inline retry is ideal for synchronous checkouts because customers expect immediate confirmation; sending orders to a background queue risks kitchen delays and missed pickup windows. Attempts are strictly bounded to **3 max** with a **500ms delay** (~1 second total added latency), which is imperceptible to users while giving network hiccups enough time to recover.
* **Double-Submission & Idempotency Analysis:** A network timeout is ambiguous (the request may have reached the kitchen before the return connection dropped). Re-submitting is safe because calls target a deterministic endpoint (`/checks/{checkId}/submit`) and carry a per-session `TransactionReference` header (`SessionReference::get()`). 
* **Questions for the API Team:** I would confirm with the backend team: *1) Does the `/checks/{checkId}/submit` endpoint guarantee idempotency when receiving the same `{checkId}` and `TransactionReference`? 2) If an order was already processed during a dropped connection, does a retry return a `200 OK` (idempotent replay) or a specific `409 Conflict` code so we can handle it cleanly?*


## Task 4 — Production-Readiness & Security Review

Below are the five most significant security and robustness issues identified across the audited codebase, ranked by risk severity:

1. **SQL Injection via Direct Cookie Concatenation (`inc/Set_sessions_for_site.php`) — Risk: CRITICAL**
   * **Issue:** Direct variable and cookie interpolation into raw SQL queries (e.g., `$wpdb->get_results("SELECT pay_later_control FROM cbs_site_details where siteid='".$_COOKIE['siteid']."'")` on line 86) without using `$wpdb->prepare()`.
   * **Scenario:** An attacker tampers with the `siteid` HTTP cookie (e.g., setting `siteid=1' UNION SELECT...`) to execute arbitrary SQL commands. In a restaurant kiosk context, this allows attackers to dump confidential configuration tokens, database credentials, or bypass payment rules.
   * **Fix:** Mandatory use of `$wpdb->prepare()` for all SQL parameters combined with input sanitization (`sanitize_text_field(wp_unslash($_COOKIE['siteid']))`).

2. **Order Identity Spoofing via Unauthenticated Client Cookies (`inc/woocommerce_hooks.php`) — Risk: HIGH**
   * **Issue:** The fallback order completion handler reads critical check identifiers (`checkid`, `siteid`, `checknumber`) directly from unauthenticated client-writable cookies (`$_COOKIE['checkid']`) and applies `esc_attr(htmlspecialchars())` prior to DB storage.
   * **Scenario:** A malicious customer modifies local browser cookies during checkout to match another guest's active check ID. The plugin will link their WooCommerce order to the victim's POS check, creating order mismatches and billing fraud in the kitchen. Additionally, storing HTML-escaped strings pollutes database metadata.
   * **Fix:** Store transaction tokens and check IDs in secure server-side WooCommerce session objects (`WC()->session`) rather than client-exposed cookies, and sanitize values without HTML entity encoding before saving.

3. **Information Disclosure via Hardcoded Debug Overrides (`northstaronlineordering.php`) — Risk: HIGH**
   * **Issue:** Lines 10-11 execute `error_reporting(E_ALL);` and `ini_set('display_errors', 1);` unconditionally at the plugin entry point before checking `ABSPATH`.
   * **Scenario:** On production sites, any unhandled warning or database hiccup displays full server file paths (`/Users/...` or `/var/www/...`), PHP stack traces, and internal variables directly onto public customer screens and kiosk interfaces.
   * **Fix:** Remove `ini_set('display_errors', 1)` and allow WordPress environment constants (`WP_DEBUG` and `WP_DEBUG_DISPLAY`) to control error visibility centrally.

4. **Brittle Bootstrap Path Traversal (`inc/Woapi/Connection.php`) — Risk: MEDIUM**
   * **Issue:** Lines 8-16 attempt to bootstrap WordPress manually using a hardcoded relative path traversal (`$root = dirname(__FILE__) . "/../../../../../"; require_once $root . 'wp-load.php';`).
   * **Scenario:** On sites with non-standard directory structures (e.g., symlinked plugin folders, custom `wp-content` locations, or Bedrock/Composer setups), this path breaks and causes fatal crashes. Direct execution of `wp-load.php` also bypasses standard action hook initializations and nonce security checks.
   * **Fix:** Remove manual `wp-load.php` requires. Depend on standard WordPress autoloading and global hook initialization.

5. **Log Forgery & Unsanitized Superglobal Access (`inc/Helpers/WoapiRequest.php`) — Risk: MEDIUM**
   * **Issue:** Line 48 passes `$_COOKIE['siteid']` directly into the ELK logging DTO (`'siteId' => $_COOKIE['siteid']`) without checking existence or sanitizing input.
   * **Scenario:** Missing cookies trigger PHP `Undefined index` notices. Furthermore, an attacker can inject multiline payloads or malformed characters into the `siteid` cookie to forge or corrupt centralized log records in ELK.
   * **Fix:** Safely inspect and sanitize the cookie value before logging: `sanitize_text_field(wp_unslash($_COOKIE['siteid'] ?? ''))`.
