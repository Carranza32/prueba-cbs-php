# Capability: save-product-deploy

## Overview

The save-product-deploy process synchronises the WooCommerce product catalogue with an external online-ordering API. It fetches menus, categories, daypart schedules, product items, and images for every configured site and writes them into WordPress/WooCommerce. The process can be triggered manually from the admin UI (async via WP-Cron) or automatically when a menu change webhook fires.

---

## Triggers

### T1 — Manual (Admin Button)
A logged-in admin user POSTs to `northstaronlineordering/v1/deploy/start`.
- The REST endpoint acquires the deploy lock, initialises `DeployProgress`, schedules a single WP-Cron event (`cbs_run_deploy_background`), calls `spawn_cron()`, and returns `{runId}` immediately.
- The actual deploy runs in the WP-Cron background worker (separate PHP process).
- The UI polls `GET /deploy/progress?runId=xxx` for live status.

### T2 — Webhook (Automatic)
The endpoint `webhooks/listener_menuitemchanged.php` receives a POST from the external API when menu data changes.
- Calls `DeployOrchestrator::run(TRIGGER_HOOK)` **synchronously** — the full deploy blocks the HTTP connection.
- If a deploy is already running the webhook receives a 200 OK (blocked result) without retrying.

> **Known gap**: Webhook runs synchronously. For large deploys this exceeds typical webhook HTTP timeouts (30 s). See open issue: _webhook async_.

### T3 — Legacy AJAX
`wp_ajax_save_product_action` calls `DeployOrchestrator::run(TRIGGER_MANUAL)` synchronously. Present for backward compatibility.

---

## Run Lifecycle

```
store($runId)
│
├─ 1. Initialise state (reset all per-run properties)
├─ 2. Load configuration (instance URL, ECM URL, site token)
├─ 3. Load site list from configuration
├─ 4. prefetchAllSiteData()          ← counts total products, warms API cache
│      └─ DeployProgress::update(totalProducts)
├─ 5. foreach site (Default only):
│      ├─ check cancel flag
│      ├─ getDefaultWebOrderingMenuid(siteId, areaId)
│      │    └─ getWholeSiteData()
│      │         ├─ use prefetch cache or re-fetch API
│      │         ├─ build site data maps (components, serving options)
│      │         ├─ load AreaDayPartMenus + DayParts
│      │         ├─ delete existing daypart_menus rows for site
│      │         └─ foreach areaDayPartMenu (filtered by site's areaId):
│      │               ├─ persist daypart schedule (start/end/days/order)
│      │               ├─ skip if MenuId already processed this site
│      │               └─ saveCategoryAndItems()
│      │                    ├─ create/update WooCommerce categories
│      │                    └─ processMenuItems()
│      │                         └─ foreach item:
│      │                               ├─ check cancel flag
│      │                               ├─ buildProductForMenuItem()
│      │                               ├─ increment processedProducts
│      │                               ├─ buildImageSrc() → getImage()
│      │                               └─ custombizAdd/UpdateSimpleProduct()
│      └─ deleteStaleProductsForSite()
├─ 6. Post-deploy cleanup:
│      ├─ remove stale product-category term assignments
│      ├─ delete unused WooCommerce categories
│      ├─ update category descriptions
│      ├─ deleteProductImgFromFolder()   ← purge temp image dirs
│      └─ cleanProductCache()
└─ 7. Return result array {success, message, products_attempted, ...}
```

### Run Result Shape
```php
[
  'success'            => bool,
  'message'            => string|string[],
  'products_attempted' => int,
  'products_succeeded' => int,
  'products_failed'    => int,
  'products_skipped'   => int,
]
```

---

## Site Filtering

- Only sites with `menu_type === 'Default'` are processed.
- Non-Default sites are counted as `skipped` in the result.
- Each site has an `areaid` used to filter `AreaDayPartMenus`.

---

## Prefetch & Progress Tracking

