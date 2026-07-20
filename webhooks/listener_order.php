<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: text/plain');

if(isset($_GET["echo"])){
  $data = json_encode($_REQUEST);
  echo  json_decode($data)->echo;
}

 //include wp-config or wp-load.php


if ($_SERVER['REQUEST_METHOD'] == 'POST') 
{
    // fetch RAW input
    $json = file_get_contents('php://input');

    // decode json
    $object = json_decode($json);

    // expecting valid json
    if (json_last_error() !== JSON_ERROR_NONE) 
    {
        die(header('HTTP/1.0 415 Unsupported Media Type'));
    }

    /**
     * Do something with object, structure will be like:
     * $object->accountId
     * $object->details->items[0]['contactName']
     */
    // dump to file so you can see
 //   $root = dirname(dirname(dirname(dirname(dirname(__FILE__)))));
 $root = realpath(dirname(dirname(dirname(dirname(dirname($_SERVER["SCRIPT_FILENAME"]))))));


        if (file_exists($root.'/wp-load.php')) 
        {
        // WP 2.6
        require_once($root.'/wp-load.php');
        } else 
        {
        // Before 2.6
        require_once($root.'/wp-config.php');
        }
      //   $t=time();
      //   $newfile="callback_menu".$t.".txt";
      // file_put_contents($newfile, $json); 
    file_put_contents('finalizedcheck.txt', $json);
     if(isset($object->Notifications[0]->Arguments[0]) && $object->Notifications[0]->Arguments[0]!='')
    {
      global $wpdb;
        $table_name = 'wp_woocommerce_cart_record';
      $wpdb->update($table_name, array('finalized' => 1), array('check_id' => $object->Notifications[0]->Arguments[0])); 
    }
    else
    {
      print_r("error");
    }

   
}
?>