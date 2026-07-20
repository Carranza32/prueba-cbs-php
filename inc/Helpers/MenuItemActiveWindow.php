<?php

namespace CBSNorthStar\Helpers;

/**
 * Single source of truth for "is this menu item currently within its per-site
 * active date window", shared by ProductScope's read-path filter (via its own
 * meta_query clause, kept in sync with this class's semantics), the
 * woocommerce_check_cart_items cart-page check, and the OrderProcess.php
 * order-submission backstop — so the three can never disagree.
 *
 * Reads `_active_start_{siteId}` / `_active_stop_{siteId}` (unix timestamps,
 * absolute UTC — see product-active-date-window capability). Comparison is a
 * direct epoch comparison against the real current UTC instant: no additional
 * per-site timezone shift is applied here, because the site's timezone is
 * already baked into the stored timestamp at deploy-write time (mirroring how
 * WooCommerce core compares `_sale_price_dates_from`/`_sale_price_dates_to`).
 * Feeding a wall-clock-shifted "now" (e.g. SiteClock::nowForSite()) into an
 * epoch comparison here would double-count that offset.
 */
class MenuItemActiveWindow {

	/**
	 * Whether $productId is within its active window for $siteId right now.
	 *
	 * Fails open: a missing start/stop, an empty product id, or an empty site
	 * id all mean "unconstrained" (per product-active-date-window's
	 * missing-meta semantics) — a payload-shape surprise or a genuinely
	 * date-less item must never mass-hide products or block valid orders.
	 *
	 * @param int|string $productId WP product post ID.
	 * @param string     $siteId    Active site id.
	 */
	public static function isWithinWindow( $productId, string $siteId ): bool {
		$productId = (int) $productId;
		if ( $productId <= 0 || '' === $siteId ) {
			return true;
		}

		$now = self::nowTimestamp();

		$start = get_post_meta( $productId, self::startKey( $siteId ), true );
		if ( '' !== $start && null !== $start && $now < (int) $start ) {
			return false;
		}

		$stop = get_post_meta( $productId, self::stopKey( $siteId ), true );
		if ( '' !== $stop && null !== $stop && $now >= (int) $stop ) {
			return false;
		}

		return true;
	}

	/**
	 * The postmeta key holding a site's active-start unix timestamp.
	 */
	public static function startKey( string $siteId ): string {
		return '_active_start_' . $siteId;
	}

	/**
	 * The postmeta key holding a site's active-stop unix timestamp.
	 */
	public static function stopKey( string $siteId ): string {
		return '_active_stop_' . $siteId;
	}