### Prefetch (`prefetchAllSiteData`)
- Runs once at the start of `store()` when a `$runId` is present.
- Makes one API call per Default site (`/sites/{siteId}/menu/`).
- Caches each response in `$this->siteDataCache[siteId]` — the main loop reuses it.
- Counts `MenuItemCategoryItem` objects across all menus for the site's `areaId`, deduplicating by `MenuId`.
- Returns an integer total used as `progressTotalProducts` baseline.
- Single API object items (stdClass, not array) count as 1.
- If cancel is requested during prefetch, returns 0 and cancels immediately.

### Progress State
- `totalProducts`: seeded from prefetch. Increases when `LinkToDataJson` subcategory items are discovered mid-deploy.
- `processedProducts`: incremented once per `menuItem` iteration, **before** the null check — so trashed/skipped items still count toward the total.
- `percent`: recalculated by `DeployProgress::recalculate()` using `(processedProducts + processedCategories + processedMenus) / (totalProducts + totalCategories + totalMenus)`. Capped at 99 until `complete()` is called.
- Progress never goes backward: a high-water mark is maintained in `DeployProgress::update()`.

---

## Areas

- `$areaId` comes from `$site->areaid` in the site configuration.
- `AreaDayPartMenus` from the API is filtered by `AreaId === $areaId` before any processing.
- Prefetch applies the same filter so counts match the main loop exactly.

---

## Dayparts

- `DayParts.DayPart[]` and `AreaDayPartMenus.AreaDayPartMenu[]` are loaded from `DataDefinitions`.
- `daypartMenusRepository->deleteMenus($siteId)` purges stale rows before re-inserting.
- For each `AreaDayPartMenu` matching the site's `areaId`:
  - Matched against `DayParts` by `DayPartId`.
  - Stored via `daypartMenusRepository->updateMenu()` with: `MenuId`, `DayPartId`, `StartTime`, `EndTime`, `DaysOfWeek` (comma-joined), `MenuDisplayOrder`, `siteId`.
- A `MenuId` that appears under multiple dayparts is only processed for products **once** (tracked in `$processedMenuIds`). Daypart schedule rows are written for every matching daypart.

---

## Menu IDs

- `$this->currentMenuId` is set to `$areasDayPartMenu->MenuId` before each `saveCategoryAndItems()` call.
- **New products**: `update_post_meta($postId, '_menuid', $this->currentMenuId)` — single value.
- **Existing products**: `add_post_meta($postId, '_menuid', $this->currentMenuId, false)` — appends if not already present, allowing a product to belong to multiple menus.
- **Category term meta**: `add_term_meta($catID, 'menu_id', $menuid, false)` — also multi-value.

---

## Categories

### Detection
Each `MenuMenuCategory` from `MenuDefinitions.Menu.MenuMenuCategories` maps to a WooCommerce `product_cat` term.

### Create Path (term does not exist)
1. `term_exists($itemCategoryName, 'product_cat')` → false/null
2. `wp_insert_term($name, 'product_cat', ['description' => ...])` → `$catID`
3. Adds term meta:
   - `menu_item_category_id` → external category UUID
   - `site_id` → site UUID
   - `menu_id` → menu UUID
   - `order` → `DisplayOrder`
4. If category has an image and images not skipped: sideload via `retrieveAndCreateImage()` + `uploadImage()` + store WP attachment ID in `thumbnail_id` term meta.

### Update Path (term exists)
1. `term_exists()` → returns `['term_id' => ...]`
2. `checkCategoryBySiteidMenuidCatid()` verifies site/menu/category meta combination.
3. If meta combination not present: `add_term_meta()` for `menu_item_category_id`, `site_id`, `menu_id`.
4. Always: `update_term_meta($catID, 'order', $displayOrder)`.
5. Image: same sideload logic, updates `thumbnail_id` if `$imgID` resolved.

