<?php

namespace CBSNorthStar\Helpers;

use CBSNorthStar\Repositories\DaypartMenusRepository;

/**
 * Resolves the active daypart menu id for menu read paths and builds the
 * canonical `_menuid` product meta_query clause.
 *
 * Sibling of {@see SiteScope}: products are scoped by BOTH site and menu. Menu
 * membership was previously enforced only at the category level, so products in
 * a category whose `product_cat` term is shared across menus leaked between
 * menus (OE-26399). This helper fails CLOSED — when no menu can be resolved it
 * returns an empty id (callers render nothing) or a never-match meta clause,
 * instead of silently dropping the menu filter and showing every menu's items.
 */
class MenuScope {

	/**
	 * Sentinel forced into the meta_query when no menu can be resolved. Real
	 * menu ids come from ECM (GUID-like strings), so this literal can never
	 * collide with a stored `_menuid` value — the query simply matches nothing.
	 */
	public const NO_MENU = '__no_menu__';

	/**
	 * Resolve the active daypart menu id for a site, failing closed.
	 *
	 * The menu is recomputed server-side from the daypart schedule (honouring
	 * `oloNavSlotOverrides()` for QA time-travel) — the client-supplied
	 * `currentMenu` cookie is deliberately NOT trusted as the filter source,
	 * since a stale/forged cookie is exactly how wrong-menu items would leak.
	 *
	 * Returns '' when no menu is active (e.g. a multi-menu site outside every
	 * daypart window) so callers can render nothing. Single-menu sites fall back
	 * to their one menu via {@see DaypartMenusRepository::getActiveDaypartMenu()}.
	 *
	 * @param string $siteId Active site id (already resolved via SiteScope).
	 */
	public static function resolveActiveMenuId( string $siteId ): string {
		if ( '' === $siteId ) {
			return '';
		}

		list( $overrideTime, $overrideDay ) = function_exists( 'oloNavSlotOverrides' )
			? oloNavSlotOverrides()
			: array( null, null );

		$menuId = DaypartMenusRepository::create()
			->getActiveDaypartMenu( $siteId, $overrideTime, $overrideDay );

		return $menuId ? (string) $menuId : '';
	}

	/**
	 * Canonical `_menuid` meta_query clause for product queries.
	 *
	 * When $menuId is empty the clause is forced to a never-match sentinel so
	 * callers that cannot short-circuit (WP/WC query filters) render no products
	 * instead of every menu's products.
	 *
	 * `_menuid` is multi-valued (a product may belong to several menus); a
	 * meta_query equality matches when ANY stored row equals the value, so
	 * multi-menu products are handled correctly without an IN comparison.
	 *
	 * @return array{key:string,value:string,compare:string}
	 */
	public static function productMenuMetaClause( string $menuId ): array {
		return array(
			'key'     => '_menuid',
			'value'   => '' !== $menuId ? $menuId : self::NO_MENU,
			'compare' => '=',
		);
	}
}
