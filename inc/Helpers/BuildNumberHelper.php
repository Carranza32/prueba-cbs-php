<?php

namespace CBSNorthStar\Helpers;

class BuildNumberHelper
{
    public static function getBuildNumber(): string
    {
        if (defined('CBS_BUILD_VERSION')) {
            return CBS_BUILD_VERSION;
        }

    $buildFile = plugin_dir_path(__FILE__) . '../../build.txt';
      if ( file_exists($buildFile) ) {
        $v = trim((string) file_get_contents($buildFile));
        if ( $v !== '' ) return $v;
      }
      return '0.0.0';
    }
}
