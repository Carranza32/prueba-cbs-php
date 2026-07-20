<?php

namespace CBSNorthStar\Dto;

class CartOrderReviewDto extends OrderDto
{
  protected $orderItems;
  protected $tableNumber;
  protected $areaExternalCode;
  protected $customerId;

  public function __construct(array $data)
  {
      parent::__construct($data);
      $this->orderItems = $this->order->get_cart();
      $this->tableNumber = $data['tableNumber'];
      $this->areaExternalCode = $data['areaExternalCode'];
      $this->customerId = $data['customerId']?? null;
  }

  public function toArray(): array
  {
    $cartItems = [];
    $order = array(
      "guestName" => $this->order->get_customer()->get_billing_first_name(),
      "guestPhoneNumber" => $this->order->get_customer()->get_billing_phone(),
      "orderType" => $this->orderType,
      "pickupTime" => $this->deliveryDate,
      "subTotal" => $this->order->subtotal,
      "taxTotal" => 0,
      "taxExempt" => 0,
      "tipTotal" => 0,
      "guestCount" => 1,
      "total" => $this->order->total,
      "arrived" => true,
      "customerId" => $this->customerId,
    );


    $order['orderItems'] = array();
    $items = array_filter(array_map(function ($itemKey, $item) use ($cartItems) {

      if (!array_key_exists($itemKey,$cartItems)){
        $cartItems[$itemKey]=$itemKey;
        setcookie('cartitemkey_arr_init', json_encode($cartItems),time()+86400, '/', '', is_ssl()  , true );
      }

      if (!isset($_COOKIE[$itemKey])) {

        $product = $item['data'];
        $productId = $item['product_id'];
        $menuId = $this->resolveMenuId($productId);
        $menuItemId = get_post_meta($productId, '_itemid', true);
        $price = $product->get_price();
        $menuCategoryId = $this->getMenuItemCategoryId($productId);

        // Duplicate the item based on the quantity
        $orderItems = array();

        for ($i = 1; $i <= $item['quantity']; $i++) {
          $orderItem = array(
              "menuId" => $menuId,
              "menuItemId" => $menuItemId,
              "menuItemCategoryId" => $menuCategoryId,
              "price" => $price,
              "quantity" => 1, // Set the quantity to 1 for each duplicated item
              "Memos" => ($item['item_selected_for'] == "") ? array() : array($item['item_selected_for']),
          );
          
          $idComponents = $item['product_component_id'] ?? null;
      
          if (!empty($idComponents)) {
              $orderItem['components'] = $this->getComponents($idComponents);
          }
      
          $variationId = $item['variation_id'];
          $orderItem['servingOptions'] = $this->getServingOptions($variationId);
      
          $orderItems[] = $orderItem; // Add the duplicated item to the array
      }

        if(!empty($orderItem)){
          return $orderItems;
        }
      }

      $_COOKIE[$itemKey] = $itemKey;
    }, array_keys($this->orderItems), $this->orderItems));

    $items = array_values($items);

    // KNOWN LIMITATION (OE-26669): $items is one array of order items PER CART
    // LINE, so taking [0] sends only the FIRST cart line to the API — a
    // multi-line cart is never fully represented by this payload. Do not rely
    // on this DTO for full-cart validation: the authoritative validate call
    // (all lines, coupons, timeslot overrides) is cbs_custom_tax_surcharge()'s
    // CartOrderDto payload. This DTO's review-order call is snapshot-gated in
    // standard mode and kept only for the pay-later table/area check.
    $order['orderItems'] = $items[0];

    if (isset($_COOKIE['pay_later_control']) && !empty($this->tableNumber) && !empty($this->areaExternalCode)) {
      $order["LocationExternalCode"] = $this->tableNumber;
      $order["AreaExternalCode"] = $this->areaExternalCode;
    }


    return $order;
  }
}