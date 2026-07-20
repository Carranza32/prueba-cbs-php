<?php

namespace CBSNorthStar\Tests;

use CBSNorthStar\SaveProduct;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for SaveProduct::menuHasLinkToData() (OE-26396, Defect 2).
 *
 * A LinkToDataJson item assembles its linked subcategory from a separate
 * /menuitemsbycategory/ endpoint that the menu-payload hash never covers, so a
 * menu containing one must never be menu-hash-skipped. The helper is pure (no
 * DB / WordPress dependencies), so it is exercised directly via reflection on
 * an instance built without the DB-coupled constructor.
 */
final class SaveProductMenuSkipGuardTest extends TestCase {

	private function invokeMenuHasLinkToData( $menuItems ): bool {
		// newInstanceWithoutConstructor() avoids ConfigurationRepository::create()
		// et al., which touch the DB; menuHasLinkToData()/ensureArray() do not.
		$instance = ( new \ReflectionClass( SaveProduct::class ) )->newInstanceWithoutConstructor();
		$method   = new ReflectionMethod( SaveProduct::class, 'menuHasLinkToData' );
		$method->setAccessible( true );
		return $method->invoke( $instance, $menuItems );
	}

	/**
	 * @dataProvider menuProvider
	 */
	public function test_menu_has_link_to_data( $menuItems, bool $expected ): void {
		$this->assertSame( $expected, $this->invokeMenuHasLinkToData( $menuItems ) );
	}

	/**
	 * @return array<string,array{0:mixed,1:bool}>
	 */
	public static function menuProvider(): array {
		$linked = (object) [ 'MenuItemId' => 'b', 'LinkToDataJson' => (object) [ 'MenuItemCategoryId' => 'cat-1' ] ];
		$plain  = (object) [ 'MenuItemId' => 'a' ];

		return [
			'plain menu (no linked items)'  => [ [ $plain, (object) [ 'MenuItemId' => 'c' ] ], false ],
			'menu with one linked item'     => [ [ $plain, $linked ], true ],
			'single linked object (not arr)' => [ $linked, true ],
			'single plain object (not arr)' => [ $plain, false ],
			'null menu items'               => [ null, false ],
			'empty array'                   => [ [], false ],
			'empty string'                  => [ '', false ],
			'null LinkToDataJson flag'      => [ [ (object) [ 'MenuItemId' => 'a', 'LinkToDataJson' => null ] ], false ],
			'empty-object LinkToDataJson'    => [ [ (object) [ 'MenuItemId' => 'a', 'LinkToDataJson' => '' ] ], false ],
		];
	}
}
