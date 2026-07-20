<?php

namespace CBSNorthStar\Helpers;

use CBSNorthStar\Logger\CBSLogger;
use CBSNorthStar\Helpers\ImageIntegrity;

class FileManager
{
  protected static $instance = null;
  public static function create(): ?FileManager
  {
    if (self::$instance === null) {
      self::$instance = new static();
    }

    return self::$instance;
  }

  public function deleteFilesFromFolder($path)
  {
    CBSLogger::general()->info('Deleting files from folder', ['path' => $path]);
    $gitkeep = '.gitkeep';

    if (is_dir($path) && $dh = opendir($path)) {
      while (($file = readdir($dh)) !== false) {

        if ($file != $gitkeep){
          unlink($path . $file);
        }
      }
      closedir($dh);
    }
  }

  public function compressImage($sourceUrl, $destinationUrl, $compression)
  {
    $info = getimagesize($sourceUrl);

    $mime = strtolower(trim($info['mime'] ?? ''));

    if ($mime == 'image/jpeg') {
      $image = imagecreatefromjpeg($sourceUrl);
    } elseif ($mime == 'image/gif') {
      $image = imagecreatefromgif($sourceUrl);
    } elseif ($mime == 'image/png') {
      $image = imagecreatefrompng($sourceUrl);
    }

    if (!$image) {
        throw new \RuntimeException('Could not load image: ' . $sourceUrl);
    }

    imagealphablending($image, false);
    imagesavealpha($image, true);
    imageinterlace($image, false);

    $filters = PNG_ALL_FILTERS;
    $wrote   = imagepng($image, $destinationUrl, $compression, $filters);
    imagedestroy($image);

    if (!$wrote) {
        // imagepng() can return false after having already written a partial file
        // (e.g. disk-full mid-write) — remove it so SaveProduct's file_exists-based
        // disk-cache checks never mistake it for a valid cached image.
        if (is_file($destinationUrl)) {
            unlink($destinationUrl);
        }
        throw new \RuntimeException('Image write failed: ' . $destinationUrl);
    }

    // Write-integrity check: confirm the file we just wrote actually ends
    // with PNG's terminator chunk. imagepng() can return true while having
    // written an incomplete file if the process is interrupted mid-write
    // (disk pressure, OOM/timeout kill) — this catches that case before the
    // caller ever treats the file as a good one.
    if (!ImageIntegrity::verifyFileComplete($destinationUrl, 'png')) {
        unlink($destinationUrl);
        throw new \RuntimeException('Image write incomplete (failed terminator check): ' . $destinationUrl);
    }

    //return destination file
    return $destinationUrl;
  }

}
