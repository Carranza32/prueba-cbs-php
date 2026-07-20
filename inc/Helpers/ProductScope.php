<?php

namespace CBSNorthStar\Helpers;

/**
 * Builds the canonical product `meta_query` block that scopes a product read to
 * BOTH the active site and the active daypart menu.
 *
 * Every product read path must filter by site AND menu, or a category whose
 * `product_cat` term is shared across sites/menus leaks another scope's items
 * (OE-26387 site leak, OE-26399 menu leak). Centralising the block here means a
 * call site cannot apply one clause and silently forget the other — the single
 * source of truth replaces four hand-built copies.
 *
 * Both component clauses fail CLOSED: an unresolved site/menu becomes a
 * never-match sentinel ({@see SiteScope::NO_SITE} / {@see MenuScope::NO_MENU}),
 * so the query returns nothing rather than every scope's products.
 *
 * Also ANDs in {@see MenuItemActiveWindow::metaQueryClause()} — a per-site
 * active-date-window comparison against "now" (product-active-date-window
 * capability) — so a scheduled item is excluded before/after its window on
 * every read path without each of them carrying its own copy.
 */
class ProductScope {

	/**
	 * Canonical site + menu `meta_query` block, joined with `relation => AND`.
	 *
	 * Extra leading clauses (e.g. `_stock_status = instock`) can be passed and
	 * are placed before the site/menu pair; clause order inside an AND group is
	 * irrelevant to the result but kept stable to minimise query churn.
	 *
	 * @param string  $siteId       Active site id (resolved via SiteScope).
	 * @param string  $menuId       Active menu id (resolved via MenuScope).
	 * @param array[] $extraClauses Additional meta_query clauses to AND in.
	 * @return array A meta_query array ready to assign to WP_Query/wc_get_products.
	 */
	public static function metaQuery( string $siteId, string $menuId, array $extraClauses = array() ): array {
		return array_merge(
			array( 'relation' => 'AND' ),
			$extraClauses,
			array(
				SiteScope::productSiteMetaClause( $siteId ),
				MenuScope::productMenuMetaClause( $menuId ),
				MenuItemActiveWindow::metaQueryClause( $siteId ),
			)
		);
	}
}
