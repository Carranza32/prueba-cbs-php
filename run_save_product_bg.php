<?php

$root = dirname(dirname(dirname(dirname(dirname(__FILE__)))));


if (file_exists($root.'/wordpress2/wp-load.php')) 
{
// WP 2.6
require_once($root.'/wordpress2/wp-load.php');
} else 
{
// Before 2.6
require_once($root.'/wordpress2/wp-config.php');
}
global $wpdb;

function execInBackground($cmd) {

    if (substr(php_uname(), 0, 7) == "Windows"){
        pclose(popen("start /B ". $cmd, "r")); 

    }
    else {
      $s=  shell_exec($cmd . ' > /dev/null &');  
      print_r($s);
      exit;
    }
}

//$path=$root."/wp-content/plugins/northstaronlineordering/";
//execInBackground('start cmd.exe');
