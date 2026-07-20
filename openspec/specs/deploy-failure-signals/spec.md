# Capability: deploy-failure-signals

## Overview

Logging guards that surface five previously silent failure conditions in the SaveProduct deploy pipeline. Additions use warning level (anomaly paths: uncategorized products, $0 price, silent update failure), info level (per-site timing summary), and debug level (per-category API item count) — no per-item logs in the hot path, no new DB queries, no behavioral changes.

---

## Requirements

### Requirement: Category assignment failure is logged
When `wp_set_object_terms()` returns a `WP_Error` during product category assignment (in create or update paths), the deploy pipeline SHALL emit a `warning`-level log entry on `CBSLogger::products()` identifying the affected post and term so operators can detect uncategorized products without a database inspection.

#### Scenario: Category term assignment fails on product create
- **WHEN** `wp_set_object_terms($postId, intval($params->termId), 'product_cat')` returns a `WP_Error` inside `custombizAddSimpleProduct`
- **THEN** a warning log is emitted with context `post_id`, `term_id`, `item_id`, `site_id` and the WP_Error message

#### Scenario: Category term assignment fails on product update
- **WHEN** `wp_set_object_terms($postId, intval($params->termId), 'product_cat', true)` returns a `WP_Error` inside `custombizUpdateSimpleProduct`
- **THEN** a warning log is emitted with context `post_id`, `term_id`, `item_id`, `site_id` and the WP_Error message

#### Scenario: Successful term assignment produces no extra log
- **WHEN** `wp_set_object_terms()` returns a non-error array
- **THEN** no additional log entry is emitted (hot path is unaffected)

---

### Requirement: Per-site deploy timing is recorded
The "deploy: site completed" summary log SHALL include an `elapsed_seconds` field containing the wall-clock time elapsed for that site's full deploy pass (from before `getDefaultWebOrderingMenuid` through the stale-cleanup step).

#### Scenario: Timing captured in summary log
- **WHEN** a site's deploy pass completes and the "deploy: site completed" info log is emitted
- **THEN** the log context includes `elapsed_seconds` as a float rounded to two decimal places

#### Scenario: Timing resets between sites
- **WHEN** a multi-site deploy runs
- **THEN** each site's `elapsed_seconds` reflects only that site's processing time, not the accumulated total

---

### Requirement: API item count included in per-category log
The per-category "deploy: category items processed" debug log SHALL include an `items_total_in_api` field containing the raw count of menu items returned by the OLO API for that category, so operators can identify categories where fewer items were saved than the API provided.

#### Scenario: Item count included in category log
- **WHEN** `processMenuItems` completes for a category and the per-category debug log is emitted
- **THEN** the log context includes `items_total_in_api: count((array) $menuItems)`

#### Scenario: Gap is computable from log fields
- **WHEN** `items_total_in_api` is 25 and `items_active + items_inactive + items_skipped_fingerprint` sums to 22
- **THEN** the three-item gap is visible without any additional query or log entry

---

### Requirement: Silent product update failure is logged with outer context
When `custombizUpdateSimpleProduct` returns `false` (non-exception path), `handleExistingProduct` SHALL emit a `warning`-level log carrying the `site_id`, `menu_id`, `item_id`, and `post_id` context available at the outer call site.

#### Scenario: Update returns false, warning emitted
- **WHEN** `custombizUpdateSimpleProduct($product)` returns `false` and no exception was thrown
- **THEN** a warning log is emitted with `site_id`, `menu_id`, `item_id`, `post_id`

#### Scenario: Update succeeds, no extra log
- **WHEN** `custombizUpdateSimpleProduct($product)` returns `true`
- **THEN** no additional log entry is emitted

---

### Requirement: Zero or empty price emits a data-quality warning
When `syncProductMeta` is called with a price that is an empty string or the literal string `"0"`, it SHALL emit a `warning`-level log identifying the product and site so operators can investigate OLO data quality before a $0 product reaches customers.

#### Scenario: Empty price warns
- **WHEN** `syncProductMeta` is called with `$price === ''`
- **THEN** a warning log is emitted with `post_id`, `item_id`, `site_id`

#### Scenario: Zero price warns
- **WHEN** `syncProductMeta` is called with `$price === '0'`
- **THEN** a warning log is emitted with `post_id`, `item_id`, `site_id`

#### Scenario: Valid price produces no warning
- **WHEN** `syncProductMeta` is called with `$price` as a non-empty string other than `"0"` (e.g. `"12.99"`)
- **THEN** no warning is emitted and the price is written normally

#### Scenario: Deploy is not blocked by zero price
- **WHEN** a zero or empty price triggers the warning
- **THEN** the deploy continues normally — no exception is thrown and the product is still saved