### Deduplication
- `$this->savedCategoriesPerMenu[$catID]` is populated for every category processed in this run.
- Post-deploy: all `product_cat` terms whose `term_id` is NOT in `savedCategoriesPerMenu` keys are deleted via `wp_delete_term()`.
- Category descriptions are updated post-deploy via `searchDescription()` + `wp_update_term()`.

### Category Image Path
Uses `getAttachmentById($mediaItemId)` (`get_page_by_path($mediaItemId, OBJECT, 'attachment')`) to check if the image was previously sideloaded. The image's WP attachment post_name is set to `$mediaItemId`. Does **not** use `picture_record`.

---

## Products

### Product Resolution
For each `MenuItemCategoryItem` in a menu category:
1. `buildProductForMenuItem()`:
   - Calls `getItemDetails()` to enrich the item with components, serving options, pricing, LinkTo data.
   - `WoapiProductAdapter::adaptProductData()` maps the enriched data to a `ProductParams` object.
   - Returns `null` if data is incomplete or product status is `'trash'`.
2. Stale products (those not encountered in this run) are trashed by `deleteStaleProductsForSite()`.
3. `prefetchProductMap()` pre-indexes all existing WooCommerce products by `_itemid` meta — no per-product DB query.

### Create Path (`custombizAddSimpleProduct`)
- `wp_insert_post()` creates the product post.
- `updateProductImage()` sets the WP thumbnail.
- `wp_set_object_terms()` assigns `product_type=simple` and `product_cat`.
- `syncProductMeta()` writes all standard meta fields.
- `update_post_meta` for: `_stock_status=instock`, `_stock=999`, `_siteid`, `_menuid`, `_link_to_category` (if present).

### Update Path (`custombizUpdateSimpleProduct`)
- `get_post_meta($postId)` primes the meta cache (single DB query).
- `wp_update_post()` only called when post fields have actually changed (title, description, status, menu_order).
- `updateProductImage()` updates thumbnail and `picture_record`.
- `_siteid` and `_menuid` are appended (not overwritten) if not already present.

### Product Post Meta Fields
| Meta Key | Value |
|---|---|
| `_itemid` | External MenuItemId UUID |
| `_itemname` | Item name |
| `_siteid` | Site UUID (multi-value) |
| `_menuid` | Menu UUID (multi-value) |
| `_thumbnail_id` | WP attachment ID |
| `_regular_price` | Price from API |
| `_price` | Same as regular price |
| `_stock_status` | `instock` / `outofstock` |
| `_stock` | 999 (in stock) / 0 (unavailable) |
| `_components` | JSON-encoded component data |
| `_servingoptions` | JSON-encoded serving options |
| `_link_to_category` | LinkTo category slug+name JSON |
| `_numberofplacement` | Number of placements |
| `_type` | Item type string |
| `_comboqualifierids` | Serialised combo qualifier IDs |

---

## LinkTo Items (`LinkToDataJson`)

### Detection
Inside `getItemDetails()`, if `$site_MenuItem_value->LinkToDataJson` is set:
- `MenuItemCategoryId` identifies the target category to expand inline.
- A circular-reference guard (`$this->visitedLinkToCategories[]`) prevents infinite loops.

### Processing
1. `Categories::createLinktoCategory($parentCatId, $menuItemId, $itemName)`:
   - Looks up parent category term by `term_id`.
   - Checks for existing subcategory by `parent` + `linkToId` term meta.
   - Creates with `wp_insert_term()` + stores `linkToId = $menuItemId` term meta if new.
2. API call: `GET /menuitemsbycategory/{linkToCategory}` via `Connection::getData()`.
3. `progressTotalProducts` is incremented by the count of returned items **before** `processMenuItems()` runs — preventing the progress counter from exceeding total.
4. `processMenuItems()` is called recursively with the subcategory's `term_id`.

### Product Meta
- `_link_to_category` stores `{slug, name}` JSON of the subcategory.

---

## Product Images

