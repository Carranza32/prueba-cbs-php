## Overview

After a deploy processes a menu, any `product_cat` term that carries `menu_id = <menuId>` term meta but whose category was absent from the current API response has a stale membership entry. This spec covers the post-deploy cleanup that removes stale `menu_id` term meta values — leaving all other menus' memberships on the same term intact — and deletes the WP term itself when no `menu_id` values remain.

## Behavior

### Active term ID tracking

During a deploy, for each menu processed, `saveCategoryAndItems()` accumulates the WP `product_cat` term IDs resolved from the API response into `$deployedCategoryTermIdsBySiteMenu[$siteId][$menuId][]`. This accumulation happens on both the existing-term path (term found by ID) and the new-term path (term created by `wp_insert_term`).

For menus skipped due to an unchanged payload hash, `registerSkippedMenuState()` populates `$deployedCategoryTermIdsBySiteMenu[$siteId][$menuId]` from the DB-queried `$termIds` used during re-registration, so the cleanup runs correctly for those menus without false-positive pruning.

### Stale meta cleanup (`deleteStaleMenuCategoryMeta`)

`deleteStaleMenuCategoryMeta(string $siteId, string $menuId, array $activeTermIds): void` runs after every menu in the same per-menu post-process loop that calls `deleteStaleMenuAssociations()`.

**Fail-closed guard**: If `$activeTermIds` is empty, the method emits a warning log and returns immediately without querying or deleting anything. This prevents mass pruning when the active set was not built (e.g., an unexpected early return during category processing).

**Query**: `get_terms` filtered by `menu_id = $menuId` AND `site_id = $siteId` returns all `product_cat` terms that carry this menu's membership in the DB.

**Diff**: `array_diff` of the DB term IDs against `$activeTermIds` (both normalized to `int`) identifies stale terms — present in the DB but absent from the current API response.

**Delete meta**: For each stale term ID, `delete_term_meta((int) $termId, 'menu_id', $menuId)` is called with the 3-arg form. This removes only the row matching the specific meta value, so a term with `menu_id=menuA` and `menu_id=menuB` retains its `menuB` row when `menuA` prunes it.

**Delete term**: After removing the stale `menu_id` row, the remaining `menu_id` values are fetched. If the term has no `menu_id` meta rows left, `wp_delete_term($termId, 'product_cat')` is called and the deletion is logged with `site_id`, `menu_id`, and `term_id`.

**Logging**: A debug entry is emitted when no stale entries are found. An info entry with `site_id`, `menu_id`, `count`, and `term_ids` is emitted when at least one stale entry is removed. An additional info entry per deleted term includes `site_id`, `menu_id`, and `term_id`.

### Placement in the deploy lifecycle

`deleteStaleMenuCategoryMeta` is called immediately after `deleteStaleMenuAssociations` in the existing per-site post-process loop that iterates over `$deployedItemIdsBySiteMenu[$siteId]`. The product-side property drives the loop; the category-side property is read inside the loop with a `?? []` fallback that triggers the fail-closed guard if unpopulated.

## Requirements

1. During `saveCategoryAndItems()`, every `product_cat` term ID resolved from the API response (existing term or newly created) is accumulated in `$deployedCategoryTermIdsBySiteMenu[$siteId][$menuId]`.
2. During `registerSkippedMenuState()`, the DB-queried term IDs for the skipped menu are added to `$deployedCategoryTermIdsBySiteMenu[$siteId][$menuId]` so that no term is incorrectly pruned for a hash-unchanged menu.
3. If `$activeTermIds` is empty at cleanup time, `deleteStaleMenuCategoryMeta` MUST skip the prune and emit a warning log.
4. The DB query for stale terms filters by both `menu_id = $menuId` AND `site_id = $siteId`; terms belonging to other sites or other menus on the same term are not returned.
5. `delete_term_meta` MUST be called with the specific `$menuId` as the third argument so that only the targeted menu's meta row is removed; other menus' `menu_id` rows on the same term MUST NOT be affected.
6. After removing a stale `menu_id` row, if the term has no remaining `menu_id` meta values, `wp_delete_term($termId, 'product_cat')` MUST be called.
7. Term deletion (requirement 6) MUST NOT occur when the term still has `menu_id` rows from other menus.
8. When no stale entries are found, a debug log is emitted with context keys `site_id` and `menu_id`.
9. When stale entries are pruned, an info log is emitted with context keys `site_id`, `menu_id`, `count`, and `term_ids`.
10. When a term is deleted (requirement 6), an info log is emitted with context keys `site_id`, `menu_id`, and `term_id`.
11. Log structure must be consistent with `deleteStaleMenuAssociations` so operators can correlate product-level and category-level cleanup in a single log search.
12. `deleteStaleMenuCategoryMeta` is called in the same per-menu loop as `deleteStaleMenuAssociations`, immediately after it.

## Out of Scope

- Removing `site_id` term meta from category terms (site membership lifecycle is a separate concern).
- Changing `CategoryVisibility::hasRenderableProducts()` — it remains the correct runtime display guard.
- Changing `deleteStaleMenuAssociations` — product-level (`_menuid` post meta) cleanup is already handled there.
- Sub-categories created via `LinkToDataJson` — these use a separate term type and are not addressed here.
- Any checkout, cart, coupon, session, payment, or REST API flows.
