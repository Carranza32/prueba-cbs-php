<?php

namespace CBSNorthStar\Tests\Helpers;

use CBSNorthStar\Helpers\MenuRenderCache;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MenuRenderCache::productKey() (OE-26548).
 *
 * The key is the leakage guard for the cached menu product blocks: a block cached for
 * one site/menu/daypart/category/sort/version must NEVER collide with another scope
 * (OE-26387 / OE-26399 / OE-26454). These tests assert that every scope dimension
 * changes the key, and that an identical scope reproduces the same key (a real cache hit).
 *
 * Pure helper — no WordPress dependency — so it runs identically with or without the WP
 * test library loaded.
 */
final class MenuRenderCacheTest extends TestCase {

	/** Baseline scope reused across collision cases. */
	private function baseKey(): string {
		return MenuRenderCache::productKey( 'burgers', 'site-1', 'menu-A', 20, 1, 'menu_value_num', 'total_sales', 'ASC', '7' );
	}

	public function test_same_scope_yields_same_key(): void {
		$this->assertSame( $this->baseKey(), $this->baseKey() );
	}

	public function test_key_is_prefixed(): void {
		$this->assertStringStartsWith( MenuRenderCache::PRODUCT_KEY_PREFIX, $this->baseKey() );
	}

	/**
	 * @dataProvider scopeDimensionProvider
	 */
	public function test_each_scope_dimension_changes_the_key( string $slug, string $site, string $menu, int $perPage, int $productPage, string $orderby, string $metaKey, string $order, string $version ): void {
		$variant = MenuRenderCache::productKey( $slug, $site, $menu, $perPage, $productPage, $orderby, $metaKey, $order, $version );
		$this->assertNotSame( $this->baseKey(), $variant );
	}

	/**
	 * Each row flips exactly one dimension away from the baseline
	 * ('burgers','site-1','menu-A',20,1,'menu_value_num','total_sales','ASC','7').
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:int,4:int,5:string,6:string,7:string,8:string}>
	 */
	public static function scopeDimensionProvider(): array {
		return array(
			'different category'     => array( 'drinks', 'site-1', 'menu-A', 20, 1, 'menu_value_num', 'total_sales', 'ASC', '7' ),
			'different site'         => array( 'burgers', 'site-2', 'menu-A', 20, 1, 'menu_value_num', 'total_sales', 'ASC', '7' ),
			'different menu'         => array( 'burgers', 'site-1', 'menu-B', 20, 1, 'menu_value_num', 'total_sales', 'ASC', '7' ),
			'different per page'     => array( 'burgers', 'site-1', 'menu-A', 50, 1, 'menu_value_num', 'total_sales', 'ASC', '7' ),
			'different product page' => array( 'burgers', 'site-1', 'menu-A', 20, 2, 'menu_value_num', 'total_sales', 'ASC', '7' ),
			'different orderby'      => array( 'burgers', 'site-1', 'menu-A', 20, 1, 'title', 'total_sales', 'ASC', '7' ),
			'different meta key'     => array( 'burgers', 'site-1', 'menu-A', 20, 1, 'menu_value_num', '_wc_average_rating', 'ASC', '7' ),
			'different order'        => array( 'burgers', 'site-1', 'menu-A', 20, 1, 'menu_value_num', 'total_sales', 'DESC', '7' ),
			'different version'      => array( 'burgers', 'site-1', 'menu-A', 20, 1, 'menu_value_num', 'total_sales', 'ASC', '8' ),
		);
	}

	public function test_empty_site_and_menu_are_distinct_from_resolved_scope(): void {
		$failClosed = MenuRenderCache::productKey( 'burgers', '', '', 20, 1, '', '', '', '7' );
		$resolved   = MenuRenderCache::productKey( 'burgers', 'site-1', 'menu-A', 20, 1, '', '', '', '7' );

		$this->assertNotSame( $resolved, $failClosed );
	}

	/**
	 * Unit tests for MenuRenderCache::loadmoreKey() (OE-26548 T1).
	 *
	 * Same leakage-guard contract as productKey(): every scope dimension must
	 * change the key, and an identical scope must reproduce the same key. Unlike
	 * productKey(), loadmoreKey() has no orderby/order dimensions — /loadmore's
	 * WP_Query never varies ordering — so those are deliberately absent here.
	 */
	private function baseLoadmoreKey(): string {
		return MenuRenderCache::loadmoreKey( 'burgers', 1, 'site-1', 'menu-A', 12, '7' );
	}

