<?php

namespace {
	// Minimal global stub so the unit suite can exercise CategoryVisibility without
	// loading WooCommerce. Guarded so a WC-loaded context uses the real function.
	// Returns a non-empty id list only for slugs listed in $GLOBALS['__cv_slugs_with_products'].
	if ( ! function_exists( 'wc_get_products' ) ) {
		function wc_get_products( $args ) {
			$slug = $args['category'][0] ?? '';
			$with = $GLOBALS['__cv_slugs_with_products'] ?? array();
			return in_array( $slug, $with, true ) ? array( 101 ) : array();
		}
	}
}

namespace CBSNorthStar\Tests\Helpers {

	use CBSNorthStar\Helpers\CategoryVisibility;
	use PHPUnit\Framework\TestCase;

	/**
	 * Unit tests for CategoryVisibility (OE-26454).
	 *
	 * The deploy no longer strips a category's site_id/menu_id term meta, so the
	 * render paths must hide empty categories themselves. This gate keeps a category
	 * only when it has a renderable (publish/visible/instock + ProductScope) product
	 * for the active site + menu, and fails open when WooCommerce is unavailable.
	 *
	 * NOTE: the global wc_get_products stub above ignores the menu/meta_query (the
	 * scoping is covered by ProductScope's own tests); these cases assert the gate's
	 * keep/drop decision, memoization, and multi-menu "any menu keeps it" behavior.
	 * Slugs keep a stable verdict across tests so the per-request static memo in the
	 * helper does not cross-contaminate cases.
	 */
	final class CategoryVisibilityTest extends TestCase {

		private string $site = 'site-1';
		private string $menu = 'menu-A';

		protected function setUp(): void {
			parent::setUp();
			$GLOBALS['__cv_slugs_with_products'] = array( 'burgers', 'drinks' );
		}

		public function test_returns_true_when_category_has_a_renderable_product(): void {
			$this->assertTrue(
				CategoryVisibility::hasRenderableProducts( $this->site, $this->menu, 'burgers' )
			);
		}

		public function test_returns_false_when_category_has_no_in_scope_product(): void {
			$this->assertFalse(
				CategoryVisibility::hasRenderableProducts( $this->site, $this->menu, 'salads' )
			);
		}

		public function test_empty_slug_fails_open(): void {
			$this->assertTrue(
				CategoryVisibility::hasRenderableProducts( $this->site, $this->menu, '' )
			);
		}

		public function test_filter_drops_empty_categories_and_reindexes(): void {
			$cats = array(
				(object) array( 'slug' => 'burgers', 'name' => 'Burgers' ),
				(object) array( 'slug' => 'salads', 'name' => 'Salads' ),
				(object) array( 'slug' => 'drinks', 'name' => 'Drinks' ),
			);

			$kept      = CategoryVisibility::filterRenderable( $cats, $this->site, array( $this->menu ) );
			$keptSlugs = array_map( static fn( $c ) => $c->slug, $kept );

			$this->assertSame( array( 'burgers', 'drinks' ), $keptSlugs );
			$this->assertSame( array( 0, 1 ), array_keys( $kept ) );
		}

		public function test_filter_keeps_category_renderable_under_any_menu(): void {
			$cats = array( (object) array( 'slug' => 'drinks', 'name' => 'Drinks' ) );

			$kept = CategoryVisibility::filterRenderable( $cats, $this->site, array( 'menu-X', 'menu-A' ) );

			$this->assertCount( 1, $kept );
			$this->assertSame( 'drinks', $kept[0]->slug );
		}

		public function test_filter_keeps_rows_with_empty_slug(): void {
			$cats = array( (object) array( 'slug' => '', 'name' => 'Weird' ) );

			$kept = CategoryVisibility::filterRenderable( $cats, $this->site, array( $this->menu ) );

			$this->assertCount( 1, $kept );
		}
	}
}
