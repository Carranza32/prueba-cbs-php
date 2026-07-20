<?php

namespace {
	// Minimal global stubs so verifyRecordedImageIntegrity()/resolveAttachmentByteSize()
	// can be exercised without a full WP/DB environment. $GLOBALS['__attached_files']
	// maps a fabricated attachment ID to the local path the test wrote for it.
	if ( ! function_exists( 'get_attached_file' ) ) {
		function get_attached_file( $attachmentId ) {
			return $GLOBALS['__attached_files'][ $attachmentId ] ?? false;
		}
	}
}

namespace CBSNorthStar\Tests {

	use CBSNorthStar\SaveProduct;
	use PHPUnit\Framework\TestCase;
	use ReflectionMethod;

	/**
	 * Unit tests for verifyRecordedImageIntegrity()/resolveAttachmentByteSize()
	 * (harden-deploy-image-resilience, tasks 4.3-4.4, 4.6, 4.7).
	 */
	final class SaveProductImageVerificationTest extends TestCase {

		private const PNG_COMPLETE  = "\x89PNG\x0D\x0A\x1A\x0A" . 'chunk-bytes' . "\x00\x00\x00\x00IEND\xAE\x42\x60\x82";
		private const PNG_TRUNCATED = "\x89PNG\x0D\x0A\x1A\x0A" . 'chunk-bytes-cut-off-mid-write';

		private array $tempFiles = [];

		protected function tearDown(): void {
			foreach ( $this->tempFiles as $path ) {
				if ( file_exists( $path ) ) {
					unlink( $path );
				}
			}
			$GLOBALS['__attached_files'] = [];
			parent::tearDown();
		}

		private function registerAttachedFile( int $attachmentId, string $binary ): string {
			$path = tempnam( sys_get_temp_dir(), 'imgverify_' );
			file_put_contents( $path, $binary );
			$this->tempFiles[] = $path;
			$GLOBALS['__attached_files'][ $attachmentId ] = $path;
			return $path;
		}

		private function verify( ?int $attachmentId, ?int $expectedBytes, string $itemId = 'item-1' ) {
			$instance = ( new \ReflectionClass( SaveProduct::class ) )->newInstanceWithoutConstructor();
			$method   = new ReflectionMethod( SaveProduct::class, 'verifyRecordedImageIntegrity' );
			$method->setAccessible( true );
			return $method->invoke( $instance, $attachmentId, $expectedBytes, $itemId );
		}

		public function test_matching_expected_bytes_passes(): void {
			$path = $this->registerAttachedFile( 101, self::PNG_COMPLETE );
			$this->assertTrue( $this->verify( 101, strlen( self::PNG_COMPLETE ) ) );
			// Confirms the comparison is against the real file, not a fixed constant.
			$this->assertSame( strlen( self::PNG_COMPLETE ), filesize( $path ) );
		}

		public function test_mismatched_expected_bytes_fails(): void {
			$this->registerAttachedFile( 102, self::PNG_COMPLETE );
			$this->assertFalse( $this->verify( 102, strlen( self::PNG_COMPLETE ) + 1 ) );
		}

		public function test_missing_file_fails_regardless_of_expected_bytes(): void {
			// No registerAttachedFile() call — get_attached_file() returns false.
			$this->assertFalse( $this->verify( 999, 123 ) );
		}

		public function test_legacy_row_with_intact_file_passes(): void {
			$this->registerAttachedFile( 103, self::PNG_COMPLETE );
			// expected_bytes is null — simulates a row written before this column existed.
			$this->assertTrue( $this->verify( 103, null ) );
		}

		public function test_legacy_row_with_corrupted_file_fails(): void {
			$this->registerAttachedFile( 104, self::PNG_TRUNCATED );
			$this->assertFalse( $this->verify( 104, null ) );
		}

		public function test_one_corrupted_item_does_not_affect_verification_of_another(): void {
			// Task 4.7: repair is scoped to the single failing item. This isn't a
			// network/ECM-call-count test (no ECM client here) — it demonstrates
			// the check itself is per-item and stateless across calls, which is
			// the property that makes scoping possible one level up in getImage().
			$this->registerAttachedFile( 201, self::PNG_COMPLETE );
			$this->registerAttachedFile( 202, self::PNG_TRUNCATED );

			$this->assertTrue( $this->verify( 201, strlen( self::PNG_COMPLETE ) ) );
			$this->assertFalse( $this->verify( 202, strlen( self::PNG_COMPLETE ) ) );
			// Re-checking item 201 after item 202 failed confirms no shared/leaked state.
			$this->assertTrue( $this->verify( 201, strlen( self::PNG_COMPLETE ) ) );
		}
	}
}
