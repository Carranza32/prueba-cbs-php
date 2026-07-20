<?php

namespace CBSNorthStar\Dto;

use WC_Order_Item_Product;
use CBSNorthStar\Logger\CBSLogger;
use CBSNorthStar\Models\Component;
use CBSNorthStar\Helpers\ComponentFreeRules;

class OrderDto extends BaseDto
{
  protected $order;
  protected $orderType;
  protected $deliveryDate;
  protected $orderItems;
  protected $areaId;
  protected $locationName;
  protected $guestName;
  protected $coupons;
  protected $gratuitiesDto;
  protected $customerId;

  public function __construct(array $data, ?GratuitiesDto $gratuitiesDto = null)
  {
    $this->order = $data['order'];

    $this->orderType = $data['orderType'];
    $this->deliveryDate = $data['deliveryDate'];
    $this->areaId = $data['areaId'];
    $this->customerId = $data['customerId'] ?? null;
    $this->coupons = $data['coupons'] ?? [];
    $this->gratuitiesDto = $gratuitiesDto;
  }

  public function toArray(): array
  {
      $this->orderItems = $this->order->get_items();
      $this->locationName = $this->order->get_meta('location-name');
      $this->guestName = $this->locationName ? $this->locationName . " - ". $this->order->get_billing_first_name() : $this->order->get_billing_first_name();

      $orderMemo = $this->order->get_customer_note() ? [$this->order->get_customer_note()] : [];
      $order = [
        'guestName' =>  $this->guestName ,
        'guestPhoneNumber' => $this->order->get_billing_phone(),
        'orderType' => $this->orderType,
        'pickupTime' => $this->deliveryDate,
        'subTotal' => (float) $this->order->get_subtotal(),
        'guestCount'=> 1,
        'arrived'=> false,
        'areaId' => $this->areaId,
        'customerId' => $this->customerId,
        'CouponCodes' => $this->coupons,
        'Memos' => $orderMemo,
      ];

    $order['orderItems'] = [];

foreach ($this->orderItems as $itemId => $item) {
    $productId = $item->get_product_id();
    $menuid = $this->resolveMenuId($productId);
    $menuItemId = get_post_meta($productId, '_itemid', true);
    $componentsData =  json_decode(get_post_meta($productId, '_components', true), true);

        $menuItemCategoryId = $this->getMenuItemCategoryId($productId);
        $quantity = $item->get_quantity();
        $itemSelectedFor = $item->get_meta('Item selected for', true);
        $itemMemos = $itemSelectedFor ? [$itemSelectedFor] : [];

        for ($i = 1; $i <= $quantity; $i++) {
            $orderItem = [
                'menuId' => $menuid,
                'menuItemId' => $menuItemId,
                'price' => ($item->get_total()) / $quantity,
                'Memos' => $itemMemos,
                'quantity' => 1, // Set quantity to 1 for each split item
                'menuItemCategoryId' => $menuItemCategoryId,
            ];

            $componentIds = $item->get_meta('Product Component Id');//rename to componentIds
            $servingOptions = $item->get_meta('servingOptions');

            if (isset($componentIds) && !empty($componentIds)) {
              $orderItem['components'] = $this->getComponents($componentIds , $componentsData);
              if (empty($orderItem['components'])) {
                CBSLogger::orders()->debug('[CBS_COMPONENTS] OrderDto: getComponents returned empty', ['orderId' => $this->order->get_id(), 'menuItemId' => $menuItemId, 'componentIdsType' => gettype($componentIds), 'componentIds' => $componentIds]);
              }
            } elseif (!empty($componentsData)) {
              CBSLogger::orders()->debug('[CBS_COMPONENTS] OrderDto: No component meta found (product has components defined)', ['orderId' => $this->order->get_id(), 'menuItemId' => $menuItemId, 'componentIdsType' => gettype($componentIds), 'componentIds' => $componentIds]);
            }
            $orderItem['servingOptions'] = $this->getServingOptions($servingOptions , $productId);

            $order['orderItems'][] = $orderItem;
        }
    }

    if ($this->gratuitiesDto !== null) {
        $order = array_merge($order, $this->gratuitiesDto->toArray());
    }

    return $order;

  }

