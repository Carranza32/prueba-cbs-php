<?php
/**
 * Cache-key builder for the legacy menu shortcode product blocks (OE-26548).
 *
 * @package NorthStarOnlineOrdering
 */

namespace CBSNorthStar\Helpers;

/**
 * Cache-key builder for the legacy menu shortcode product blocks (OE-26548).
 *
 * Kept as a pure, dependency-free helper so the leakage-critical keying can be unit
 * tested in isolation: a product block cached for one scope must NEVER be served for a
 * different site, menu, daypart, category, sort order or catalog version
 * (OE-26387 site leak / OE-26399 menu leak / OE-26454 empty-category leak).
 *
 * The site + menu values are resolved by the caller via {@see SiteScope} / {@see MenuScope}
 * — the SAME resolvers the `woocommerce_shortcode_products_query` filter uses — so the key
 * always matches the scope of the products actually rendered.
 */
class MenuRenderCache {

	/** Transient key prefix for cached menu product blocks. */
	public const PRODUCT_KEY_PREFIX = 'cbs_sc_products_';

	/** Transient key prefix for cached /loadmore product HTML. */
	public const LOADMORE_KEY_PREFIX = 'cbs_loadmore_';

	/** Transient key prefix for cached CategoryVisibility slug sets. */
	public const CATVIS_KEY_PREFIX = 'cbs_catvis_';

	/**
	 * Build the transient key for a category's product block under a given scope.
	 *
	 * Every dimension that can change the rendered products is folded in. The catalog
	 * version (bumped by the deploy) makes a deploy invalidate every entry at once.
	 *
	 * @param string $slug    Product category slug.
	 * @param string $siteId  Active site id (resolved via SiteScope; '' fails closed).
	 * @param string $menuId  Active menu id (resolved via MenuScope; '' fails closed).
	 * @param int    $perPage Products per page.
	 * @param int    $productPage Current WooCommerce product-page pagination index.
	 * @param string $orderby Catalog ordering column.
	 * @param string $metaKey Catalog ordering meta_key.
	 * @param string $order   Catalog ordering direction.
	 * @param string $version Catalog cache version token.
	 */
	public static function productKey(
		string $slug,
		string $siteId,
		string $menuId,
		int $perPage,
		int $productPage,
		string $orderby,
		string $metaKey,
		string $order,
		string $version
	): string {
		return self::PRODUCT_KEY_PREFIX . md5(
			$slug . '|' . $siteId . '|' . $menuId . '|' . $perPage . '|' . $productPage . '|' . $orderby . '|' . $metaKey . '|' . $order . '|' . $version
		);
	}

	/**
	 * Build the transient key for a `/loadmore` REST response.
	 *
	 * `/loadmore`'s WP_Query never varies ordering (no `orderby` arg, no
	 * catalog-ordering pull), so — unlike {@see self::productKey()} — no
	 * orderby/meta_key/order dimensions are folded in here; adding them would
	 * just fragment the cache for scopes that can never actually differ.
	 *
	 * @param string $category Product category slug.
	 * @param int    $page     Requested page number.
	 * @param string $siteId   Active site id (resolved via SiteScope; '' fails closed).
	 * @param string $menuId   Active menu id (resolved via MenuScope; '' fails closed).
	 * @param int    $perPage  Products per page (MainController::LOADMORE_PER_PAGE).
	 * @param string $version  Catalog cache version token.
	 */
	public static function loadmoreKey(
		string $category,
		int $page,
		string $siteId,
		string $menuId,
		int $perPage,
		string $version
	): string {
		return self::LOADMORE_KEY_PREFIX . md5(
			$category . '|' . $page . '|' . $siteId . '|' . $menuId . '|' . $perPage . '|' . $version
		);
	}

	/**
	 * Build the transient key for the batched CategoryVisibility slug set.
	 *
	 * QA retest for OE-26548 showed shortcode-mode pages were paying an
	 * O(categories) per-slug product probe on every request. This key scopes a
	 * single batched slug-set by site + menu + catalog version so visibility
	 * lookups become constant-time set membership checks.
	 *
	 * @param string $siteId  Active site id (resolved via SiteScope; '' fails closed).
	 * @param string $menuId  Active menu id (resolved via MenuScope; '' fails closed).
	 * @param string $version Catalog cache version token.
	 */
	public static function categoryVisibilityKey( string $siteId, string $menuId, string $version ): string {
		return self::CATVIS_KEY_PREFIX . md5( $siteId . '|' . $menuId . '|' . $version );
	}
}
