<?php

namespace CBSNorthStar\Models;

use CBSNorthStar\Repositories\ConfigurationRepository;
use CBSNorthStar\Models\ServingOption;
use CBSNorthStar\Woapi\Connection;
use CBSNorthStar\Helpers\SiteRulesCache;

class Component
{

  protected string $description;
  protected int $id;
  protected string $name;
  protected $image;
  protected $components;
  protected $price;

  public function load($productId): array
  {
      $response=array();

      $product = wc_get_product($productId);

      $this->setInformation($product);


      $this->components = $this->getComponentInfo($productId);

      $response['id'] = $this->id;
      $response['name'] = $this->name;
      $response['description'] = $this->description;
      $response['image'] = $this->image;
      $response['price'] = $this->price;
      $response['components'] = $this->components;


      return $response;
  }

  /**
   * @param $productId
   * @return mixed
   */
  public function getProductComponents($productId)
  {
    $productComponents = get_post_meta($productId, '_components', true);
    return json_decode($productComponents);
  }

  /**
   * @param $product
   * @return void
   */
  protected function setInformation($product): void
  {
      $this->description = $product->get_description();
      $this->id = $product->get_id();
      $this->name = $product->get_name();
      $this->price = $product->get_price();
      $imageId = get_post_meta($product->get_id(), '_thumbnail_id', true);
      $postThumbnailImg = wp_get_attachment_image_src($imageId, 'large');
      $this->image = $postThumbnailImg[0];
  }

  /**
   * @param $product
   * @return array
   */
  public function getVariations($product): array
  {
      $variationsData = []; // Initializing

      if ($product->is_type('variable')) {
          /**
           * @var \WC_Product_Variable $product
           */


      $attributes = $product->get_attributes();
      // Loop through variations data
      $displayOrders = [];
      // Loop through each attribute

      foreach ($attributes as $attribute) {
        // Get the term_id from the options field of the attribute
        $term_ids = $attribute->get_options();
        // Loop through each term_id
        foreach ($term_ids as $term_id) {
          // Get the order using get_term_meta() only if it's not already fetched
          if (!isset($displayOrders[$term_id])) {
            $displayOrders[$term_id] = get_term_meta($term_id, 'order', true);
          }
        }
      }

          foreach ($product->get_available_variations() as $variation) {

        $term_id = array_values($variation['attributes'])[0];
        $attributeId = array_keys($variation['attributes'])[0];

        $taxonomy = str_replace('attribute_', '', $attributeId);
        $term = get_term_by('slug', $term_id, $taxonomy);

        $displayOrder = $displayOrders[$term->term_id] ?? '';
              //Set for each variation ID the corresponding price in the data array (to be used in jQuery)
              $variationsData[$variation['variation_id']] = [
                "dispaly_price" => $variation['display_price'],
                "attributes" => $variation['attributes'],
          "display_order" => $displayOrder,
              ];
      }
    }
      return $variationsData;
  }

  /**
   * @param $attributeList
   * @return array
   */
  public function getAttributeList($attributeList): array
  {
      $attributes = array_map(function ($variation) {
          return $variation['attributes'];
      }, $attributeList);

      $attList = [];
      foreach ($attributes as $value) {

      foreach ($value as $key2 => $value2) {
        if (!array_key_exists($key2, $attList) && $key2 !== 'display_order') {
                  $attList[$key2][] = $value2;
              } else {
          if ($key2 !== 'display_order' && !in_array($value2, $attList[$key2])) {
                    $attList[$key2][] = $value2;
                }
              }
          }
      }

      return $attList;
  }

  protected function getComponentInfo($productId): array
  {
      $componentInfo = [];
      $productComponents = $this->getProductComponents($productId);
      $numberOfPlacements = get_post_meta( $productId, '_numberofplacement', true);
      $apiCallCount = 1;
      
      foreach ($productComponents as $categoryId => $components) {

        if (!empty($components)){
          $category = $this->getCategoryName($components);

            foreach ($components as $component) {
              if (!empty($component->outofstock)) {
                  continue;
              }

              $this->setDefaultValues($component, $numberOfPlacements);
              $componentInfo[$category]['items'][$component->componentName] = $this->createComponentInfoArray($component, $categoryId);
              $componentInfo[$category]['info']['catName'] = $category;
              $componentInfo[$category]['info']['componentCatId'] = $categoryId;
              // Union (+), not assignment: components in one category can carry
              // different ruleIds, and setRules() returns a single-entry
              // [ruleId => data] map. The old per-component reassignment (of the
              // whole info array AND of info.rules) left only the LAST
              // component's rule in the category map — quick-add then priced
              // free defaults with the wrong rule, and drawComponents'
              // info.rules[ruleId] lookup was undefined for the clobbered ids.
              $componentInfo[$category]['info']['rules'] =
                ($componentInfo[$category]['info']['rules'] ?? []) + $this->setRules($component, $apiCallCount);
              $componentInfo[$category]['items'][$component->componentName]['key'] = $component->key;
              $apiCallCount++;
          }
        }
      }

      return $componentInfo;
  }

