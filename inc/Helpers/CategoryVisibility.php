<?php

namespace CBSNorthStar\Helpers;

/**
 * Decides whether a product category should render on the menu for the active
 * site + daypart menu, based on whether it actually has a renderable product.
 *
 * A `product_cat` term is shared across sites and menus and keeps its
 * `site_id` / `menu_id` term meta as stable structural membership (OE-26454 no
 * longer strips that meta during deploy). Term-meta presence therefore does NOT
 * guarantee the category has any item for THIS scope, so every category-list
 * render path must additionally gate on a renderable-product count — otherwise
 * a category whose only items were disabled / moved would show as an empty
 * heading.
 *
 * Single source of truth: extracted from {@see \CBSNorthStar\Models\Categories}
 * (React/kiosk path) so the legacy `[menuitems]` web path
 * (`cbs_categories_func`, `cbs_menuitems_func`, `cbsInfinityScroll`) applies the
 * exact same publish/visible/instock + {@see ProductScope} filter the read
 * paths use.
 *
 * For shortcode-mode performance (OE-26548 QA retest), visibility now uses a
 * batched slug set cached per site|menu|catalog-version and memoized per
 * request; category checks are set-membership lookups in the warm path.
 *
 * Fails OPEN: when WooCommerce is unavailable OR the batched path is
 * unavailable/failing, the class falls back to the legacy per-slug
 * `wc_get_products` probe, keeping behavior stable in no-WP/no-db contexts.
 *
 * Known carried-forward gap: webhook-only visibility flips that do not bump
 * `cbs_catalog_cache_version` can stay stale until TTL expires (same accepted
 * TTL-bounded trade-off as cbs_render_scoped_products). The batched slug set
 * also now includes the product-active-date-window filter (a scheduled item's
 * category-emptiness verdict) — unlike the webhook gap above, this one IS
 * corrected: the transient's TTL is capped via
 * {@see MenuItemActiveWindow::cacheTtl()} to the site's next known start/stop
 * boundary, so a scheduled crossing can never sit stale for the cache's full
 * TTL the way an untracked webhook edit can.
 */
class CategoryVisibility {