  /**
   * @param $idComponents
   * @return array flat array of components
   */
  public function getComponents($idComponents , ?array $componentsData = []): array
  {
    $allComponents= [];

    if(empty($idComponents)) {
        return $allComponents;
    }
    if(!is_array($idComponents) && !empty($idComponents)) {
        $decoded = json_decode($idComponents, true);
        if(!is_array($decoded)) {
            CBSLogger::orders()->error('[CBS_COMPONENTS] getComponents: json_decode failed', ['inputType' => gettype($idComponents), 'inputValue' => substr(print_r($idComponents, true), 0, 500), 'jsonError' => json_last_error_msg()]);
            return $allComponents;
        }
        $idComponents = $decoded;
    }

    $freeInstanceKeys = array_flip($this->getFreeComponentInstanceKeys($idComponents, $componentsData));

    foreach($idComponents as $componentId => $componentInfo) {
      $flag = 1;
      $quantity = $componentInfo['quantity'] ?? 1;
      $servingOptions = $componentInfo['servingOptionIds'] ?? [];

     while ($flag <= $quantity) {
        $component = [];
        $leftFlag = strpos($componentId, "left");
        $rightFlag = strpos($componentId, "right");

        if ($leftFlag) {
          $addonLoc = "Left";
          $componentId = str_replace("_left", "", $componentId);
        } elseif ($rightFlag) {
          $addonLoc = "Right";
          $componentId = str_replace("_right", "", $componentId);
        } else {
          $addonLoc = "All";
        }

        $component["componentId"] = $componentId;
        if($componentsData){
          $component["servingOptions"] = $this->getComponentServingOptions($componentsData, $componentId, $servingOptions);
          $component["componentName"] = $this->getComponentName($componentsData, $componentId);
          if (!isset($freeInstanceKeys["{$componentId}:{$flag}"])) {
            $component["price"] = $this->getComponentPrice($componentsData, $componentId, $flag);
          }
        }

        $component["placementLocation"] = $addonLoc;

        $allComponents[] = $component;
        $flag++;
      }
    }

    return $allComponents;
  }

  /**
   * Determines which "componentId:instanceNumber" pairs are free per the site's pricing
   * rules (FreeUpTo, DefaultComponentsAreFree, FirstDefaultComponentsLevelsFree, FreeAfter),
   * mirroring src/product-detail/view.js's isInstanceFree(). Read-only against the already
   * cached rules table — never calls out to ECM — and fails safe (no free instances) on any
   * unexpected shape so order submission is never blocked by this.
   *
   * @return string[]
   */
  protected function getFreeComponentInstanceKeys($idComponents, ?array $componentsData): array
  {
    if (empty($componentsData) || empty($idComponents)) {
        return [];
    }

    try {
        [$flatComponents, $siteId] = $this->flattenComponentsData($componentsData);
        if (empty($siteId)) {
            return [];
        }

        $orderedComponents = $this->buildOrderedComponentList($idComponents, $flatComponents);
        if (empty($orderedComponents)) {
            return [];
        }

        $rulesByRuleId = $this->getRulesByRuleId($siteId);
        if (empty($rulesByRuleId)) {
            return [];
        }

        return ComponentFreeRules::computeFreeInstanceKeys($orderedComponents, $rulesByRuleId);
    } catch (\Throwable $e) {
        CBSLogger::orders()->error('[CBS_COMPONENTS] getFreeComponentInstanceKeys failed, defaulting to no free instances', ['message' => $e->getMessage()]);
        return [];
    }
  }

