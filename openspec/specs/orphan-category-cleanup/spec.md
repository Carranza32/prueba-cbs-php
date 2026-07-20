## Overview

Sites deployed before the ID-based lookup was introduced may have accumulated duplicate `product_cat` terms — one or more for the same external `menu_item_category_id`. This spec covers the one-time migration that detects those duplicates, consolidates their product assignments onto the canonical term, and deletes the orphans. The migration is safe to re-run.

## Behavior

### Entry points

The migration is exposed through two entry points:

1. **WP-CLI**: `wp northstar fix-orphan-categories [--site-id=<id>]` — `--site-id` must be a positive integer; non-numeric or ≤ 0 values are rejected with `WP_CLI::error()` before any migration logic runs.
2. **Admin action hook**: `do_action('northstar_run_fix_orphan_categories', $site_id)` — guarded by `manage_woocommerce` capability for interactive requests; the capability check is bypassed when invoked from a cron context (`wp_doing_cron()`) or from WP-CLI (`defined('WP_CLI') && WP_CLI`). In those contexts, a warning is emitted instead when the capability would otherwise block execution (it is skipped, not an error).

### Deploy-lock guard

Before processing any site, the migration checks `DeployRunRepository::create()->findActiveRun()`. If any deploy run is active, the migration logs a warning and returns an empty result without modifying any data.

### Site scope

- If `--site-id` is provided (or the action hook is called with a non-null `$site_id`), only that site is processed.
- Otherwise all site IDs found in `product_cat` term meta are processed. Site IDs are collected via a raw SQL query on `termmeta` joined to `term_taxonomy`; non-numeric values are filtered out with a warning rather than cast to zero (which would silently match site ID 0).

### Duplicate detection

For each site, `product_cat` terms are fetched filtered by `site_id` meta. Terms are grouped by their `menu_item_category_id` meta value. A group is considered a duplicate set only when it contains more than one term. These raw groups may overlap: a term that carries multiple `menu_item_category_id` meta values appears in more than one group.

### Group merging (connected components)

Before any canonical is elected or any term is deleted, overlapping raw groups are merged into connected components. Two groups are merged when they share at least one term. The merge is repeated in passes until no two remaining components share a term (a single pass handles direct overlaps; additional passes handle transitive chains where groups A and B are disjoint but both overlap group C).

The result is a set of disjoint components, each holding all terms that are transitively connected through shared membership. Canonical selection and product consolidation operate on these components, not on the original per-`menu_item_category_id` groups.

### Canonical selection

Within each component, the canonical term is selected **once**, across all terms in the component, before any deletion occurs:

1. **Highest product count** (most products assigned — most likely the original).
2. **Lowest term ID** as tiebreaker (oldest term).

Because canonical selection runs over the full component before any `wp_delete_term` call, the elected canonical can never be subsequently deleted by a different group in the same run.

### Product consolidation

For each non-canonical term in a component (all canonical decisions have been made for the component before this loop begins):

1. `get_objects_in_term($orphanId, 'product_cat')` retrieves assigned product IDs.
2. Each product is removed from the orphan term and added to the canonical term.
3. `wp_delete_term($orphanId, 'product_cat')` removes the orphan.

If `get_objects_in_term` returns `WP_Error` or empty, consolidation is skipped for that orphan but deletion still proceeds.

The return value of `wp_delete_term` is checked: `$termsDeleted` is incremented only on success (`! is_wp_error($result) && $result !== false`). On failure, a warning is logged and the counter is left unchanged.

A `$deletedTermIds` map is maintained as a belt-and-suspenders guard: if a term somehow appears in more than one component after merging (should not occur after correct merging), it is skipped on subsequent encounters so it is not double-deleted and the counter does not overcount.

### Cache flush

After all sites are processed, `run()` inspects the collected per-site summaries. `CacheService::clearProductCache()` is called **only** when at least one site summary has `terms_deleted > 0` or `products_reassigned > 0`. If all sites reported zero changes (idempotent re-run, no duplicates found), the cache is not touched. The flush always happens outside the per-site loop — a single call at the end is sufficient and avoids repeated full-cache invalidations when processing multiple sites.

### Idempotency

Re-running the migration on a site with no duplicates is a no-op. No terms are modified or deleted. The summary reports zero for all counters.

### Summary

The migration returns a keyed array per site:

```php
[
  $siteId => [
    'duplicate_groups'    => int,
    'terms_deleted'       => int,
    'products_reassigned' => int,
  ]
]
```

The WP-CLI command outputs one line per site and exits with `WP_CLI::success()`.

## Requirements

1. The migration must not run while any deploy is active.
2. The canonical term is always the one with the most products (tiebreak: lowest term_id).
3. Every product assigned to an orphan term must be reassigned to the canonical term before the orphan is deleted.
4. Cache must be flushed at most once after all sites are processed — not inside the per-site loop — and only when at least one site summary reports `terms_deleted > 0` or `products_reassigned > 0`.
5. Re-running the migration on a clean site produces no changes and returns zero counters.
6. The WP-CLI command accepts `--site-id` to limit scope to a single site; the value must be a positive integer — non-numeric or ≤ 0 values are rejected with `WP_CLI::error()` before any migration logic runs.
7. The admin action handler checks `manage_woocommerce` capability for interactive requests; cron and WP-CLI contexts bypass this check.
8. Non-numeric `site_id` meta values must be filtered (with a warning) rather than cast to zero.
9. `wp_delete_term` return must be checked; `$termsDeleted` is incremented only on confirmed success.
10. A term that appears in multiple duplicate groups is deleted at most once per run.
11. Overlapping raw duplicate groups must be merged into connected components before canonical selection; a term elected canonical in one raw group must never be deleted because it also appeared as a non-canonical in another raw group.

## Out of Scope

- Resolving duplicates caused by `menu_id` mismatches (two menus on the same site using the same external category ID) — those are a data-integrity issue in the source data.
- Merging terms across different `site_id` values.
- Recovering soft-deleted or trashed WooCommerce products during consolidation.