  private function getCategoryName($components): string
  {
      return array_unique(array_map(fn ($component) => $component->categoryName, $components))[0];
  }

  private function setDefaultValues($component, $numberOfPlacements): void
  {
      $component->componentprice = $component->componentprice == "" ? 0 : $component->componentprice;
      $component->pricingLevels = $component->pricingLevels ?? [];
      $component->isDefault = $component->isDefault == "" ? false : $component->isDefault;
      $component->ruleId = $component->ruleId == "" ? 'Default' : $component->ruleId;
      $component->siteId = $component->siteId == "" ? 0 : $component->siteId;
      $component->numberOfPlacements = $component->numberOfPlacements === 0 ? $numberOfPlacements : $component->numberOfPlacements;
      $component->image = $component->image == "" ? wc_placeholder_img_src() : $component->image;
  }

  private function createComponentInfoArray($component, $categoryId): array
  {
      return [
        "componentName"       => $component->componentName,
        "componentPrice"      => $component->componentprice,
        "pricingLevels"       => $component->pricingLevels ?? [],
        "isDefault"           => $component->isDefault,
        "ruleId"              => $component->ruleId,
        "siteId"              => $component->siteId,
        "NumberOfPlacements"  => $component->numberOfPlacements,
        "componentCatId"      => $categoryId,
        "componentId"         => $component->componentId,
        "image"               => $component->image,
        "componentServingOptions" => $this->getServingOptions($component->componentServingOptions, $component->componentId),
      ];
  }

  /**
   * Fetch all components rule
   *
   * @param string      $siteId              site id.
   * @param string      $apicallCount        keep record of api calls.
   * @param object|null $prefetchedResponse  Optional already-fetched /rules/
   *                                         response (from the deploy's parallel
   *                                         prefetch batch) — skips the API call.
   * @return array
   */
  public function getComponentsRule($siteId, $apicallCount, $prefetchedResponse = null)
  {
      global $wpdb;
      if ($apicallCount == 0) {

        $configuration = ConfigurationRepository::create()->getDetails();
        $token = $configuration->token;
        $siteInstance = $configuration->instance;

        $instance = ConfigurationRepository::create()->getInstance($siteInstance);
        $instanceOeapiUrl = $instance->instance_oeapiurl;


        $url = '/rules/';
        $response = $prefetchedResponse ?? (new Connection())->getData($siteId, $url, 'Token');


        $exists = $wpdb->get_var($wpdb->prepare(
          "SELECT COUNT(*) FROM cbs_save_api_response WHERE siteid = %s",
          $siteId
        ));

        $responseJson = wp_json_encode($response);

        if ($responseJson === false) {
          error_log('Failed to encode rules for site ID: ' . $siteId);
          return $response;
        }
        $result = false;

        if ($exists) {
            $result = $wpdb->update(
                'cbs_save_api_response',
                ['sites_rules' => $responseJson],
                ['siteid' => $siteId],
                ['%s'],
                ['%s']
            );
        } else {
            $result = $wpdb->insert(
                'cbs_save_api_response',
                ['siteid' => $siteId, 'sites_rules' => $responseJson],
                ['%s', '%s']
            );
        }
        if ($result === false) {
            error_log('Failed to insert rules for site ID: ' . $siteId . ' - ' . $wpdb->last_error);
        }
        return $response;
      }
      else {

      // Request-scoped memo (OE-26548 N+1): this branch runs once per component
      // per product, but siteId is invariant for the whole request — memoise the
      // DB read instead of re-querying for every component. Deploy path above
      // (apicallCount == 0) is untouched and always reads/writes fresh.
      // (Merge note: supersedes this branch's plain $wpdb->prepare() fix — the
      // memoized read is equally injection-safe and adds the caching.)
      $sitesRulesJson = SiteRulesCache::rememberSitesRulesJson((string) $siteId, function () use ($wpdb, $siteId) {
        return $wpdb->get_var($wpdb->prepare(
          "SELECT sites_rules FROM cbs_save_api_response WHERE siteid = %s",
          $siteId
        ));
      });

      if ($sitesRulesJson) {
        return json_decode($sitesRulesJson);
      }
    }
  }

