<?php
namespace CBSNorthStar\Views;

use CBSNorthStar\Helpers\ComponentPricing;
use CBSNorthStar\Models\Component;
use CBSNorthStar\Models\ServingOption;

class QuickOrderButtons{
    public $product;
    public $components;
    public $componentsSelected = [];
    public $componentsSelectedQty = [];
    public $componentsSelectedCompleteData = [];
    public $price = 0 ;
    public $itemPrice = 0 ;
    public $quickAdd = false;
    public $productInfo = [];
    public $servingOptions = [];
    public $linkToItems = [];

    public function __construct($product){
        $this->quickAdd = false;
        $this->product = $product;
        $this->productInfo = (new Component())->load($product->get_id());
        $this->servingOptions = (new ServingOption())->load($product->get_id());
        $this->components = $this->productInfo['components'];
        $this->linkToItems = $this->checkIsLinkToItems($product->get_id());

    }


    public  function render($slug = null){

        $this->initializeRenderData();
        $buttonsContainer = '<div class="quick-order-buttons">';
        $buttonsContainer .= $this->buildButtons($slug);
        $buttonsContainer .= '</div>';

        return $buttonsContainer;
    }

    private function initializeRenderData(){
        $this->quickAdd = $this->validateProduct($this->product, $this->components);
        $this->itemPrice = $this->product->get_price();
        
        if($this->quickAdd && $this->components && $this->componentsSelected){
            $this->componentsSelectedCompleteData = [
                'selComponentsPrice' => $this->price,
                'selComponents' => $this->componentsSelected,
                'selComponentsQty' => $this->componentsSelectedQty
            ];
        }
    }

    private function buildButtons($slug = null) {
        $buttons = '';
        
        // Priority order: linkToItems overrides everything else
        if ($this->linkToItems) {
            return $this->buildLinkToItemsButton();
        }
        
        $haveOptions = $this->hasProductOptions();
        
        if ($haveOptions) {
            $buttons .= $this->buildCustomizeButton();
        }
        
        if ($this->shouldShowQuantitySection($haveOptions)) {
            $buttons .= $this->createQuantitySection();
        }
        
        if ($this->quickAdd) {
            $buttons .= $this->buildQuickAddButton($slug, $haveOptions);
        }
        
        return $buttons;
    }

    private function hasProductOptions() {
        return count($this->components) > 0 || count($this->servingOptions['servingOptions']) > 0;
    }

    private function getCbsDisplayQuantity() {
        $value = carbon_get_theme_option('cbs_display_quantity');
        if ($value === null) {
            return get_option('cbs_display_quantity');
        }
        return $value;
    }

    private function shouldShowQuantitySection($haveOptions) {
        return $this->getCbsDisplayQuantity() && !$haveOptions;
    }

    private function buildCustomizeButton() {
        $url = get_permalink($this->product->get_id());
        $customizeButtonClass = $this->getCustomizeButtonClass();
        
        return '<a class="'. $customizeButtonClass .'"  href="' . $url . '"> Customize
            <i class="fa-solid fa-arrow-right"></i></a>';
    }

    private function getCustomizeButtonClass() {
        $displayQuantity = $this->getCbsDisplayQuantity();
        
        if ($displayQuantity && !$this->quickAdd) {
            return "button light customize-button fullwidth";
        }
        
        if (!$this->quickAdd && !$displayQuantity) {
            return "button light fullwidth customize-button";
        }
        
        return "button light customize-button";
    }

    private function buildQuickAddButton($slug, $haveOptions) {
        $addButtonClass = $this->getQuickAddButtonClass($haveOptions);
        $encode = json_encode($this->componentsSelectedCompleteData);
        
        return '<button  type="button" id="'.$this->product->get_id().'" class="' . $addButtonClass .'"
            data-components="['.htmlspecialchars($encode, ENT_QUOTES, 'UTF-8') .']" data-price="'.$this->itemPrice.'" data-slug="'.$slug.'">
            Add </button>';
    }

    private function getQuickAddButtonClass($haveOptions) {
        $displayQuantity = $this->getCbsDisplayQuantity();
        
        if ($displayQuantity || $haveOptions) {
            return 'button quick-add active';
        }
        
        return 'button quick-add active fullwidth';
    }

