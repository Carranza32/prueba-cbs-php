<?php
namespace CBSNorthStar\Models;

use CBSNorthStar\Repositories\ConfigurationRepository;
use CBSNorthStar\Woapi\Connection;
use CBSNorthStar\Repositories\DaypartMenusRepository;
use CBSNorthStar\Helpers\ProductScope;
use CBSNorthStar\Helpers\CategoryVisibility;

class Categories {
    public function __construct(String $siteId = null) {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->configuration = ConfigurationRepository::create();
        $this->site = $this->configuration->getDetails();
        $this->id = $this->site->id;
        $this->instance = $this->site->instance;
        $this->siteId = $this->validateSiteId($siteId);
        $this->siteDetails = $this->getactiveSiteDetails();
        $this->menuType = $this->siteDetails->menu_type;
        $this->defaultMenu = $this->siteId ? $this->getDefaultMenuId() : "";
    }

    public function load(){
        if(!$this->siteId){
            return [];
        }
        return $this->getCaregorydata();
    }


    protected function getDefaultMenuId(){
        $menuId = (DaypartMenusRepository::create())->getActiveDaypartMenu(
            $this->siteId,
            ...(function_exists('oloNavSlotOverrides') ? oloNavSlotOverrides() : [null, null])
        );
        return $menuId ? $menuId : "";
    }

    protected function getactiveSiteDetails(){
        $sites = $this->configuration->getSiteDetails($this->id);
        foreach ($sites as $site) {
            if ($site->siteid === $this->siteId) {
                return $site;
            }
        }
        return null;
    }

    protected function validateSiteId($siteId){
        $sites = $this->configuration->getSiteDetails($this->id);
        foreach ($sites as $site) {
            if ($site->siteid === $siteId) {
                return $siteId;
            }
        }
        return null;
    }

    public function getCaregorydata(){

        $pf = $this->wpdb->prefix;
        
        // Top-level categories only (tt.parent = 0). A LinkTo item creates a child product_cat
        // term that now carries site_id/menu_id term meta (SaveProduct.php), so without this guard
        // the subcategory would leak into the kiosk category list alongside its parent.
        $queryCategories = "
      SELECT DISTINCT p.term_id, p.name, p.slug, m3.meta_value as thumbnail_id
        FROM " . $pf . "terms p
        JOIN " . $pf . "term_taxonomy tt ON p.term_id = tt.term_id AND tt.taxonomy = 'product_cat' AND tt.parent = 0
        JOIN " . $pf . "termmeta m1 ON p.term_id = m1.term_id AND m1.meta_key = 'site_id' AND m1.meta_value = '".$this->siteId."'
        JOIN " . $pf . "termmeta m2 ON p.term_id = m2.term_id AND m2.meta_key = 'menu_id' AND m2.meta_value = '".$this->defaultMenu."'
        LEFT JOIN " . $pf . "termmeta m3 ON p.term_id = m3.term_id AND m3.meta_key = 'thumbnail_id'
        ORDER BY p.name
    ";
        $queryOrderCategories  = "SELECT term_id FROM " . $pf . "termmeta
                                WHERE meta_key = 'order' 
                                ORDER BY CAST(meta_value AS UNSIGNED) ASC" ;

        $orderCategory =  $this->wpdb->get_results($queryOrderCategories);
        $results = $this->wpdb->get_results($queryCategories);
        usort($results, function ($a, $b) use ($orderCategory) {
          $posA = array_search($a->term_id, array_column($orderCategory, 'term_id'));
          $posB = array_search($b->term_id, array_column($orderCategory, 'term_id'));
          return $posA - $posB;
      });

      // Drop categories that have no item to show for the active site + menu.
      // A product_cat term keeps its site_id/menu_id term meta even after
      // OE-26435 strips an ECM-disabled item's per-site _siteid association, so a
      // category whose only items are disabled would otherwise render as an empty
      // heading (QA: a category with no activated items must not appear).
      $results = array_values(array_filter($results, function ($category) {
          return $this->categoryHasActiveProducts($category->slug);
      }));

      return $results;
    }

