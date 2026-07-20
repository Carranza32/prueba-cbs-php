<?php

namespace CBSNorthStar\Tests;

use CBSNorthStar\SaveProduct;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for SaveProduct::storeAndOptimizeImage()'s source-integrity
 * gate (harden-deploy-image-resilience, tasks 1.3-1.4, 1.5).
 *
 * The instance is built via newInstanceWithoutConstructor() (as in
 * SaveProductMenuSkipGuardTest) so $fileManager is left null. A truncated
 * payload must return null WITHOUT ever reaching $this->fileManager -> if
 * these tests pass despite fileManager being unset, that proves the
 * rejection happens before any GD/FileManager call, not after one fails.
 */
final class SaveProductStoreImageTest extends TestCase {

	private const PNG_COMPLETE  = "\x89PNG\x0D\x0A\x1A\x0A" . 'chunk-bytes' . "\x00\x00\x00\x00IEND\xAE\x42\x60\x82";
	private const PNG_TRUNCATED = "\x89PNG\x0D\x0A\x1A\x0A" . 'chunk-bytes-cut-off-mid-transfer';

	private function invokeStoreAndOptimizeImage( $response ) {
		$instance = ( new \ReflectionClass( SaveProduct::class ) )->newInstanceWithoutConstructor();
		$method   = new ReflectionMethod( SaveProduct::class, 'storeAndOptimizeImage' );
		$method->setAccessible( true );
		return $method->invoke( $instance, $response );
	}

	private function response( string $binary ): object {
		return (object) [
			'Data' => (object) [
				'FileName'    => 'test-image',
				'MediaItemId' => 'media-item-1',
				'MediaData'   => base64_encode( $binary ),
			],
		];
	}

	public function test_truncated_source_returns_null_without_touching_file_manager(): void {
		// fileManager is left null by newInstanceWithoutConstructor(); a call
		// into it (e.g. compressImage()) would throw a PHP Error on a null
		// method call, which PHPUnit would surface as a test failure/error —
		// so a clean null return here is proof the rejection happened first.
		$result = $this->invokeStoreAndOptimizeImage( $this->response( self::PNG_TRUNCATED ) );
		$this->assertNull( $result );
	}

	public function test_unrecognized_format_returns_null_without_touching_file_manager(): void {
		$result = $this->invokeStoreAndOptimizeImage( $this->response( 'not-an-image-payload-at-all' ) );
		$this->assertNull( $result );
	}

	public function test_missing_media_data_returns_null(): void {
		$response = (object) [
			'Data' => (object) [
				'FileName'    => 'test-image',
				'MediaItemId' => 'media-item-1',
				'MediaData'   => '',
			],
		];
		$this->assertNull( $this->invokeStoreAndOptimizeImage( $response ) );
	}
}
