<?php

namespace {
	// Minimal postmeta stubs so SaveProduct::writeActiveDateMeta() is testable
	// without a live WP/DB test environment — mirrors CategoryVisibilityTest's
	// wc_get_products stub pattern. Records calls into $GLOBALS for assertion.
	if ( ! function_exists( 'update_post_meta' ) ) {
		function update_post_meta( $postId, $key, $value ) {
			$GLOBALS['__spadm_updated'][ $postId ][ $key ] = $value;
			unset( $GLOBALS['__spadm_deleted'][ $postId ][ $key ] );
			return true;
		}
	}
	if ( ! function_exists( 'delete_post_meta' ) ) {
		function delete_post_meta( $postId, $key ) {
			$GLOBALS['__spadm_deleted'][ $postId ][ $key ] = true;
			unset( $GLOBALS['__spadm_updated'][ $postId ][ $key ] );
			return true;
		}
	}
}

namespace CBSNorthStar\Tests {

	use CBSNorthStar\Helpers\MenuItemActiveWindow;
	use CBSNorthStar\Models\ProductParams;
	use CBSNorthStar\SaveProduct;
	use PHPUnit\Framework\TestCase;
	use ReflectionMethod;

	/**
	 * Unit tests for SaveProduct::writeActiveDateMeta() — the deploy-write
	 * helper shared by custombizAddSimpleProduct()/custombizUpdateSimpleProduct()
	 * (product-active-date-window / save-product-deploy capabilities). Exercised
	 * via reflection on an instance built without the DB-coupled constructor
	 * (same pattern as SaveProductItemFingerprintTest).
	 */
	final class SaveProductActiveDateMetaTest extends TestCase {

		private const SITE_ID = 'site-1';
		private const POST_ID = 42;

		protected function setUp(): void {
			parent::setUp();
			$GLOBALS['__spadm_updated'] = [];
			$GLOBALS['__spadm_deleted'] = [];
		}

		private function writeActiveDateMeta( ?int $activeStart, ?int $activeStop ): void {
			$instance = ( new \ReflectionClass( SaveProduct::class ) )->newInstanceWithoutConstructor();

			$params = new ProductParams( [
				'proName'            => 'Test Item',
				'proprice'           => 0,
				'proDes'             => '',
				'numberOfPlacements' => 0,
				'type'               => '',
				'comboQualifierIds'  => [],
				'termId'             => 0,
				'proImg'             => '',
				'components'         => [],
				'siteid'             => self::SITE_ID,
				'itemid'             => 'item-1',
				'mediaItemId'        => null,
				'displayOrder'       => 0,
				'servingOptions'     => [],
				'activeStart'        => $activeStart,
				'activeStop'         => $activeStop,
			] );

			$method = new ReflectionMethod( SaveProduct::class, 'writeActiveDateMeta' );
			$method->setAccessible( true );
			$method->invoke( $instance, self::POST_ID, $params );
		}

		public function test_both_dates_supplied_writes_both(): void {
			$this->writeActiveDateMeta( 1000, 2000 );

			$this->assertSame(
				1000,
				$GLOBALS['__spadm_updated'][ self::POST_ID ][ MenuItemActiveWindow::startKey( self::SITE_ID ) ]
			);
			$this->assertSame(
				2000,
				$GLOBALS['__spadm_updated'][ self::POST_ID ][ MenuItemActiveWindow::stopKey( self::SITE_ID ) ]
			);
		}

		public function test_only_start_supplied_clears_stop(): void {
			$this->writeActiveDateMeta( 1000, null );

			$this->assertSame(
				1000,
				$GLOBALS['__spadm_updated'][ self::POST_ID ][ MenuItemActiveWindow::startKey( self::SITE_ID ) ]
			);
			$this->assertTrue(
				$GLOBALS['__spadm_deleted'][ self::POST_ID ][ MenuItemActiveWindow::stopKey( self::SITE_ID ) ]
			);
		}

		public function test_only_stop_supplied_clears_start(): void {
			$this->writeActiveDateMeta( null, 2000 );

			$this->assertTrue(
				$GLOBALS['__spadm_deleted'][ self::POST_ID ][ MenuItemActiveWindow::startKey( self::SITE_ID ) ]
			);
			$this->assertSame(
				2000,
				$GLOBALS['__spadm_updated'][ self::POST_ID ][ MenuItemActiveWindow::stopKey( self::SITE_ID ) ]
			);
		}

		public function test_neither_supplied_clears_both(): void {
			$this->writeActiveDateMeta( null, null );

			$this->assertTrue(
				$GLOBALS['__spadm_deleted'][ self::POST_ID ][ MenuItemActiveWindow::startKey( self::SITE_ID ) ]
			);
			$this->assertTrue(
				$GLOBALS['__spadm_deleted'][ self::POST_ID ][ MenuItemActiveWindow::stopKey( self::SITE_ID ) ]
			);
		}

		public function test_value_update_on_redeploy_overwrites_previous(): void {
			$this->writeActiveDateMeta( 1000, 2000 );
			$this->writeActiveDateMeta( 5000, 6000 );

			$this->assertSame(
				5000,
				$GLOBALS['__spadm_updated'][ self::POST_ID ][ MenuItemActiveWindow::startKey( self::SITE_ID ) ]
			);
			$this->assertSame(
				6000,
				$GLOBALS['__spadm_updated'][ self::POST_ID ][ MenuItemActiveWindow::stopKey( self::SITE_ID ) ]
			);
		}
	}
}
