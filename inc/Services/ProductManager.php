<?php
namespace CBSNorthStar\Services;

use CBSNorthStar\Woapi\Connection;
use CBSNorthStar\SaveProduct;
use CBSNorthStar\Helpers\WoapiProductAdapter;
use CBSNorthStar\Logger\CBSLogger;

class ProductManager {
        /** @var mixed|null */
    protected $siteMenuItems;
    /** @var iterable */
    protected $menuItems = [];
    /** @var int|string|null */
    protected $siteId;
    /** @var int|string|null */
    protected $catId;

    public function __construct(
                $siteMenuitems = null,
        $menuItems     = null,
        $siteId        = null,
        $catId         = null
    ) {
        $this->siteMenuitems = $siteMenuitems;
        $this->menuItems = $menuItems;
        $this->siteId = $siteId;
        $this->catId = $catId;

        /* Move part of save products */
    }

    public function syncMenuItems(){
        $unavailableItems = $this->unavailableProducts();
        
        foreach ($this->menuItems as $menuItem) {
            $menuItemId = $menuItem->MenuItemId;
            $foundPost = $this->checkProductExistsByItemId($menuItemId);

            if ($foundPost) {
                // Product exists, update it
               /*  $this->updateProduct($foundPost, $menuItem); */
            } else {
                
                $menuItemDetails = (new SaveProduct)->getItemDetails($this->siteMenuitems, $menuItemId, $this->siteId , $this->catId);
                $product = WoapiProductAdapter::adaptProductData($menuItemDetails, $this->catId);
                $test = "";
                $this->saveOrUpdateComponentsImage($componentsData);
                
                return $product;
                /* $this->createProduct($menuItem); */
            }
        }
    }
    /**
     * Full /unavailableitems/ response Data object (menu items, components,
     * serving options) — fetched once, so callers needing more than
     * UnavailableMenuItems don't have to make a second request.
     *
     * Each list is normalized to an array: the API serializes a collection
     * with one member as a bare object rather than a one-element array, so
     * callers foreach-ing over the raw node would break on the single-item
     * case. Normalizing here also guarantees unavailableProducts() actually
     * satisfies its declared array return type.
     */
    public function unavailableData(): object {
        $url = '/unavailableitems/' ;
        $unavailableItems = (new Connection)->getData($this->siteId, $url, 'Token');
        if (is_object($unavailableItems) && !empty($unavailableItems->Ok) && is_object($unavailableItems->Data)) {
            $data = $unavailableItems->Data;
            return (object) [
                'UnavailableMenuItems'      => $this->ensureArray( $data->UnavailableMenuItems ?? [] ),
                'UnavailableComponents'     => $this->ensureArray( $data->UnavailableComponents ?? [] ),
                'UnavailableServingOptions' => $this->ensureArray( $data->UnavailableServingOptions ?? [] ),
            ];
        }
        return (object) [
            'UnavailableMenuItems'      => [],
            'UnavailableComponents'     => [],
            'UnavailableServingOptions' => [],
        ];
    }

    public function unavailableProducts(): array {
        return $this->unavailableData()->UnavailableMenuItems ?? [];
    }

    /**
     * Fetch WOAPI's `/menuitems` response for this site — a new, previously
     * unmade outbound call (product-active-date-window / save-product-deploy
     * capabilities) carrying each item's `StartDate`/`EndDate`. Unlike
     * unavailableData()'s Data object, `/menuitems`' Data node is already a
     * flat array of item records, one per MenuItemId.
     *
     * Callers should fetch this once per site per deploy run — see
     * SaveProduct::getMenuItemDatesForSite() — not once per menu/category,
     * mirroring unavailableData()'s existing once-per-site caching rationale.
     *
     * Returns null (not []) on a failed call, so callers can tell "confirmed
     * no items have active-date windows" apart from "we don't know right
     * now" — conflating the two would let a transient API failure look like
     * every item losing its window, and SaveProduct::writeActiveDateMeta()
     * would delete otherwise-correct meta on the strength of that failure.
     *
     * @return array|null Item records (each with ->MenuItemId, ->StartDate,
     *                     ->EndDate), or null when the call failed.
     */
    public function menuItemDates(): ?array {
        $url = '/menuitems';
        $response = (new Connection)->getData($this->siteId, $url, 'Token');
        if (is_object($response) && !empty($response->Ok)) {
            $items = $this->ensureArray($response->Data ?? []);
            CBSLogger::products()->debug('ProductManager::menuItemDates: fetched /menuitems', [
                'site_id'    => $this->siteId,
                'item_count' => count($items),
            ]);
            return $items;
        }

        // DIAGNOSTIC (OE — cross-site fingerprint / category-attachment investigation):
        // previously silent on failure. A site-specific /menuitems failure (bad
        // response shape, Ok=false, non-object) would fail open to an empty
        // lookup with zero visibility — this is the only place that would show it.
        CBSLogger::products()->warning('ProductManager::menuItemDates: /menuitems call did not return Ok — failing open (caller must not treat this as a confirmed-empty result)', [
            'site_id'  => $this->siteId,
            'response' => $response,
        ]);
        return null;
    }

    /**
     * Normalize an OEAPI node into an array of records (mirrors SaveProduct's
     * private helper of the same name/behavior — not shared across classes).
     *
     * @param mixed $value
     * @return array
     */
    private function ensureArray( $value ): array {
        if ( is_array( $value ) ) {
            return $value;
        }
        if ( $value === null || $value === '' ) {
            return [];
        }
        return [ $value ];
    }

    public function checkProductExistsByItemId($itemId) {
        $args = [
            'post_type'   => 'product',
            'posts_per_page' => 1,
            'post_status' => array('trash', 'publish'),
            'fields'      => 'ids',
            'no_found_rows' => true,
            'meta_key'   => '_itemid',
            'meta_value' => (string) $itemId,
        ];
    
        $query = new \WP_Query($args);
    
        if ($query->have_posts()) {
             return (int) $query->posts[0];  // Return the first matching post
        } else {
            return null; // No matching post found
        }
    }

    public function saveOrUpdateComponentsImage($componentsData) {
        // Logic to save or update component images
        // This is a placeholder for the actual implementation
    }

    public function saveOrUpdateProduct($productData) {
        // Logic to save or update product data
        // This is a placeholder for the actual implementation
    }
}