  /**
   * @return array{0: array<string,array{categoryId:mixed,ruleId:string,isDefault:bool}>, 1: ?string}
   */
  protected function flattenComponentsData(array $componentsData): array
  {
    $flat = [];
    $siteId = null;

    foreach ($componentsData as $categoryId => $components) {
        if (empty($components)) {
            continue;
        }
        foreach ($components as $item) {
            $componentId = $item['componentId'] ?? null;
            if ($componentId === null) {
                continue;
            }
            $isDefaultRaw = $item['isDefault'] ?? false;
            $flat[$componentId] = [
                'categoryId' => $categoryId,
                'ruleId'     => (string) ($item['ruleId'] ?? 'Default'),
                'isDefault'  => $isDefaultRaw === true || $isDefaultRaw === 1 || $isDefaultRaw === '1',
            ];
            if (empty($siteId) && !empty($item['siteId'])) {
                $siteId = $item['siteId'];
            }
        }
    }

    return [$flat, $siteId];
  }

  protected function buildOrderedComponentList($idComponents, array $flatComponents): array
  {
    $ordered = [];
    foreach ($idComponents as $componentId => $componentInfo) {
        $componentId = str_replace(['_left', '_right'], '', $componentId);
        if (!isset($flatComponents[$componentId])) {
            continue;
        }
        $ordered[] = [
            'componentId' => $componentId,
            'categoryId'  => $flatComponents[$componentId]['categoryId'],
            'ruleId'      => $flatComponents[$componentId]['ruleId'],
            'isDefault'   => $flatComponents[$componentId]['isDefault'],
            'quantity'    => $componentInfo['quantity'] ?? 1,
        ];
    }
    return $ordered;
  }

  protected function getRulesByRuleId(string $siteId): array
  {
    $rulesResponse = (new Component())->getComponentsRule($siteId, 1);
    if (empty($rulesResponse)) {
        return [];
    }

    $rulesResponse = (object) $rulesResponse;
    $data = $rulesResponse->Data ?? null;
    if (empty($data)) {
        return [];
    }

    $rulesByRuleId = [];
    foreach ($data as $rule) {
        $rule = (object) $rule;
        $ruleId = $rule->RuleId ?? null;
        if ($ruleId === null) {
            continue;
        }
        $rulesByRuleId[(string) $ruleId] = [
            'FreeUpTo'                         => $rule->FreeUpTo ?? 0,
            'DefaultComponentsAreFree'         => $rule->DefaultComponentsAreFree ?? false,
            'FirstDefaultComponentsLevelsFree' => $rule->FirstDefaultComponentsLevelsFree ?? 0,
            'FreeAfter'                        => $rule->FreeAfter ?? '',
        ];
    }

    return $rulesByRuleId;
  }


  protected function getServingOptions($servingOptions , $productId = null ): array
  {
    if (empty($servingOptions)) {
        return [];
    }
    
    $servingOptionPrices = json_decode(get_post_meta($productId, '_servingoptions', true));


    $servingOptions = array_map(function ($option) use ($servingOptionPrices) {
        return [
            'servingOptionId' => $option['optionId'],
            'price' => $this->getServingOptionsPrice($option['optionId'], $servingOptionPrices)
        ];
    }, $servingOptions);

    return $servingOptions;
}

  protected function getServingOptionsPrice($servingOptionId, $servingOptionPrices){
    //check if empty
    if(empty($servingOptionPrices)) {
        return null;
    }
    $servingOptionSelected = null;

    foreach ($servingOptionPrices as $category) {
      $servingOptionSelected = current(array_filter($category, function($servingOption) use ($servingOptionId) {
          return property_exists($servingOption, 'servingOptionId') && $servingOption->servingOptionId === $servingOptionId;
      }));
    
    // Break early if the option is found
      if ($servingOptionSelected !== false) {
          break;
      }
    }

    return  $servingOptionSelected ? $servingOptionSelected->servingOptionPrice : null;
}