### Skip Logic (no download, no sideload)
- `picture_record` has a row where `mediaitemid === $mediaItemId` for this `itemid` AND `wp_get_attachment_url((int) pictureid)` returns a valid URL.
- Returns the existing WP attachment ID.

### In-Memory Per-Run Cache
- `$this->sideloadedImages[$mediaItemId]` caches attachment IDs for the duration of a single deploy.
- Prevents re-downloading the same image when multiple products share a `mediaItemId`.

### Disk Cache (same deploy run)
- If the file already exists at `img_products/{raw}.PNG` (downloaded earlier in this run), sideload directly without API call.

### Download & Sideload Path
1. `getMediaItemFromAPI($mediaItemId)` — `GET {ecmUrl}/ecm/api/v1/mediaitem/{mediaItemId}`.
2. `storeAndOptimizeImage($response)` — base64-decodes `MediaData`, writes to `img_product_load/`, compresses to `img_products/` using configured quality, returns filename string.
3. `sideloadImageUrl($fileUrl, $postId)` — calls `media_sideload_image($url, $postId, '', 'id')`, returns WP attachment ID.
4. Result cached in `$this->sideloadedImages[$mediaItemId]`.

### Persistence
- `updateProductImage($attachmentId, $postId, $itemid, $mediaItemId)`:
  - `set_post_thumbnail($postId, $attachmentId)`.
  - `updateImageProductRecord($itemid, (string) $attachmentId, $mediaItemId)` — inserts or updates `picture_record`.
- `picture_record` schema: `itemid` | `mediaitemid` | `pictureid` (WP attachment ID as string).

### Temp Folder Cleanup
- `deleteProductImgFromFolder()` runs at the end of every deploy.
- Deletes all files in `img_products/` and `img_product_load/`.
- Images are permanently stored in WP uploads — the temp files are not needed after sideloading.

### Skip Images Option
- Carbon Fields option `olo_skip_deploy_images` (bool) — when true, `$this->skipImages = true` and all image operations are bypassed entirely.

### Error Handling
- `sideloadImageUrl()` failures are logged and return `null`; the product is saved without a thumbnail.
- `storeAndOptimizeImage()` returns `null` on malformed API response; `getImage()` returns `null` and no image is set.

---

## Components

- Fetched via `getItemComponentsDetails()` using `DataDefinitions.Components` lookup maps.
- Stored as JSON in `_components` post meta.
- Component images use a separate path: `getAttachmentById($mediaItemId)` checks by WP `post_name`, sideloads via `uploadImage($url, null, $mediaItemId, 'src')` if absent.
- Component images are **not** tracked in `picture_record`.

---

## Serving Options

- Fetched via `getServingOptionsData()` using `DataDefinitions.ServingOptions` lookup maps.
- Stored as JSON in `_servingoptions` post meta.
- Global serving option rules persisted via `ServingOption::getOrSaveServingOptionRules($siteId, 0)` once per site.

---

## Pricing Levels

- `buildMenuPricingLevelMap($menuPricingLevel)` pre-indexes `PricingLevel[]` by `PricingLevelId` once per menu.
- Used in `getItemDetails()` to resolve the correct price for each item.

---

## Availability / Stock

- `unavailableProducts()` via `ProductManager` returns item IDs not available for the current site/menu/category.
- `_stock_status = outofstock` / `_stock = 0` when `$params->available == 1`.
- `_stock_status = instock` / `_stock = 999` otherwise.

---

## Stale Product Cleanup

- `$this->deployedItemIdsBySite[$siteId]` accumulates every `_itemid` encountered during deploy for that site.
- `deleteStaleProductsForSite($siteId, $deployedItemIds)` queries all products with `_siteid = $siteId` and trashes any whose `_itemid` was not seen in this run.

---

## Cancellation

- `DeployProgress::isCancelRequested($runId)` is checked:
  - Between sites in the main `foreach` loop.
  - Between menu items in `processMenuItems()`.
  - During prefetch between sites.