	/**
	 * The `meta_query` sub-array ProductScope::metaQuery() ANDs in to exclude
	 * out-of-window items — same semantics as {@see self::isWithinWindow()},
	 * expressed as a native numeric comparison so it runs in SQL rather than
	 * a PHP pass over every row.
	 *
	 * Shape: item passes if start is absent OR <= now, AND stop is absent OR
	 * > now (matches isWithinWindow()'s boundary: exactly-at-stop is inactive).
	 *
	 * An existing row whose value is an empty string is treated the same as
	 * NOT EXISTS — matching isWithinWindow()'s `'' !== $start`/`'' !== $stop`
	 * fail-open checks. Without this, a NUMERIC comparison would CAST('' AS
	 * SIGNED) to 0, which passes the start OR-group but WRONGLY fails the
	 * stop OR-group (0 is never > now), hiding an item isWithinWindow() would
	 * still consider unconstrained.
	 *
	 * @return array A meta_query fragment (an AND-relation array of two OR-groups).
	 */
	public static function metaQueryClause( string $siteId ): array {
		$now = self::nowTimestamp();

		return array(
			'relation' => 'AND',
			array(
				'relation' => 'OR',
				array(
					'key'     => self::startKey( $siteId ),
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => self::startKey( $siteId ),
					'value'   => '',
					'compare' => '=',
				),
				array(
					'key'     => self::startKey( $siteId ),
					'value'   => $now,
					'compare' => '<=',
					'type'    => 'NUMERIC',
				),
			),
			array(
				'relation' => 'OR',
				array(
					'key'     => self::stopKey( $siteId ),
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => self::stopKey( $siteId ),
					'value'   => '',
					'compare' => '=',
				),
				array(
					'key'     => self::stopKey( $siteId ),
					'value'   => $now,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
			),
		);
	}

	/**
	 * Filter a set of cart lines down to those currently outside their active
	 * window — the pure decision logic behind the woocommerce_check_cart_items
	 * cart-page check (cart-item-active-date-enforcement capability). Kept
	 * WC_Cart-free so it is unit-testable without loading WooCommerce; the
	 * caller (woocommerce_hooks.php) does the WC_Cart -> array extraction and
	 * the wc_add_notice() side effect.
	 *
	 * @param array<string,array{product_id:int|string,name:string}> $cartLines Cart item key => {product_id, name}.
	 * @param string $siteId Active site id.
	 * @return array<string,string> Cart item key => name, for lines outside their window.
	 */
	public static function findOutOfWindowCartLines( array $cartLines, string $siteId ): array {
		$outOfWindow = array();
		foreach ( $cartLines as $cartItemKey => $line ) {
			$productId = $line['product_id'] ?? 0;
			if ( ! self::isWithinWindow( $productId, $siteId ) ) {
				$outOfWindow[ $cartItemKey ] = (string) ( $line['name'] ?? '' );
			}
		}
		return $outOfWindow;
	}

	/**
	 * The real current absolute UTC unix timestamp — deliberately NOT routed
	 * through SiteClock's wall-clock-shifted "now" (see class docblock).
	 *
	 * Public so raw-SQL read paths that cannot use a `meta_query` (e.g.
	 * CategoryVisibility's batched slug lookup) compare against the exact
	 * same "now" as {@see self::isWithinWindow()} and {@see self::metaQueryClause()}.
	 */
	public static function nowTimestamp(): int {
		return function_exists( 'current_time' ) ? (int) current_time( 'timestamp', true ) : time();
	}

	/**
	 * Seconds until this site's next known active-date-window boundary (any
	 * item's start OR stop), or null if none is stored / every stored boundary
	 * is already in the past.
	 *
	 * Several read paths cache a fully-rendered product/category list for
	 * performance (`cbs_render_scoped_products()`, `MainController::getProductsHTML()`,
	 * `CategoryVisibility::renderableSlugSet()` — all keyed by catalog-cache-version,
	 * which only changes on deploy). None of those keys are time-sensitive, so a
	 * boundary crossing with no deploy nearby would otherwise sit stale for the
	 * cache's full TTL — contradicting this feature's "correct at the exact
	 * scheduled moment, no cron needed" goal for every OTHER (uncached) read path.
	 * {@see self::cacheTtl()} uses this to cap those caches' TTL so they can never
	 * outlive the next crossing.
	 *
	 * Scoped to the whole site rather than the specific category being cached —
	 * simpler and safer (a same-site crossing elsewhere just shortens this cache
	 * a bit early; it can never be too conservative in the wrong direction).
	 */
	public static function secondsUntilNextSiteBoundary( string $siteId ): ?int {
		if ( '' === $siteId || ! isset( $GLOBALS['wpdb'] ) ) {
			return null;
		}
		global $wpdb;

		$now = self::nowTimestamp();

		$next = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(CAST(meta_value AS SIGNED)) FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s) AND CAST(meta_value AS SIGNED) > %d",
				self::startKey( $siteId ),
				self::stopKey( $siteId ),
				$now
			)
		);

		if ( null === $next || '' === $next ) {
			return null;
		}

		return max( 0, (int) $next - $now );
	}

	/**
	 * The transient TTL to use for a cached product/category render scoped to
	 * this site, capped so it can never outlive the next known active-date-window
	 * boundary (see {@see self::secondsUntilNextSiteBoundary()}).
	 *
	 * Floors at 1, not 0 — WordPress treats a 0 expiration as "never expires",
	 * which would be the opposite of the intent for a boundary landing right now.
	 */
	public static function cacheTtl( string $siteId, int $defaultTtl ): int {
		$untilBoundary = self::secondsUntilNextSiteBoundary( $siteId );
		if ( null === $untilBoundary ) {
			return $defaultTtl;
		}
		return max( 1, min( $defaultTtl, $untilBoundary ) );
	}
}
