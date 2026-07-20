<?php

namespace CBSNorthStar\Tests\Helpers;

use CBSNorthStar\Helpers\ImageIntegrity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ImageIntegrity's structural completeness checks
 * (harden-deploy-image-resilience, tasks 1.1-1.2, 1.5).
 *
 * These are pure byte-level checks — no real image encoding/decoding is
 * needed to exercise them, only the magic-byte prefixes and terminator
 * suffixes each format defines.
 */
final class ImageIntegrityTest extends TestCase {

	private const JPEG_COMPLETE   = "\xFF\xD8" . 'not-real-scan-data' . "\xFF\xD9";
	private const JPEG_TRUNCATED  = "\xFF\xD8" . 'not-real-scan-data-cut-off';

	private const PNG_COMPLETE  = "\x89PNG\x0D\x0A\x1A\x0A" . 'chunk-bytes' . "\x00\x00\x00\x00IEND\xAE\x42\x60\x82";
	private const PNG_TRUNCATED = "\x89PNG\x0D\x0A\x1A\x0A" . 'chunk-bytes-cut-off-mid-write';

	private const GIF_COMPLETE  = 'GIF89a' . 'frame-data' . "\x3B";
	private const GIF_TRUNCATED = 'GIF89a' . 'frame-data-cut-off';

	public function test_detects_jpeg(): void {
		$this->assertSame( 'jpeg', ImageIntegrity::detectFormat( self::JPEG_COMPLETE ) );
	}

	public function test_detects_png(): void {
		$this->assertSame( 'png', ImageIntegrity::detectFormat( self::PNG_COMPLETE ) );
	}

	public function test_detects_gif(): void {
		$this->assertSame( 'gif', ImageIntegrity::detectFormat( self::GIF_COMPLETE ) );
	}

	public function test_unrecognized_format_returns_null(): void {
		$this->assertNull( ImageIntegrity::detectFormat( 'not-an-image-at-all' ) );
	}

	/**
	 * @dataProvider completeBinaryProvider
	 */
	public function test_complete_binary_passes( string $binary ): void {
		$result = ImageIntegrity::verifyBinaryComplete( $binary );
		$this->assertTrue( $result['ok'] );
	}

	public static function completeBinaryProvider(): array {
		return [
			'jpeg' => [ self::JPEG_COMPLETE ],
			'png'  => [ self::PNG_COMPLETE ],
			'gif'  => [ self::GIF_COMPLETE ],
		];
	}

	/**
	 * @dataProvider truncatedBinaryProvider
	 */
	public function test_truncated_binary_fails( string $binary ): void {
		$result = ImageIntegrity::verifyBinaryComplete( $binary );
		$this->assertFalse( $result['ok'] );
	}

	public static function truncatedBinaryProvider(): array {
		return [
			'jpeg missing EOI'  => [ self::JPEG_TRUNCATED ],
			'png missing IEND'  => [ self::PNG_TRUNCATED ],
			'gif missing trailer' => [ self::GIF_TRUNCATED ],
		];
	}

	public function test_empty_binary_fails(): void {
		$result = ImageIntegrity::verifyBinaryComplete( '' );
		$this->assertFalse( $result['ok'] );
		$this->assertNull( $result['format'] );
	}

	public function test_unrecognized_format_reports_null_format_not_a_crash(): void {
		$result = ImageIntegrity::verifyBinaryComplete( 'garbage-bytes-not-an-image' );
		$this->assertFalse( $result['ok'] );
		$this->assertNull( $result['format'] );
	}

	public function test_file_complete_check_passes_for_intact_file(): void {
		$path = tempnam( sys_get_temp_dir(), 'imgintegrity_' );
		file_put_contents( $path, self::PNG_COMPLETE );

		try {
			$this->assertTrue( ImageIntegrity::verifyFileComplete( $path ) );
			$this->assertTrue( ImageIntegrity::verifyFileComplete( $path, 'png' ) );
		} finally {
			unlink( $path );
		}
	}

	public function test_file_complete_check_fails_for_truncated_file(): void {
		$path = tempnam( sys_get_temp_dir(), 'imgintegrity_' );
		file_put_contents( $path, self::PNG_TRUNCATED );

		try {
			$this->assertFalse( ImageIntegrity::verifyFileComplete( $path ) );
		} finally {
			unlink( $path );
		}
	}

	public function test_file_complete_check_fails_for_missing_file(): void {
		$this->assertFalse( ImageIntegrity::verifyFileComplete( '/tmp/does-not-exist-' . uniqid() ) );
	}

	public function test_file_shorter_than_terminator_fails_without_error(): void {
		$path = tempnam( sys_get_temp_dir(), 'imgintegrity_' );
		file_put_contents( $path, "\x89PNG" ); // valid PNG prefix, but far too short to contain IEND

		try {
			$this->assertFalse( ImageIntegrity::verifyFileComplete( $path ) );
		} finally {
			unlink( $path );
		}
	}
}