- When detected in `processMenuItems()`: sets `$this->cancelDetected = true` and returns immediately (does NOT call `DeployProgress::cancel()` directly).
- `getWholeSiteData()` has `cancelDetected` guards after each `saveCategoryAndItems()` call to break out of menu and daypart loops.
- `store()` checks `$this->cancelDetected` after the site loop and calls `DeployProgress::cancel()` once.
- The cancel key (`cbs_deploy_cancel_{runId}`) is a separate `wp_options` row to prevent concurrent progress writes from overwriting it.

---

## Lock & Concurrency

- `DeployLockService::tryAcquire()` prevents concurrent deploys. Returns a `DeployLockResult`.
- If a run is already active, subsequent requests are either blocked (409) or queued (202).
- The queue holds at most one pending deploy (last-write-wins).
- After each run completes, `firePendingDeploy()` checks the queue and starts the next run if one is waiting.
- Lock is always released in a `finally` block.

---

## Post-Deploy Cleanup

| Step | What happens |
|---|---|
| Category term cleanup | `wp_remove_object_terms()` removes product-category associations that no longer apply |
| Category deletion | `wp_delete_term()` removes categories not present in `savedCategoriesPerMenu` |
| Category description update | `wp_update_term()` syncs description text from API |
| Image temp folder | `deleteProductImgFromFolder()` purges `img_products/` and `img_product_load/` |
| WooCommerce cache | `cleanProductCache()` resets WooCommerce caches |

---

## API Endpoints Used

| Method | Path | Purpose |
|---|---|---|
| GET | `{oeapiUrl}/sites/{siteId}/menu/` | Full site menu data (prefetch + main loop) |
| GET | `{oeapiUrl}/sites/{siteId}/menu/{menuId}` | Per-menu item details (MenuItem, MenuItemCategory, PricingLevel) |
| GET | `{oeapiUrl}/menuitemsbycategory/{categoryId}` | LinkTo subcategory item expansion |
| GET | `{ecmUrl}/ecm/api/v1/mediaitem/{mediaItemId}` | Image binary download |

---

## Logging Requirements

### Requirement: Per-site summary log includes timing
The "deploy: site completed" info log emitted after all categories for a site complete SHALL include an `elapsed_seconds` field (float, two decimal places) in addition to the existing counter fields (`items_created`, `items_updated`, `items_skipped_fingerprint`, `items_inactive`, `images_unchanged`, `images_resideloaded`, `images_uploaded`, `categories_total`, `site_id`, `run_id`).

#### Scenario: Summary log emitted with timing
- **WHEN** a site's deploy pass completes
- **THEN** "deploy: site completed" info log includes all existing fields plus `elapsed_seconds`

---

### Requirement: Per-category log includes API item total
The "deploy: category items processed" debug log emitted after each `processMenuItems` call SHALL include an `items_total_in_api` field containing the raw count of items passed to `processMenuItems` for that category (via `count((array) $menuItems)`).

#### Scenario: Per-category log includes total
- **WHEN** `processMenuItems` completes for any category
- **THEN** the debug log includes `items_total_in_api` alongside `items_active`, `items_inactive`, `items_skipped_fingerprint`

---

## Known Gaps

| ID | Description | Impact |
|---|---|---|
| GAP-1 | Webhook trigger runs synchronously — full deploy blocks the HTTP connection, HTTP timeout fires before deploy completes | Webhook provider may treat timeout as failure and retry, causing duplicate deploys |
| GAP-2 | Category image skip logic uses `get_page_by_path(mediaItemId)` rather than `picture_record` — inconsistent with product image skip logic | Category images are re-checked every deploy via WP post lookup; no caching of mediaItemId change |
| GAP-3 | `visitedLinkToCategories` is never reset between sites — if two sites share a LinkTo category UUID the second site skips it silently | Unlikely in practice; requires investigation if multi-site LinkTo items are used |
