<?php


use CBSNorthStar\Woapi\Connection;

require_once dirname(__FILE__) . '/../inc/Woapi/Connection.php';


function getAreaExternalCode($site_id,$url,$area)
{
   $connection = new Connection();
     $response = $connection->getData($site_id, $url, 'Token');
     $ec=0;
     if(!empty($response)){
        foreach($response as $data){
          foreach($data as $areas){
          if( $areas->AreaId==$area)
          {
            $ec=$areas->ExternalCode;
          }
            
          }
        }
      }
      return $ec;
}

if(isset($_POST["area"])){
    $location_arr = array();
    $area = $_POST["area"];
    $site_id = $_POST["site"];
    $url = '/locations';
    $connection = new Connection();
    $response = $connection->getData($site_id, $url, 'Token');
    $locations = $response->Data;
    $ec=getAreaExternalCode($site_id,'/areas',$area);

    if($area !== 'Select'){
        echo '<input id="areaExternalCode" name="areaExternalCode" type="hidden" value="'.$ec.'"><select class="locations">';

        foreach($locations as $location){
           if($location->AreaId === $area){
            echo '<option value='.$location->ExternalCode.'>'.$location->Name.'</option>';
           }
        }
        echo '</select>';
        echo '<input type="submit" name="qrcode_submit" value="Generate QR Code">';
    }
}