	/**
	 * Resolve renderable category slugs for one site + menu as a slug=>true map.
	 *
	 * Resolution order: request memo -> transient -> one batched SQL query.
	 * Returns null when the batched path is unavailable so callers can use the
	 * legacy per-slug wc_get_products fallback unchanged.
	 *
	 * @param string $siteId Active site id.
	 * @param string $menuId Active daypart menu id.
	 * @return array<string,bool>|null
	 */
	private static function renderableSlugSet( string $siteId, string $menuId ): ?array {
		static $memo = array();

		$memoKey = $siteId . '|' . $menuId;
		if ( array_key_exists( $memoKey, $memo ) ) {
			return $memo[ $memoKey ];
		}

		if ( ! isset( $GLOBALS['wpdb'] ) || ! function_exists( 'get_transient' ) ) {
			$memo[ $memoKey ] = null;
			return null;
		}

		global $wpdb;

		$version  = (string) get_option( 'cbs_catalog_cache_version', '0' );
		$key      = MenuRenderCache::categoryVisibilityKey( $siteId, $menuId, $version );
		$cached   = get_transient( $key );
		if ( is_array( $cached ) ) {
			$memo[ $memoKey ] = $cached;
			return $cached;
		}

		$siteMeta = SiteScope::productSiteMetaClause( $siteId );
		$menuMeta = MenuScope::productMenuMetaClause( $menuId );
		$now      = MenuItemActiveWindow::nowTimestamp();

		// active_start/active_stop are LEFT JOINed (not INNER, like the required
		// site/menu/stock meta above) because product-active-date-window's
		// missing-meta semantics mean "absent" must PASS, not exclude the row —
		// mirrors MenuItemActiveWindow::isWithinWindow()/metaQueryClause() exactly
		// so this batched path can't drift from the other five read paths that go
		// through ProductScope::metaQuery() instead of raw SQL.
		$slugs = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT t.slug
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} site_meta
				 	ON site_meta.post_id = p.ID
				 	AND site_meta.meta_key = %s
				 	AND site_meta.meta_value = %s
				 INNER JOIN {$wpdb->postmeta} menu_meta
				 	ON menu_meta.post_id = p.ID
				 	AND menu_meta.meta_key = %s
				 	AND menu_meta.meta_value = %s
				 INNER JOIN {$wpdb->postmeta} stock_meta
				 	ON stock_meta.post_id = p.ID
				 	AND stock_meta.meta_key = %s
				 	AND stock_meta.meta_value = %s
				 LEFT JOIN {$wpdb->postmeta} active_start
				 	ON active_start.post_id = p.ID
				 	AND active_start.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} active_stop
				 	ON active_stop.post_id = p.ID
				 	AND active_stop.meta_key = %s
				 INNER JOIN {$wpdb->term_relationships} tr
				 	ON tr.object_id = p.ID
				 INNER JOIN {$wpdb->term_taxonomy} tt
				 	ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 	AND tt.taxonomy = %s
				 INNER JOIN {$wpdb->terms} t
				 	ON t.term_id = tt.term_id
				 WHERE p.post_type = %s
				 	AND p.post_status = %s
				 	AND ( active_start.meta_value IS NULL OR CAST(active_start.meta_value AS SIGNED) <= %d )
				 	AND ( active_stop.meta_value IS NULL OR CAST(active_stop.meta_value AS SIGNED) > %d )
				 	AND NOT EXISTS (
				 		SELECT 1
				 		FROM {$wpdb->term_relationships} trv
				 		INNER JOIN {$wpdb->term_taxonomy} ttv
				 			ON ttv.term_taxonomy_id = trv.term_taxonomy_id
				 		INNER JOIN {$wpdb->terms} tv
				 			ON tv.term_id = ttv.term_id
				 		WHERE trv.object_id = p.ID
				 			AND ttv.taxonomy = %s
				 			AND tv.slug IN (%s, %s)
				 	)",
				$siteMeta['key'],
				$siteMeta['value'],
				$menuMeta['key'],
				$menuMeta['value'],
				'_stock_status',
				'instock',
				MenuItemActiveWindow::startKey( $siteId ),
				MenuItemActiveWindow::stopKey( $siteId ),
				'product_cat',
				'product',
				'publish',
				$now,
				$now,
				'product_visibility',
				'exclude-from-catalog',
				'exclude-from-search'
			)
		);

		if ( ! empty( $wpdb->last_error ) ) {
			$memo[ $memoKey ] = null;
			return null;
		}

		$map = array();
		if ( is_array( $slugs ) ) {
			foreach ( $slugs as $slug ) {
				$slug = (string) $slug;
				if ( '' !== $slug ) {
					$map[ $slug ] = true;
				}
			}
		}

		// Capped so this cache can never outlive an item's next active-date-window
		// start/stop crossing (which can flip a category between empty and
		// non-empty) — see MenuItemActiveWindow::cacheTtl(). This supersedes the
		// class docblock's earlier note accepting up-to-TTL staleness for this signal.
		set_transient(
			$key,
			$map,
			MenuItemActiveWindow::cacheTtl(
				$siteId,
				(int) apply_filters( 'cbs_category_visibility_cache_ttl', HOUR_IN_SECONDS )
			)
		);

		$memo[ $memoKey ] = $map;
		return $map;
	}

	/**
	 * Whether a category has at least one product that would actually render on
	 * the menu for the given site and daypart menu.
	 *
	 * Result is memoized per (slug, site, menu) for the request: a page can build
	 * the category list more than once and the verdict is stable within a request.
	 * The memo is request-scoped only — no cross-request cache — so an item
	 * re-enabled by a deploy makes its category reappear on the very next render.
	 *
	 * @param string $siteId       Active site id (resolved via SiteScope).
	 * @param string $menuId       Active daypart menu id (resolved via MenuScope).
	 * @param string $categorySlug product_cat slug.
	 * @return bool True when at least one in-scope renderable product exists.
	 */
	public static function hasRenderableProducts( string $siteId, string $menuId, string $categorySlug ): bool {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return true;
		}
		if ( '' === $categorySlug ) {
			return true;
		}

		$set = self::renderableSlugSet( $siteId, $menuId );
		if ( null !== $set ) {
			return isset( $set[ $categorySlug ] );
		}

		static $memo = array();
		$key = $categorySlug . '|' . $siteId . '|' . $menuId;
		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}

		$products = wc_get_products(
			array(
				'status'       => 'publish',
				'visibility'   => 'visible',
				'limit'        => 1,
				'return'       => 'ids',
				'stock_status' => 'instock',
				'category'     => array( $categorySlug ),
				'tax_query'    => array(
					array(
						'taxonomy'         => 'product_cat',
						'field'            => 'slug',
						'terms'            => $categorySlug,
						'operator'         => 'IN',
						'include_children' => false,
					),
				),
				'meta_query'   => ProductScope::metaQuery( $siteId, $menuId ),
			)
		);

		return $memo[ $key ] = ! empty( $products );
	}

	/**
	 * Filter a list of category rows to those with at least one renderable product
	 * for the site under ANY of the given menus.
	 *
	 * @param array    $categories Rows with a `->slug` property (e.g. wpdb results / WP_Term).
	 * @param string   $siteId     Active site id.
	 * @param string[] $menuIds    One or more daypart menu ids to consider (a category is
	 *                             kept if it renders under any of them).
	 * @return array Re-indexed list keeping only renderable categories.
	 */
	public static function filterRenderable( array $categories, string $siteId, array $menuIds ): array {
		return array_values(
			array_filter(
				$categories,
				static function ( $category ) use ( $siteId, $menuIds ) {
					$slug = is_object( $category ) ? ( $category->slug ?? '' ) : '';
					if ( '' === $slug ) {
						return true;
					}
					foreach ( $menuIds as $menuId ) {
						if ( self::hasRenderableProducts( $siteId, (string) $menuId, $slug ) ) {
							return true;
						}
					}
					return false;
				}
			)
		);
	}
}
