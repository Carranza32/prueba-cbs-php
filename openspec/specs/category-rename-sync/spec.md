## Overview

When a category's display name is changed in the external API (WOAPI), the corresponding WooCommerce `product_cat` term must be updated in place — name, slug, and description — without creating a duplicate term or orphaning the existing one.

## Behavior

### Trigger

A rename sync is triggered when `saveCategoryAndItems()` finds an existing term via the ID-based lookup (see `category-id-based-lookup` spec) and the stored term name does not exactly match the incoming API name (string comparison after `trim()`).

### What is updated

When a rename is detected, `wp_update_term` is called with:

- `name` — the new display name from the API
- `slug` — `sanitize_title($newName)` to keep the URL-friendly slug consistent
- `description` — the incoming category description (synced on every rename)

When only the description changes (name is identical), `wp_update_term` is called with `description` only — name and slug are not touched.

When neither name nor description differ, no `wp_update_term` call is made.

### What is NOT changed

- Term ID — the same term is reused; product assignments are preserved.
- Term meta (`menu_item_category_id`, `site_id`, `menu_id`) — already correct on the existing term.
- Any products assigned to the term — they remain assigned.

### Logging

A rename triggers an info-level log entry via `CBSLogger::products()->info('Renaming category in-place', ...)` recording `term_id`, `old_name`, and `new_name`.

## Requirements

1. Finding a term by `menu_item_category_id` and detecting a name change must result in `wp_update_term` — not `wp_insert_term`.
2. The slug must be regenerated from the new name on every rename.
3. Product assignments must not be affected by a rename.
4. A description-only change (name identical) must update description without touching name or slug.
5. No change when name and description are both identical to stored values (no redundant DB write).

## Out of Scope

- Renaming categories that exist only by name (no `menu_item_category_id` meta) — those are handled by the adoption path in `category-id-based-lookup`.
- Cascading slug changes to child terms or WooCommerce archive URLs — WordPress handles those internally.