	public function test_loadmore_same_scope_yields_same_key(): void {
		$this->assertSame( $this->baseLoadmoreKey(), $this->baseLoadmoreKey() );
	}

	public function test_loadmore_key_is_prefixed(): void {
		$this->assertStringStartsWith( MenuRenderCache::LOADMORE_KEY_PREFIX, $this->baseLoadmoreKey() );
	}

	public function test_loadmore_prefix_is_distinct_from_product_key_prefix(): void {
		$this->assertNotSame( MenuRenderCache::PRODUCT_KEY_PREFIX, MenuRenderCache::LOADMORE_KEY_PREFIX );
	}

	/**
	 * @dataProvider loadmoreScopeDimensionProvider
	 */
	public function test_loadmore_each_scope_dimension_changes_the_key( string $category, int $page, string $siteId, string $menuId, int $perPage, string $version ): void {
		$variant = MenuRenderCache::loadmoreKey( $category, $page, $siteId, $menuId, $perPage, $version );
		$this->assertNotSame( $this->baseLoadmoreKey(), $variant );
	}

	/**
	 * Each row flips exactly one dimension away from the baseline
	 * ('burgers',1,'site-1','menu-A',12,'7').
	 *
	 * @return array<string,array{0:string,1:int,2:string,3:string,4:int,5:string}>
	 */
	public static function loadmoreScopeDimensionProvider(): array {
		return array(
			'different category' => array( 'drinks', 1, 'site-1', 'menu-A', 12, '7' ),
			'different page'     => array( 'burgers', 2, 'site-1', 'menu-A', 12, '7' ),
			'different site'     => array( 'burgers', 1, 'site-2', 'menu-A', 12, '7' ),
			'different menu'     => array( 'burgers', 1, 'site-1', 'menu-B', 12, '7' ),
			'different per page' => array( 'burgers', 1, 'site-1', 'menu-A', 24, '7' ),
			'different version'  => array( 'burgers', 1, 'site-1', 'menu-A', 12, '8' ),
		);
	}

	public function test_loadmore_empty_site_and_menu_are_distinct_from_resolved_scope(): void {
		$failClosed = MenuRenderCache::loadmoreKey( 'burgers', 1, '', '', 12, '7' );
		$resolved   = MenuRenderCache::loadmoreKey( 'burgers', 1, 'site-1', 'menu-A', 12, '7' );

		$this->assertNotSame( $resolved, $failClosed );
	}

	/**
	 * Unit tests for MenuRenderCache::categoryVisibilityKey() (OE-26548 T5).
	 */
	private function baseCategoryVisibilityKey(): string {
		return MenuRenderCache::categoryVisibilityKey( 'site-1', 'menu-A', '7' );
	}

	public function test_category_visibility_same_scope_yields_same_key(): void {
		$this->assertSame( $this->baseCategoryVisibilityKey(), $this->baseCategoryVisibilityKey() );
	}

	public function test_category_visibility_key_is_prefixed(): void {
		$this->assertStringStartsWith( MenuRenderCache::CATVIS_KEY_PREFIX, $this->baseCategoryVisibilityKey() );
	}

	public function test_category_visibility_prefix_is_distinct_from_other_prefixes(): void {
		$this->assertNotSame( MenuRenderCache::PRODUCT_KEY_PREFIX, MenuRenderCache::CATVIS_KEY_PREFIX );
		$this->assertNotSame( MenuRenderCache::LOADMORE_KEY_PREFIX, MenuRenderCache::CATVIS_KEY_PREFIX );
	}

	/**
	 * @dataProvider categoryVisibilityScopeDimensionProvider
	 */
	public function test_category_visibility_each_scope_dimension_changes_the_key( string $siteId, string $menuId, string $version ): void {
		$variant = MenuRenderCache::categoryVisibilityKey( $siteId, $menuId, $version );
		$this->assertNotSame( $this->baseCategoryVisibilityKey(), $variant );
	}

	/**
	 * Each row flips exactly one dimension away from the baseline
	 * ('site-1','menu-A','7').
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function categoryVisibilityScopeDimensionProvider(): array {
		return array(
			'different site'    => array( 'site-2', 'menu-A', '7' ),
			'different menu'    => array( 'site-1', 'menu-B', '7' ),
			'different version' => array( 'site-1', 'menu-A', '8' ),
		);
	}
}
