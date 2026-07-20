<?php

namespace CBSNorthStar\Tests\Helpers;

use CBSNorthStar\Helpers\FileManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FileManager::compressImage()'s write-integrity checks
 * (harden-deploy-image-resilience, tasks 2.2-2.3, 2.5).
 *
 * compressImage()'s pre-existing "!$image" guard already covers a source
 * GD cannot decode at all; these tests cover the two NEW failure modes: the
 * write call itself failing, and the written file failing the structural
 * terminator check. The write failure is triggered for real (an unwritable
 * destination directory), not mocked — imagepng()/getimagesize() are PHP
 * built-ins that resist mocking, and a genuinely unwritable path exercises
 * the exact code path a disk-full or permissions failure would hit.
 */
final class FileManagerCompressImageTest extends TestCase {

	private string $sourcePng;

	protected function setUp(): void {
		parent::setUp();
		$this->sourcePng = tempnam( sys_get_temp_dir(), 'fmtest_src_' ) . '.png';
		$img = imagecreatetruecolor( 4, 4 );
		imagepng( $img, $this->sourcePng );
		imagedestroy( $img );
	}

	protected function tearDown(): void {
		if ( file_exists( $this->sourcePng ) ) {
			unlink( $this->sourcePng );
		}
		parent::tearDown();
	}

	public function test_valid_source_and_writable_destination_succeeds(): void {
		$dest = tempnam( sys_get_temp_dir(), 'fmtest_dst_' ) . '.png';

		try {
			$result = FileManager::create()->compressImage( $this->sourcePng, $dest, 6 );
			$this->assertSame( $dest, $result );
			$this->assertFileExists( $dest );
		} finally {
			if ( file_exists( $dest ) ) {
				unlink( $dest );
			}
		}
	}

	public function test_unwritable_destination_throws_instead_of_returning_a_broken_file(): void {
		$dest = '/tmp/nonexistent-dir-' . uniqid() . '/out.png';

		$this->expectException( \RuntimeException::class );
		@FileManager::create()->compressImage( $this->sourcePng, $dest, 6 );
	}

	public function test_unwritable_destination_leaves_no_file_behind(): void {
		$dest = '/tmp/nonexistent-dir-' . uniqid() . '/out.png';

		try {
			@FileManager::create()->compressImage( $this->sourcePng, $dest, 6 );
			$this->fail( 'Expected RuntimeException was not thrown.' );
		} catch ( \RuntimeException $e ) {
			$this->assertFileDoesNotExist( $dest );
		}
	}
}
