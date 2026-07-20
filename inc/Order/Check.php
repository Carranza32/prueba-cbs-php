<?php
namespace CBSNorthStar\Order;

use CBSNorthStar\Dto\LocationDto;
use CBSNorthStar\Repositories\CartRecordRepository;
use CBSNorthStar\Woapi\Connection;
use CBSNorthStar\Services\ProductManager;
use CBSNorthStar\Logger\CBSLogger;

class Check {
    public $checkid;
    public $url;
    public $tokenType;
    public $siteId;
    public $northJson;
    public $locationJson;
    public $area;
    public $table;
    public $checknumber;
    protected const ERROR_MESSAGE = "Something happened! Please contact the restaurant staff.";

    public function __construct($siteId = null )
    {
        $this->siteId = $siteId;
        $this->tokenType = 'Token';
    }


    /**
     * Set the parameters for the check.
     *
     * @param mixed $siteId
     * @param mixed $token
     * @return void
     */
    public function setParameters($siteId, $token ){
        $this->siteId = $siteId;
        $this->tokenType = $token;
    }

    /**
     * validate products
     * @param $siteId
     * @param $northJson
     * @return false|mixed|object
     */
    public function validateMenuItems($siteId, $northJson){
        $this->url = '/checks/validate/';
        $this->tokenType = 'Token';
        $this->siteId = $siteId ;

        $items = json_decode(stripslashes($northJson), true);
            unset($items['LocationExternalCode']);
            unset($items['AreaExternalCode']);
            
        // Encode the modified array back to JSON
        $itemsPayload = json_encode($items);

        $connection = new Connection();
        $responseValidate = $connection->postData($siteId, $this->url, $this->tokenType , $itemsPayload);
        if($responseValidate->Ok){
            $this->northJson = $northJson;
            return $responseValidate;
        }else{
            if($responseValidate->ErrorMessage!=""){
                $errMsg="API response 4: ".$responseValidate->ErrorMessage;
                wc_print_notice(__(self::ERROR_MESSAGE), 'error');
                CBSLogger::orders()->error($errMsg);
                return false;
              }
        }
    }

    //validate location
    public function validateLocation($table , $area)
    {
        $locationJson = (new LocationDto($table,$area))->toJson();
        $this->url = '/checks/location';
        $response = (new Connection())->especialPostData($this->siteId, $this->url , $this->tokenType , $locationJson);
        if($response->ErrorMessage === "invalid_location"){
            $errMsg="API response 7: Add a valid location";
            CBSLogger::orders()->error($errMsg);
            wc_add_notice(__(self::ERROR_MESSAGE), 'error');
            return "invalid_location";
        }elseif($response->ErrorMessage === "invalid_check"){
            $this->locationJson = $locationJson;
            $this->area = $area ;
            $this->table = $table;
            return "invalid_check";
        }else{
            $this->checkid = $response->Data->CheckId;
            $this->area = $area ;
            $this->table = $table;
            $this->locationJson = $locationJson;
            return $response;
        }
    }

    public function createCheckLocation(){
        $this->url = '/checks/';
        $requestConnection = new Connection();
        $response = $requestConnection->postData($this->siteId , $this->url , $this->tokenType ,  $this->locationJson );
        
        if($response->ErrorMessage!=""){
            return false;
        }else{
            $this->checkid = $response->Data->CheckId;
            $_COOKIE["checkid"] = $response->Data->CheckId;
            $this->checknumber = $response->Data->CheckNumber;
            return $this->checkid;
        }
    }
    public function getCheckNumber(){
      return $this->checknumber;
    }
    public function submitItems($checkid , $cart){
        $this->url = '/checks/'.$checkid.'/additemsandsubmit';

        $connectionAddItems = new Connection();
        $response = $connectionAddItems->postData($this->siteId  , $this->url ,  $this->tokenType,   $this->northJson );
        $this->checkid=$_COOKIE["checkid"];
        if($response->ErrorMessage!=""){
          $errMsg="API response 9: ".$response->ErrorMessage;
          CBSLogger::orders()->error($errMsg);
          wc_print_notice(__(self::ERROR_MESSAGE), 'error');
          return false;
        }else{
            global $wpdb;
            $tableName = 'wp_woocommerce_cart_record';

            $result = CartRecordRepository::create()->getCartData($this->table,$this->area);
            $cartrecord = serialize($cart->get_cart());
    
            if(!$result){
              $wpdb->insert($tableName,
                  array('location_number' => $this->table,
                        'area_id' => $this->area,
                        'cart_data' => serialize($cart->get_cart()),
                        'check_id'=>$this->checkid
                  )
                );
            }else{
              $wpdb->update($tableName,
                  array(
                      'cart_data' => serialize($cart->get_cart())
                  ),
                  array(
                      'location_number' => $this->table,
                      'area_id' => $this->area
                  )
              );
            }
          return $response;
          }
    }

    /**
     * @throws Exception
     */
    public function addExistingCart($orderResponse){

        CBSLogger::cart()->debug('Add existing cart', [
            'orderResponse'  => $orderResponse,
            'orderItemCount' => count($orderResponse->Data->OrderItems),
        ]);
        $order = $orderResponse->Data->OrderItems;
        $result = CartRecordRepository::create()->getCartData($this->table,$this->area);
        $wpOrder = unserialize($result[0]->cart_data);

        $counter = 0 ;
        //Compare data between existing on WordPress is the same amount of items
        $totalItems = 0;
        foreach ($wpOrder as $orderValue) {
            $totalItems += $orderValue['quantity'];
        }
        CBSLogger::cart()->debug('Cart vs order item count comparison', [
            'totalCartItems'  => $totalItems,
            'totalOrderItems' => count($order),
            'order'           => $order,
            'wpOrder'         => $wpOrder,
        ]);


        if(count($order) === $totalItems){
            CBSLogger::cart()->debug('Order and cart item counts match', ['totalItems' => $totalItems]);

            foreach ($wpOrder as  $orderValue) {

                CBSLogger::cart()->debug('Processing cart item', [
                    'counter'  => $counter,
                    'item'     => $orderValue,
                    'quantity' => $orderValue['quantity'],
                ]);
                $counter ++;

                if($orderValue['total_price']){
                    WC()->session->set('total_price',$orderValue['total_price']);
                }
                $servingoptionsel = $orderValue['product_serving_options'];
                $servingopitionsHtml = $orderValue['product_serving_options_html'];
                WC()->session->set( 'custom_product_serving_options', $orderValue['product_serving_options'] );
                WC()->session->set( 'custom_product_serving_html', $orderValue['product_serving_options_html'] );
                WC()->session->set( 'custom_product_component', $orderValue['product_component'] );
                WC()->session->set( 'custom_product_component_id', $orderValue['product_component_id'] );
                
                $cartItemKey = WC()->cart->add_to_cart(
                    $orderValue['product_id'],
                    $orderValue['quantity'],
                    $orderValue['variation_id']);


                setcookie($cartItemKey,$cartItemKey,  time()+86400, '/', null, is_ssl() , true );
                $cartItemkeyArr[$cartItemKey]=$cartItemKey;
            }
            return $cartItemkeyArr;
        }
    }

    public function syncCartProduct($menuItem){
        $menuItemId = $menuItem->MenuItemId;
        $componentsKey = "_components"; 
        $servingOptionsKey = "_servingoptions";

        $productId = (new ProductManager)->checkProductExistsByItemId($menuItemId);

        if($productId){
            $components = get_post_meta($productId, $componentsKey, true);
            $servingOptions = get_post_meta($productId, $servingOptionsKey, true);

        }



    }

}