     private function buildLinkToItemsButton() {
        $isKioskMode = get_option('siteMode') === 'kiosk';
        $categoryNameEncoded = urlencode($this->linkToItems['name']);
        $referer = wp_get_referer();

        global $wp;

        if ( $isKioskMode ) {
            $baseUrl = wp_validate_redirect( $referer, home_url( '/' ) );
        } else {
            $baseUrl = get_permalink( get_queried_object_id() );
        }

        $url = esc_url( add_query_arg(
            array( 'cat_slug' => $this->linkToItems['slug'], 'cat_name' => $categoryNameEncoded ),
            $baseUrl
        ) );
       
        return '<a class="button light fullwidth linkto" href="'.$url.'" data-categoryslug="'.$this->linkToItems['slug'].'" data-is-kiosk-mode="'.($isKioskMode? 'true' : 'false' ).'">Customize</a>';
    }

    public function validateProduct($product , $components){
        switch($product->get_type()){
            case 'variable':
                return false;
                break;
            case 'simple':
                return $this->verifyComponentsRequirements($components) > 0 ? false : true;
                break;
            default:
                return false;
        }
    }
    public function verifyComponentsRequirements($componentCategories){

        if(!empty($this->servingOptions['servingOptions'])){
            return count($this->servingOptions['servingOptions']);
        }
        
        $count = 0 ;
        foreach($componentCategories as $components){
            // Full [ruleId => data] map, resolved per component below — a category
            // can mix ruleIds, so there is no single category rule to reset() to.
            // Mirrors the detail view's rulesGlobal[component.rule] lookup in
            // rules.js, which is why Customize priced free defaults correctly
            // while quick-add did not.
            $rulesMap = is_array($components['info']['rules'] ?? null) ? $components['info']['rules'] : [];
            $existingComponentsCategory = $this->countExistingComponents($components['items'], $rulesMap);

            // A category is unmet when any of its components' own rules demand more
            // defaults than are selected, or when a default component has a
            // serving-option category whose MinRequired cannot be met by its
            // defaults (independent of the category MinRequired). Either makes the
            // product non-auto-orderable, forcing Customize-only.
            $missingComponents = $this->categoryHasUnmetMinRequired($components['items'], $rulesMap, $existingComponentsCategory);
            if($missingComponents || $this->hasDefaultComponentWithUnmetServingOptions($components['items'])){
                $count++;
            }
		}

        //Return the amount of categories that are missing components minimum requirements
        return $count;
    }

    /**
     * Resolve a component's rules from the category rules map by its own ruleId.
     * Missing entry → empty array: isDefaultComponentFree([]) is false, so the
     * fail-safe direction is "charge the configured price" — never a fatal (the
     * old reset() on an empty map returned false, violating the array type hint
     * of calculateComponentPrice()).
     *
     * @param array $component
     * @param array $rulesMap [ruleId => ruleData]
     * @return array
     */
    protected function resolveComponentRules(array $component, array $rulesMap): array {
        $ruleId = (string) ($component['ruleId'] ?? 'Default');
        $rules  = $rulesMap[$ruleId] ?? [];
        return is_array($rules) ? $rules : [];
    }