    /**
     * Whether a category has at least one product that would actually render on
     * the menu for the active site and daypart menu.
     *
     * A product_cat term is shared across sites and menus, so its presence in the
     * term-meta-scoped {@see getCaregorydata()} query does not guarantee it has
     * any item for THIS scope. Counting with the canonical {@see ProductScope}
     * (site + menu) and the same publish/visible/instock filters the read paths
     * use (see MainController::getProductsHTML) keeps a category in the list only
     * when productsLoop would render at least one item under it.
     *
     * Fails OPEN: if WooCommerce is unavailable the count cannot run, so the
     * category is kept rather than mass-hiding the whole menu.
     *
     * @param string $categorySlug product_cat slug.
     * @return bool True when at least one in-scope product exists.
     */
    protected function categoryHasActiveProducts($categorySlug){
        // Single source of truth shared with the legacy [menuitems] web path
        // (cbs_categories_func / cbs_menuitems_func / cbsInfinityScroll). See
        // CategoryVisibility for the publish/visible/instock + ProductScope filter,
        // request memoization, and the fail-open-when-WooCommerce-unavailable behavior.
        return CategoryVisibility::hasRenderableProducts(
            (string) $this->siteId,
            (string) $this->defaultMenu,
            (string) $categorySlug
        );
    }
    public function createLinktoCategory($parentCategoryId , $menuItemId , $itemName){
        $parentCategories = $this->checkStoredCategory($parentCategoryId);

        if($parentCategories)
        {
            $parentCategory = reset($parentCategories);

            //Check if the parent category already has a linkToCategory term meta
            $subCategory = $this->checkStoredSubcategories($parentCategory->term_id, $menuItemId);

            if(empty($subCategory)){
                $termId = $this->storeLinktoCategory($itemName ,$parentCategory->term_id, $menuItemId);
                if($termId){
                    $subCategory = $this->checkStoredSubcategories($parentCategory->term_id, $menuItemId);
                    return $subCategory[0] ?? null;

                }
            }else{
                return $subCategory[0] ?? null;
            }

        }
    }


        /**
         * Find an existing subcategory (direct child) under a given parent category,
         * by checking the `linkToId` term meta.
         *
         * @param int    $parentTermId       The parent category term_id
         * @param string|int $menuItemId     The external MenuItemId you’re matching
         * @return WP_Term[]|null            Array of WP_Term objects if found, null if none
         */
        public function checkStoredSubcategories(int $parentTermId, $menuItemId): ?array {
            $terms = get_terms([
                'taxonomy'   =>'product_cat',   // usually 'product_cat'
                'hide_empty' => false,
                'parent'     => $parentTermId,
                'meta_query' => [
                    [
                        'key'     => 'linkToId', // defaults to 'linkToId'
                        'value'   => $menuItemId,
                        'compare' => '='
                    ],
                ],
            ]);

            if (is_wp_error($terms) || empty($terms)) {
                return null;
            }


            return $terms ? $terms : null;
        }

        public function checkStoredCategory($menuCategoryId): ?array {

            
            $terms = get_terms([
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'meta_query' => [
                    [
                        'key'     => 'menu_item_category_id',
                        'value'   => $menuCategoryId,
                        'compare' => '='
                    ],
                ],
            ]);

            if (is_wp_error($terms) || empty($terms)) {
                return null;
            }

            

            return $terms;
        }

        public function storeLinktoCategory($itemName , $termId , $menuItemId){

                $subCategory = wp_insert_term(
                $itemName, // Subcategory name
                'product_cat', // Taxonomy
                array(
                    'slug'   =>  sanitize_title( $itemName ), // Subcategory slug
                    'parent' => $termId // Parent category ID
                )
            );
            if ( ! is_wp_error( $subCategory ) ) {
                $termId = $subCategory['term_id'];

                update_term_meta( $termId, 'linkToId', $menuItemId, true );

                return $termId ?? null;
            }
        }

}
