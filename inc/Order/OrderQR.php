<?php
namespace CBSNorthStar\Order;

use CBSNorthStar\Order\Check;

class OrderQR{
    public $connection;
    public $checkId ;
    public $checkNumber;
    public $existingItems;
    public $cart;

    public function handlerSendMenuItems($responseData , $cartItemsKey){

        if(!isset($_COOKIE["checkid"]) && !isset($_COOKIE["checknumber"])){

            $this->existingItems = false;
            $this->checkId = $responseData === "invalid_check" ?
            $this->connection->createCheckLocation() : $responseData->Data->CheckId;
            $this->checkNumber = $responseData === "invalid_check" ?
            $this->connection->getCheckNumber() :  $responseData->Data->CheckNumber;


            if($responseData != "invalid_check" ){
                $this->existingItems = $this->connection->addExistingCart($responseData);
            }


            setcookie("checkid", $this->checkId , time()+86400, '/', null, is_ssl() , true );
            setcookie("checknumber",$this->checkNumber, time()+86400, '/', null, is_ssl() , true );
        }else{

            if($responseData->Data->CheckId === $_COOKIE['checkid'] ){
                $this->checkId = $_COOKIE['checkid'];
            }else{
                //create new check
            }
        }

        $this->cart = WC()->cart;
        $response =  $this->connection->submitItems($this->checkId , $this->cart);

        if($response){
            //set success cookies
            $this->setQrCookies($responseData, $cartItemsKey);
            return $response;
        }
    }

    public function qrHandler($siteid, $northstarJson , $table_num, $areaId , $cartItemsKey ){
        $this->connection = new Check();
        $items = json_decode(stripslashes($northstarJson));
        
        if ($items->orderItems === null) {
            return;
        }
        $validMenuItems = $this->connection->validateMenuItems($siteid, $northstarJson );

        if($validMenuItems->Ok){
            $location = $this->connection->validateLocation($table_num, $areaId );
            return  $this->handlerSendMenuItems($location , $cartItemsKey);
        }
    }

    public function setQrCookies($responseData  , $cartItemsKey){
        if ($responseData === 'invalid_check') {
            if (isset($_COOKIE['cart_item_arr'])) {
                setcookie("cart_item_arr", "" , time() - 3600 , '/', null, is_ssl(), true);
            }
            setcookie("cart_item_arr", json_encode($cartItemsKey), time() + 86400, '/', null, is_ssl(), true);
            setcookie("success_item_qr", true, time() + 86400, '/', null, is_ssl(), true);
        }else{
            if (isset($_COOKIE['cart_item_arr']) && !$this->existingItems) {

                $currentItemsCookie = json_decode(stripslashes($_COOKIE['cart_item_arr']) , true );
                $newItemsCookie = array_merge( $currentItemsCookie , $cartItemsKey );
                 setcookie("cart_item_arr", json_encode($newItemsCookie), time() + 86400, '/', null, is_ssl(), true);

            }elseif ($this->existingItems) {

                $currentItems = $this->existingItems;
                $newCookie = array_merge( $currentItems , $cartItemsKey );
                setcookie("cart_item_arr", json_encode($newCookie), time() + 86400, '/', null, is_ssl(), true);
                setcookie("success_item_qr", true, time() + 86400, '/', null, is_ssl(), true);
            }
        }
    }
}