    /**
     * Whether any component's own rule sets a MinRequired the selected defaults
     * do not satisfy. Evaluated per component ruleId for the same reason as
     * pricing: the old reset() read used whichever single rule survived category
     * assembly, not the rule actually governing the requirement.
     *
     * @param array $components
     * @param array $rulesMap
     * @param int   $defaultsSelected
     * @return bool
     */
    protected function categoryHasUnmetMinRequired(array $components, array $rulesMap, int $defaultsSelected): bool {
        foreach ($components as $component) {
            $rules       = $this->resolveComponentRules((array) $component, $rulesMap);
            $minRequired = (int) ($rules['MinRequired'] ?? 0);
            if ($minRequired > 0 && $defaultsSelected < $minRequired) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether any default component in a category has a serving-option category
     * whose MinRequired rule cannot be satisfied by its default options.
     *
     * @param array $components
     * @return bool
     */
    protected function hasDefaultComponentWithUnmetServingOptions(array $components): bool {
        foreach ($components as $component) {
            if (isset($component['isDefault']) && $component['isDefault'] == 1
                && $this->componentServingOptionsUnmet($component)) {
                return true;
            }
        }
        return false;
    }
    protected function countExistingComponents($components, array $rulesMap){

        $count = 0;
        foreach ($components as $component) {
            if (isset($component['isDefault']) && $component['isDefault'] == 1) {
                $component['servingOptions'] = $this->getDefaultServingOptions($component);

                $componentRules = $this->resolveComponentRules($component, $rulesMap);
                $componentPrice = $this->calculateComponentPrice($component, $componentRules, $count + 1);

                // rulePrice must ride in the posted selComponents payload — the
                // same component-only, rule-adjusted field computeSelectedComponents()
                // posts from the detail view. Without it processProductComponents()
                // falls back to the RAW price and the cart displays a charge for
                // free defaults. Set before the push (arrays copy by value).
                $component['rulePrice'] = $componentPrice;
                array_push($this->componentsSelected , $component);

                $this->price += $componentPrice;
                $component["quantity"] = 1;
                array_push($this->componentsSelectedQty, $component);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Determine whether a default component has a serving-option category whose
     * MinRequired rule cannot be met by its default options.
     *
     * @param array $component
     * @return bool True when at least one serving-option category is unsatisfiable.
     */
    protected function componentServingOptionsUnmet(array $component): bool {
        if (empty($component['componentServingOptions'])) {
            return false;
        }

        foreach (array_values($component['componentServingOptions']) as $categoryData) {
            // reset() is safe HERE, unlike the component rules it replaced: serving-option
            // rules are resolved by category name (ServingOption::setRules matches
            // $rule->Name == $categoryName), one rule per category with no per-option
            // ruleId that could be clobbered during assembly.
            $rules = isset($categoryData['info']['rules']) ? reset($categoryData['info']['rules']) : [];
            $minRequired = (int) ($rules['MinRequired'] ?? 0);

            if ($minRequired <= 0) {
                continue;
            }

            $defaultCount = count($this->extractDefaultOptionsFromCategory($categoryData));
            if ($defaultCount < $minRequired) {
                return true;
            }
        }

        return false;
    }

    protected function calculateComponentPrice(array $component, array $rules, int $position): float
    {
        // Same rule semantics as the Customize modal (rules.js), so quick-add and
        // customize price the default selection identically (OE-26648).
        if (ComponentPricing::isDefaultComponentFree($rules, $position)) {
            return 0.0;
        }

        return (float) ($component['componentPrice'] ?? 0);
    }


    protected function createQuantitySection(){
        $quantitySection = '<div class="quantity-section">';
        $quantitySection .= '<button class="quick-add quantity-button button-decrease" data-action="decrease">-</button>';
        $quantitySection .= '<input type="number" class="quantity-input" value="1" min="1" max="9999" disabled>';
        $quantitySection .= '<button class="quick-add quantity-button button-increase" data-action="increase">+</button>';
        $quantitySection .= '</div>';
        return $quantitySection;
    }

    protected function getDefaultServingOptions($component) {
        $defaultServingOptions = [];
        
        if (!isset($component['componentServingOptions']) || empty($component['componentServingOptions'])) {
            return $defaultServingOptions;
        }
        
        foreach (array_values($component['componentServingOptions']) as $categoryData) {
            $defaultServingOptions = array_merge(
                $defaultServingOptions,
                $this->extractDefaultOptionsFromCategory($categoryData)
            );
            
        }
        
        return $defaultServingOptions;
    }

    private function extractDefaultOptionsFromCategory($categoryData) {
        $defaultOptions = [];
        
        if (isset($categoryData['items']) && is_array($categoryData['items'])) {
            foreach ($categoryData['items'] as $servingOptionName => $servingOption) {
                if (isset($servingOption['isDefault']) && $servingOption['isDefault'] === true) {
                    $defaultServingOption['servingOptionId'] = $servingOption['servingOptionId'];
                    $defaultServingOption['servingOptionName'] = $servingOptionName;
                    $defaultServingOption['servingOptionPrice'] = isset($servingOption['servingOptionPrice']) ? $servingOption['servingOptionPrice'] : 0;
                    $defaultOptions[] = $defaultServingOption;
                }
            }
        }
        
        return $defaultOptions;
    }

    private function checkIsLinkToItems($productId) {
        $linkedItems = get_post_meta($productId, '_link_to_category', true);
        return json_decode($linkedItems, true) ?? [];
    }


}

