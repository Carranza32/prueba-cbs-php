<?php

namespace CBSNorthStar\Helpers;

/**
 * Cheap, structural completeness checks for deployed product images.
 *
 * These are deliberately NOT full image decodes — only magic-byte format
 * detection and fixed-size header/trailer comparisons — so they can run on
 * every image in a deploy without adding meaningful CPU/memory cost. See
 * openspec/changes/harden-deploy-image-resilience/design.md (Decision 1).
 */
class ImageIntegrity
{
  private const JPEG_SOI = "\xFF\xD8";
  private const JPEG_EOI = "\xFF\xD9";

  private const PNG_SIGNATURE = "\x89PNG\x0D\x0A\x1A\x0A";
  // IEND carries no chunk data, so its CRC32 (over just the ASCII bytes
  // "IEND") is a fixed constant — length(4) + "IEND" + CRC32(4).
  private const PNG_IEND = "\x00\x00\x00\x00IEND\xAE\x42\x60\x82";

  private const GIF_SIGNATURE_87 = 'GIF87a';
  private const GIF_SIGNATURE_89 = 'GIF89a';
  private const GIF_TRAILER      = "\x3B";

  /**
   * Detect an image format from its leading magic bytes.
   *
   * @param string $binary Raw binary data (not base64-encoded).
   * @return string|null 'jpeg'|'png'|'gif', or null if unrecognized.
   */
  public static function detectFormat( string $binary ): ?string
  {
    if ( strncmp( $binary, self::JPEG_SOI, 2 ) === 0 ) {
      return 'jpeg';
    }

    if ( strncmp( $binary, self::PNG_SIGNATURE, 8 ) === 0 ) {
      return 'png';
    }

    if ( strncmp( $binary, self::GIF_SIGNATURE_87, 6 ) === 0 || strncmp( $binary, self::GIF_SIGNATURE_89, 6 ) === 0 ) {
      return 'gif';
    }

    return null;
  }

  /**
   * Check that raw binary data ends with its format's structural terminator.
   *
   * A truncated write/transfer is, by definition, missing the terminator —
   * this catches that without decoding any pixel data.
   *
   * @param string $binary Raw binary data (not base64-encoded).
   * @param string $format 'jpeg'|'png'|'gif' (from detectFormat()).
   */
  public static function hasValidTerminator( string $binary, string $format ): bool
  {
    switch ( $format ) {
      case 'jpeg':
        return substr( $binary, -2 ) === self::JPEG_EOI;
      case 'png':
        return substr( $binary, -12 ) === self::PNG_IEND;
      case 'gif':
        return substr( $binary, -1 ) === self::GIF_TRAILER;
      default:
        return false;
    }
  }

  /**
   * Detect format + verify terminator on an in-memory binary string in one call.
   *
   * @param string $binary Raw binary data (not base64-encoded).
   * @return array{ok: bool, format: string|null} 'format' is null when unrecognized.
   */
  public static function verifyBinaryComplete( string $binary ): array
  {
    if ( $binary === '' ) {
      return [ 'ok' => false, 'format' => null ];
    }

    $format = self::detectFormat( $binary );
    if ( $format === null ) {
      return [ 'ok' => false, 'format' => null ];
    }

    return [ 'ok' => self::hasValidTerminator( $binary, $format ), 'format' => $format ];
  }

  /**
   * Same check as verifyBinaryComplete(), but against a file on disk —
   * reads only the trailing bytes needed for the terminator check (and the
   * leading bytes for format detection if $format is not already known), not
   * the whole file.
   *
   * @param string      $filePath Absolute path to the file to check.
   * @param string|null $format   Known format ('jpeg'|'png'|'gif'), or null to detect from the file.
   */
  public static function verifyFileComplete( string $filePath, ?string $format = null ): bool
  {
    if ( ! is_readable( $filePath ) ) {
      return false;
    }

    $handle = fopen( $filePath, 'rb' );
    if ( ! $handle ) {
      return false;
    }

    try {
      if ( $format === null ) {
        $head   = fread( $handle, 8 );
        $format = $head === false ? null : self::detectFormat( $head );
        if ( $format === null ) {
          return false;
        }
      }

      $tailLength = $format === 'png' ? 12 : ( $format === 'jpeg' ? 2 : 1 );
      if ( fseek( $handle, -$tailLength, SEEK_END ) !== 0 ) {
        return false; // File shorter than its own terminator — can't be complete.
      }

      $tail = fread( $handle, $tailLength );
      return $tail !== false && self::hasValidTerminator( $tail, $format );
    } finally {
      fclose( $handle );
    }
  }
}
