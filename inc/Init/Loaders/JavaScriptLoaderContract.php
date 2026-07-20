<?php

namespace CBSNorthStar\Init\Loaders;


interface JavaScriptLoaderContract
{
  public static function create();
  public function registerScripts();

  public function ajaxHandler();
}
