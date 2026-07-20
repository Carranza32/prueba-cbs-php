<?php

namespace CBSNorthStar\Tests;

use CBSNorthStar\SaveProduct;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for the picture_record gating behavior (harden-deploy-image-
 * resilience, tasks 3.1, 3.3).
 *
 * updateProductImage() (SaveProduct.php ~2585-2598) already guards on
 * `$attachmentId <= 0` before ever calling set_post_thumbnail() /
 * updateImageProductRecord() — since getImage() returns null on a
 * verification failure (tasks 1-2), and (int) null === 0, that guard means
 * a failed verification never writes a picture_record row. This test proves
 * the second half of that claim directly: with no picture_record row
 * present, getImage() does NOT short-circuit on the "unchanged" branch —
 * it proceeds to fetch from ECM, exactly as it would for a genuinely new
 * item. That is what makes a previously-failed item retry on the next
 * deploy with no special repair logic required.
 */
final class SaveProductImageSkipGuardTest extends TestCase {

	private function invokeUpdateProductImage( SaveProduct $instance, $proImg, $postId, $itemid, $mediaItemId ) {
		$method = new ReflectionMethod( SaveProduct::class, 'updateProductImage' );
		$method->setAccessible( true );
		return $method->invoke( $instance, $proImg, $postId, $itemid, $mediaItemId );
	}

	public function test_null_attachment_id_never_calls_set_post_thumbnail_or_writes_a_record(): void {
		// newInstanceWithoutConstructor + no updateImageProductRecord/set_post_thumbnail
		// stubbing needed: if the guard did NOT short-circuit, the real
		// set_post_thumbnail()/$wpdb call inside updateImageProductRecord()
		// would fatally error in this bare unit-test context (no WP/DB loaded) —
		// a clean null return is itself proof the write path was never reached.
		$instance = ( new \ReflectionClass( SaveProduct::class ) )->newInstanceWithoutConstructor();

		$result = $this->invokeUpdateProductImage( $instance, null, 123, 'item-1', 'media-1' );
		$this->assertNull( $result );
	}

	public function test_zero_attachment_id_never_calls_set_post_thumbnail_or_writes_a_record(): void {
		$instance = ( new \ReflectionClass( SaveProduct::class ) )->newInstanceWithoutConstructor();

		$result = $this->invokeUpdateProductImage( $instance, 0, 123, 'item-1', 'media-1' );
		$this->assertNull( $result );
	}

	public function test_no_existing_record_causes_getimage_to_fetch_rather_than_skip(): void {
		// Simulates the state left behind by a prior verification failure: no
		// picture_record row for this item. Only protected methods are
		// stubbed — sideloadImageUrl()/attachLocalImage() are private and
		// cannot be overridden (PHP resolves private calls statically to the
		// declaring class), so the API response is deliberately shaped to hit
		// storeAndOptimizeImage()'s existing "missing fields" early return,
		// keeping the rest of the real (unstubbed) pipeline safely unreached.
		// The property under test is the mock expectation below: with no
		// existing record, getImage() must attempt a fetch, not skip.
		$mock = $this->getMockBuilder( SaveProduct::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'imageExistInRecord', 'getMediaItemFromAPI' ] )
			->getMock();

		$mock->expects( $this->once() )
			->method( 'imageExistInRecord' )
			->willReturn( [] ); // no row — exactly what a failed verification leaves behind

		$mock->expects( $this->once() )
			->method( 'getMediaItemFromAPI' )
			->with( 'media-1' )
			->willReturn( (object) [ 'Data' => (object) [] ] );

		$method = new ReflectionMethod( SaveProduct::class, 'getImage' );
		$method->setAccessible( true );
		$result = $method->invoke( $mock, 'media-1', 42, 'item-1' );

		$this->assertNull( $result ); // storeAndOptimizeImage() rejects the empty payload — the fetch-attempt assertion is the mock expectation above
	}
}
