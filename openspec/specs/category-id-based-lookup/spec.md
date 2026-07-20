## Overview

During a menu deploy, each incoming API category is matched to the correct WooCommerce `product_cat` term using its stable external ID (`menu_item_category_id` term meta). A single WP term is shared across all menus that reference the same `catId` on the same site. `menu_id` is stored as multi-value metadata on the term, not used as a lookup key.

## Behavior

### Primary lookup

`saveCategoryAndItems()` queries for an existing `product_cat` term whose term meta satisfies:

- `site_id` equals the current site ID
- `menu_item_category_id` equals the incoming category's external ID

`menu_id` is NOT part of the lookup query. This allows a term created during menu B's sync to be found when menu A processes the same `catId`.

This query is performed via a bulk preload (`get_terms` once per site, filtered by `site_id` only) at the start of the loop, with a per-category fallback DB query when the preload does not contain the ID.

### Match found

When a term is found by ID:

- It is used as the target term for product assignment.
- The term is removed from the orphan-deletion candidate list and added to `$savedCategoriesPerMenu`.
- Name and description are updated in-place if the API value has changed.
- The current `menu_id` is attached to the term if not already present: `add_term_meta($catID, 'menu_id', $menuid, false)`. A term may carry multiple `menu_id` rows — one per menu that references it.
- `menu_item_category_id` meta is enforced to exactly one correct value.

### Match not found — fresh term creation

When no term is found by ID, `wp_insert_term` creates a new term. No name-based lookup (`term_exists()`) is performed.

The slug is constructed as:
```
sanitize_title($name) . '-' . substr(str_replace('-', '', $catId), 0, 8)
```

This guarantees uniqueness even when two category names normalize to the same `sanitize_title()` output (e.g. "Appetizers" and "Appetizers." both produce "appetizers"). The catId suffix makes the slug deterministic and globally unique per API category.

If `wp_insert_term` returns `WP_Error`, the error is logged with `catId`, `name`, `error`, `site_id`, and `menu_id`, and execution continues to the next category.

### `$savedCategoriesPerMenu` and orphan-list removal

Both `$this->savedCategoriesPerMenu[$catID] = $name` and `unset($this->currentCategories[$catID])` are executed only after `$catID` is resolved to a valid integer — regardless of which path resolved it. This prevents a valid term from being treated as an orphan and deleted at the end of the deploy run.

## Requirements

1. The lookup queries by `site_id` + `menu_item_category_id` only. `menu_id` MUST NOT be part of the lookup query.
2. A bulk preload reduces database queries to one per site (not one per category or per menu).
3. When a term is found, the current `menu_id` MUST be attached if not already present; no duplicate `menu_id` meta rows are added.
4. When no term is found, `wp_insert_term` is called with a catId-suffixed slug (`sanitize_title($name) . '-' . substr(str_replace('-', '', $catId), 0, 8)`).
5. No `term_exists()` name-based lookup is performed at any point in the category resolution flow.
6. `wp_insert_term` results are always checked with `is_wp_error` before array access.
7. `$catID` is always an integer before `$savedCategoriesPerMenu` and `currentCategories` are updated.

## Out of Scope

- Sub-categories (`LinkToDataJson` / `Categories` hierarchy).
- Categories that belong to a different site — the lookup still filters by `site_id`.
- Legacy terms without `menu_item_category_id` meta — these must be deleted before deploying this behavior.
