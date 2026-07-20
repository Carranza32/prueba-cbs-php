<?php

namespace CBSNorthStar\Models;

use CBSNorthStar\Repositories\ConfigurationRepository;
use CBSNorthStar\Woapi\Connection;
use CBSNorthStar\Helpers\SiteRulesCache;

class ServingOption
{
  protected array $servingOptions;
  protected array $servingOptionComponents;

    public function load($productId): array
    {
        $response=array();
    
        $product = wc_get_product($productId);
    
        $this->servingOptions = $this->getServingOptions($product);
        $this->servingOptionComponents = $this->processServingOptions();
    
        $response['servingOptions'] = $this->servingOptionComponents;

        return $response;
    }

    public function getServingOptions($product): array
    {
        if(!$product) {
            return [];
        }
        $servingOptions = get_post_meta($product->get_id(), '_servingoptions', true);
        $servingOptions = $servingOptions? wp_unslash($servingOptions) : $servingOptions;
        return json_decode($servingOptions,true) ?? [];
    }

    public function processServingOptions(): array
    {
        if(empty($this->servingOptions)) {
            return [];
        }

        $servingOptionComponents = [];
        $apiCallCount = 1;
        foreach ($this->servingOptions as $servingOptionCategoryId => $servingOptions) {
            
            $categoryNames = array_map(fn($servingOption) => $servingOption["categoryName"], $servingOptions);
            $uniqueCategoryNames = array_unique($categoryNames);
            $categoryName = reset($uniqueCategoryNames); // Get the first unique category name

            usort($servingOptions, function($a, $b) {
                return $a['displayOrder'] <=> $b['displayOrder'];
            });

            foreach ($servingOptions as $option) {
                $option = (object) $option;
                $servingOptionComponents[$categoryName]['items'][$option->servingOptionName]= [
                    "servingOptionName" => $option->servingOptionName,
                    "servingOptionPrice" => $option->servingOptionPrice,
                    "pricingLevels" => $option->pricingLevels ?? [],
                    "isDefault" => $option->isDefault === "1",
                    "siteId" => $option->siteId,
                    "categoryId" => $servingOptionCategoryId,
                    "servingOptionId" => $option->servingOptionId,
                    "displayOrder" => $option->displayOrder,
                ];

            }

            $servingOptionComponents[$categoryName]["info"] = [
                "categoryName" => $categoryName,
                "categoryId" => $servingOptionCategoryId,
            ];
            $servingOptionComponents[$categoryName]['info']['rules'] = $this->setRules($option, $apiCallCount);
            $apiCallCount++;
        }
        return $servingOptionComponents;
    }

    public function setRules($servingOption, $apiCallCount): array
    {
        $siteId = $servingOption->siteId;
        $rules = self::getOrSaveServingOptionRules($siteId, $apiCallCount);
        $categoryName = $servingOption->categoryName;
        $optionRule = [];
        $rulesData = $rules->Data;

        foreach($rulesData as $rule) {
            if($rule->Name == $categoryName) {
                $optionRule[$rule->ServingOptionCategoryId] = [
                    "MaxAllowed" => $rule->MaxAllowed,
                    "MinRequired" => $rule->MinRequired,
                ];
            }
        }

        return $optionRule;
    }

    /**
     * @param object|null $prefetchedResponse Optional already-fetched
     *        /servingOptionCategories/ response (from the deploy's parallel
     *        prefetch batch) — skips the API call but still persists to DB.
     */
    public static function getOrSaveServingOptionRules($siteId, $apiCallCount, $prefetchedResponse = null): object
    {
        global $wpdb;

        if($apiCallCount >0) {
            // Request-scoped memo (OE-26548 N+1): siteId is invariant for the whole
            // request but this branch runs once per serving-option category per
            // product — memoise the DB read instead of re-querying every time.
            $servingOptionRulesJson = SiteRulesCache::rememberServingOptionRulesJson((string) $siteId, function () use ($wpdb, $siteId) {
                return $wpdb->get_var($wpdb->prepare(
                    "SELECT servingoptions_rules FROM cbs_save_api_response WHERE siteid = %s",
                    $siteId
                ));
            });

            if ($servingOptionRulesJson) {
                $response = json_decode($servingOptionRulesJson) ?: (object)[];
                return $response;
            }
        }

        $configuration = ConfigurationRepository::create()->getDetails();
        $token = $configuration->token;
        $siteInstance = $configuration->instance;

        $instance = ConfigurationRepository::create()->getInstance($siteInstance);
        $instanceOeapiUrl = $instance->instance_oeapiurl;

        $url =  '/servingOptionCategories/';
        $response = $prefetchedResponse ?? (new Connection())->getData($siteId, $url, 'Token');

        $rowExist = $wpdb->get_results("SELECT 1 FROM cbs_save_api_response where siteid='$siteId'");

        $responseJson = json_encode($response);

        if ($responseJson === false) {
          error_log('Failed to encode rules for site ID: ' . $siteId);
          return $response;
       }

        // Write back into the request memo: the read above memoised null (no DB
        // row yet), so without this every later call in the same request would
        // fall through to another OEAPI fetch instead of reusing this response.
        SiteRulesCache::primeServingOptionRulesJson((string) $siteId, $responseJson);

       $result = false;

        if($rowExist) {

            $updateQuery = "
                UPDATE cbs_save_api_response
                SET servingoptions_rules=%s
                WHERE siteid=%s";

            $result = $wpdb->query($wpdb->prepare($updateQuery, $responseJson , $siteId));

        }
        else {

            $result = $wpdb->insert('cbs_save_api_response', array(
            'siteid' => $siteId,
            'servingoptions_rules' => $responseJson
            ));
        }
        if ($result === false) {
            error_log('Failed to insert serving option rules for site ID: ' . $siteId );
        }

        return $response;
    }
    
}