  /* Function added on Master fr components prices delete after merge if no used */
  protected function getComponentPrice(array $data, string $componentId, int $quantity): ?string {
    if(empty($data)) {
        return null;
    }
    foreach ($data as $components) {
        foreach ($components as $item) {
            if (isset($item['componentId']) && $item['componentId'] === $componentId) {
              $pricingLevels = $item['pricingLevels'] ?? [];

              if (!empty($pricingLevels)) {
                if (isset($pricingLevels[$quantity]['price'])) {
                  return $pricingLevels[$quantity]['price'];
                }

                // Quantity is past the highest configured level: clamp to that level's
                // price instead of falling back to the flat price (mirrors OE-26649's
                // getComponentPriceByLayer() clamp). A gap at or below the highest level
                // still falls through to the flat-price fallback below.
                $definedLevels = array_filter(array_keys($pricingLevels), 'is_int');
                if (!empty($definedLevels)) {
                  $maxLevel = max($definedLevels);
                  if ($quantity > $maxLevel && isset($pricingLevels[$maxLevel]['price'])) {
                    return $pricingLevels[$maxLevel]['price'];
                  }
                }
              }

              $componentPrice = $item['componentprice'] ?? '';
              return $componentPrice !== '' ? $componentPrice : null;
            }
        }
    }
    return null;
}

  protected function getComponentName(array $data, string $componentId): ?string {
    if(empty($data)) {
        return null;
    }
    foreach ($data as $components) {
        foreach ($components as $item) {
            if (isset($item['componentId']) && $item['componentId'] === $componentId) {
              return $item['componentName'] ?? null;
            }
        }
    }
    return null;
}

  protected function getComponentServingOptions(array $data,  string $componentId, array $servingOptionIds ): array {
    if(empty($data) || empty($servingOptionIds)) {
        return [];
    }
    $servingOptions = [];
    $targetIds = array_flip($servingOptionIds);

    foreach ($data as $components) {
      foreach ($components as $item) {
        if (isset($item['componentId']) && $item['componentId'] !== $componentId){
          continue;
        }
        $servingOptionCategory = $item['componentServingOptions'];
        foreach ($servingOptionCategory as $category) {
          foreach ($category as $option) {
            $optionId = $option['servingOptionId'] ?? null;
            if ($optionId && isset($targetIds[$optionId])) {
              $servingOptions[] = [
                  'servingOptionId' => $option['servingOptionId'],
                  'price' => $option['servingOptionPrice'] ?? null
              ];
            }
          }
        }
        return $servingOptions;
      }
    }
    return $servingOptions;
  }

  /**
   * Retrieves the MenuItemCategoryId from the product's category term meta.
   * Falls back to parent term if no term has the meta directly.
   *
   * @param int $productId The WooCommerce product ID.
   * @return string|null The MenuItemCategoryId or null if not found.
   */
  protected function getMenuItemCategoryId(int $productId): ?string
  {
    $terms = wp_get_post_terms($productId, 'product_cat');
    
    if (is_wp_error($terms) || empty($terms)) {
      return null;
    }

    // First pass: search meta directly in terms
    foreach ($terms as $term) {
      $categoryId = get_term_meta($term->term_id, 'menu_item_category_id', true);
      
      if (!empty($categoryId)) {
        return (string) $categoryId;
      }
    }

    // Second pass: search in parents only if no term has the meta
    foreach ($terms as $term) {
      if (!empty($term->parent)) {
        $parentCategoryId = get_term_meta($term->parent, 'menu_item_category_id', true);
        if (!empty($parentCategoryId)) {
          return (string) $parentCategoryId;
        }
      }
    }

    return null;
  }

  /**
   * Returns the menuId for a product that matches the currently active menu
   * (currentMenu cookie). A product can have multiple _menuid post-meta rows —
   * one per daypart menu it belongs to — because SaveProduct uses add_post_meta
   * with unique=false. get_post_meta(..., true) always returns the first row
   * (whichever menu synced last), which is wrong when the active menu differs.
   * This method gets all stored values and picks the one matching the cookie,
   * falling back to the first stored value if none match.
   */
  protected function resolveMenuId(int $productId): string
  {
    $menuIds     = get_post_meta($productId, '_menuid', false); // all rows
    $currentMenu = sanitize_text_field($_COOKIE['currentMenu'] ?? '');

    if (!empty($currentMenu) && in_array($currentMenu, (array) $menuIds, true)) {
      return $currentMenu;
    }

    return (string) ($menuIds[0] ?? '');
  }
}
