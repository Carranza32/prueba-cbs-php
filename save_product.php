#!/usr/bin/php
<?php
/**
 * Save Product
 *
 * Used to fetch data from api and save in woocommerce
 * this file will be used by the webhooks
 *
 * @package Northstaronlineordering\Save_Product
 * @version 1.0.0
 */

use CBSNorthStar\SaveProduct;

$root = realpath(dirname(dirname(dirname(dirname($_SERVER["SCRIPT_FILENAME"])))));

if (file_exists($root . '/wp-load.php')) {
  // WP 2.6
  require_once $root . '/wp-load.php';
}

if ( ! defined( 'CBS_PLUGIN_FILE' ) ) {
  define( 'CBS_PLUGIN_FILE', __FILE__ );
}

if ( file_exists( dirname(__FILE__) . '/vendor/autoload.php' ) ) {
  require_once dirname(__FILE__) . '/vendor/autoload.php' ;
}


if(class_exists(SaveProduct::class)){
  $result = (new SaveProduct())->store();
  session_start();
  if($result['success']){
    $redirect_url = get_site_url() .
      "/wp-admin/admin.php?page=northstaronlineordering%2Fnorthstaronlineordering.php";
  }else{
    $redirect_url = get_site_url() .
      "/wp-admin/admin.php?error=1&page=northstaronlineordering%2Fnorthstaronlineordering.php&sites_error=";
    foreach ($GLOBALS['sites_error'] as $sitee) {
      $redirect_url.=$sitee.",";
    }
    $redirect_url=substr_replace($redirect_url,"",-1);
  }

  header("Location: " . $redirect_url);
  session_write_close();
}