  protected function setRules(&$component, int $apiCallCount): array
  {
    $maxAllowed = 1000;
    $minRequired = 0;
    $freeAfter = '';
    $freeUpTo = 0;
    $maxUnique = 0;
    $componentRule = [];
    $componentRules = $this->getComponentsRule($component->siteId, $apiCallCount);

    $componentRules = (object) $componentRules;
    $rules = $componentRules->Data;
    foreach ($rules as $key => $rule) {
      $component->key = $key;
      if ($rule->RuleId == $component->ruleId) {

        $componentRule[$component->ruleId] = [
          "MaxAllowed"  => $rule->MaxAllowed ,
          "MinRequired" => $rule->MinRequired,
          "FreeAfter"   => $rule->FreeAfter,
          "FreeUpTo"    => $rule->FreeUpTo,
          "MaxUnique"   => $rule->MaxUnique,
          "DefaultComponentsAreFree" => $rule->DefaultComponentsAreFree,
          "FirstDefaultComponentsLevelsFree" => $rule->FirstDefaultComponentsLevelsFree,
        ];
      }
      elseif($component->ruleId == 'Default')
      {
        // Static false flags, NOT $rule->...: this branch runs on every rule in
        // the site list (the loop deliberately never breaks — $component->key
        // must keep ending as the LAST list key, which processProductComponents
        // uses as the cart category name), so reading the current $rule here
        // made a no-rule component inherit free-flags from whichever rule
        // happened to be last. A component with no rule has no free entitlements.
        $componentRule[$component->ruleId] = [
          "MaxAllowed"  => $maxAllowed ,
          "MinRequired" => $minRequired,
          "FreeAfter"   => $freeAfter,
          "FreeUpTo"    => $freeUpTo,
          "MaxUnique"   => $maxUnique,
          "DefaultComponentsAreFree" => false,
          "FirstDefaultComponentsLevelsFree" => false,
        ];
      }

    }

    return $componentRule;
  }
  // need create structure for serving options
  public function getServingOptions($componentServingOptions, $componentId): array
  {
      $productComponents = $componentServingOptions;
      $servingOptionComponents = [];
      $apiCallCount = 1;

      foreach ($productComponents as $categoryId => $components) {
          if (!empty($components)) {
              $categoryName = $this->getCategoryName($components);
              $servingOptionCategoryId = $categoryId;

              $servingOptionComponents[$categoryName]['items'] = $this->buildServingOptionItems($components, $servingOptionCategoryId);
              $servingOptionComponents[$categoryName]["info"] = [
                  "categoryName" => $categoryName,
                  "categoryId" => $servingOptionCategoryId,
                  "componentId" => $componentId,
              ];
              // Use the first option for rules if available
              $servingOption = new ServingOption();
              $firstOption = reset($components);
              $result = $servingOption->setRules($firstOption, $apiCallCount);
              $servingOptionComponents[$categoryName]['info']['rules'] = $firstOption ? $result : [];
              $apiCallCount++;
          }
      }
      return $servingOptionComponents;
  }

  /**
   * Build serving option items array.
   *
   * @param array $components
   * @param int|string $servingOptionCategoryId
   * @return array
   */
  private function buildServingOptionItems(array $components, $servingOptionCategoryId): array
  {
      $items = [];
      foreach ($components as $option) {
          $option->siteId = isset($option->siteId) ? $option->siteId : 0;
          $option->displayOrder = isset($option->displayOrder) ? $option->displayOrder : 0;
          $items[$option->servingOptionName] = $this->buildServingOptionItem($option, $servingOptionCategoryId);
      }
      return $items;
  }

  /**
   * Build a single serving option item array.
   *
   * @param object $option
   * @param int|string $servingOptionCategoryId
   * @return array
   */
  private function buildServingOptionItem($option, $servingOptionCategoryId): array
  {
      return [
          "servingOptionName" => $option->servingOptionName,
          "servingOptionPrice" => $option->servingOptionPrice,
          "isDefault" => isset($option->isDefault) ? (bool)$option->isDefault : false,
          "siteId" => isset($option->siteId) ? (int)$option->siteId : 0,
          "categoryId" => $servingOptionCategoryId,
          "servingOptionId" => $option->servingOptionId,
          "displayOrder" => isset($option->displayOrder) ? (int)$option->displayOrder : 0,
      ];
  }
}
