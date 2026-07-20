<?php

namespace CBSNorthStar\Dto;

class CartOrderDto extends OrderDto
{

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->orderItems = $this->order->get_cart();
    }

    public function toArray(): array
    {
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
            "areaId" => $this->areaId,
            'CouponCodes' => $this->coupons
        );

        $order['orderItems'] = array_reduce($this->orderItems, function ($carry, $item){
            $product = $item['data'];
            $productId = $item['product_id'];
            $menuid = $this->resolveMenuId($productId);
            $menuItemId = get_post_meta($productId, '_itemid', true);
            $quantity = $item['quantity'];
            $price = $product->get_price();
            
            for ($i = 1; $i <= $quantity ; $i++) {
                $orderItem = array(
                    "menuId" => $menuid,
                    "menuItemId" => $menuItemId,
                    "price" => (float) $price,
                    "quantity" => 1,
                    "Memos" => empty($item['item_selected_for']) ? array() : array($item['item_selected_for']),
                  );
      
                  if(isset($item['product_component_id']) && !empty($item['product_component_id'])){
                      $orderItem['components'] = $this->getComponents($item['product_component_id']);
                  }
      
                $servingOptions = $item['product_serving_options'] ?? null;
                  $orderItem['servingOptions'] = $this->getServingOptions($servingOptions);
                  $carry[] = $orderItem;

            }
            
            return $carry;
        }, []);

        return $order;
    }
}
