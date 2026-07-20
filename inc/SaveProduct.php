<?php

namespace CBSNorthStar;

use CBSNorthStar\Helpers\EmailService;
use CBSNorthStar\Helpers\FileManager;
use CBSNorthStar\Helpers\ImageIntegrity;
use CBSNorthStar\Helpers\MenuItemActiveWindow;
use CBSNorthStar\Repositories\ConfigurationRepository;
use CBSNorthStar\Repositories\ProcessRepository;
use CBSNorthStar\Models\Component;
use CBSNorthStar\Repositories\DaypartMenusRepository;
use CBSNorthStar\Models\ProductParams;
use CBSNorthStar\Models\Categories;
use CBSNorthStar\Woapi\Connection;
use CBSNorthStar\Services\CacheService;
use CBSNorthStar\Services\ProductManager;
use CBSNorthStar\Helpers\WoapiProductAdapter;
use Exception;
use CBSNorthStar\Models\ServingOption;
use CBSNorthStar\Logger\CBSLogger;
use CBSNorthStar\Repositories\DeployRunRepository;
use CBSNorthStar\Services\DeployProgress;


class SaveProduct
{
  public const IMG_PRODUCTS = 'img_products/';
  public const IMG_PRODUCT_LOAD = 'img_product_load/';
  public const SITES = '/sites/';
  public const PLUGIN_PATH = "wp-content/plugins/northstaronlineordering/";

  // Progress writes hit wp_options (a DB read+write each). Throttle them so a
  // large catalog does not issue one write per product.
  public const PROGRESS_FLUSH_EVERY   = 25; // flush at most every N products...
  public const PROGRESS_FLUSH_SECONDS = 2;  // ...or every N seconds, whichever first.

  protected ?ConfigurationRepository $configuration;
  protected string $instanceOeapiUrl;
  protected string $instanceEcmUrl;
  protected string $siteToken;
  protected ?ProcessRepository $processRepository;
  protected $fileManager;
  protected $daypartMenusRepository;
  protected $productIds;
  protected $areasDayPartMenus;
  protected $dayparts;
  protected $areaDayPartMenu;

  protected array $globalSiteComponent;

  protected array $productCategories;
  protected array $termExpectedItemIds = []; // [termId => [itemKey => true]]  populated during processMenuItems
  protected array $currentCategories;
  protected array $currentCategoriesDescription;
  protected array $currentPostCat;
  protected array $savedCategoriesPerMenu;
  protected array $notAvailableTerms;
  protected array $notAvailableCategoryTerms;
  protected array $visitedLinkToCategories = [];

  // Pre-indexed lookup maps built once per site/menu — eliminate O(n) linear scans.
  protected array $componentCategoryMap = []; // [ComponentCategoryId => object]
  protected array $componentMap         = []; // [ComponentId => object]
  protected array $servingOptionCatMap  = []; // [ServingOptionCategoryId => object]
  protected array $servingOptionMap     = []; // [ServingOptionId => object]
  protected array $pricingLevelMap      = []; // [PricingLevelId => object]  (rebuilt per menu)
  protected array $menuItemMap          = []; // [MenuItemId => object]  (rebuilt per menu — kills O(n²) scan)
  protected array $unavailableItemsCache = []; // [siteId => array]  fetched once per site, not per category
  protected array $unavailableComponentsCache = []; // [siteId => [componentId => true]]  same fetch as $unavailableItemsCache
  protected array $menuItemDatesCache = []; // [siteId => [MenuItemId => {StartDate, EndDate}]]  /menuitems fetched once per site, not per menu/category
  protected array $menuItemDatesFetchFailed = []; // [siteId => true] when /menuitems failed this run — see getMenuItemDatesForSite()

  // Per-menu / per-site state — replaces $GLOBALS usage.
  protected string $currentMenuId                = '';
  protected array  $currentSiteMenuItemCategory  = [];
  protected string $currentRunId                 = '';  // set from store() for DeployProgress updates
  protected int    $progressProcessedProducts    = 0;   // running count updated per product
  protected int    $progressTotalProducts        = 0;   // accumulated as batches are discovered
  protected int    $progressLastFlushCount        = 0;   // processed count at last DB flush
  protected int    $progressLastFlushTime         = 0;   // unix time of last DB flush
  protected array  $deployedItemIdsBySite        = []; // [siteId => [itemId, ...]]  accumulated per site
  protected array  $deployedItemIdsBySiteMenu    = []; // [siteId => [menuId => [itemId, ...]]] active items per site+menu, for per-menu _menuid pruning
  protected array  $deployedCategoryTermIdsBySiteMenu = []; // [siteId => [menuId => [termId, ...]]] active category term IDs per site+menu, for per-menu menu_id term meta pruning
  protected array  $createdItemPostIds           = []; // [itemId => postId] for products created during this run; keeps the existence check fresh across batches and LinkToDataJson recursion (OE-26396)
  protected bool   $skipImages                   = false; // set from Carbon Fields option on deploy start
  protected bool   $cancelDetected              = false; // set when cancel is detected mid-batch; stops outer loops
  protected array  $siteDataCache               = []; // [siteId => raw API response] populated by prefetchAllSiteData()
  protected ?array $deployScope                 = null; // [lowercase siteid => true] when scoped; null = deploy all sites
  protected bool   $forceFullDeploy             = false; // admin/manual runs bypass the menu-hash skip
  protected bool   $menuHashSkipEnabled         = true;  // kill switch: cbs_deploy_menu_hash_skip_enabled filter
  protected array  $storedMenuHashes            = []; // current site's [menuId => hash] from the last successful deploy
  protected array  $pendingMenuHashes           = []; // current site's [menuId => hash] collected this run, persisted on site success
  protected array  $storedAuxHashes             = []; // current site's ['rules'|'serving'|'root' => hash] from the last successful deploy
  protected array  $pendingAuxHashes            = []; // current site's aux hashes collected this run
  protected array  $siteAuxUnchanged            = []; // ['rules'|'serving'|'root' => bool] for the current site
  protected array  $prefetchedMenuResponses     = []; // current site's [menuId => decoded /menu/{id} response] from the parallel batch
  protected array  $prefetchedMediaItems        = []; // [mediaItemId => decoded ECM response] from the parallel batch (freed as consumed)
  protected array  $prefetchedRootResponses     = []; // [siteId => decoded /menu/ response] from the parallel batch
  protected array  $prefetchedRules             = []; // [siteId => decoded /rules/ response] from the parallel batch
  protected array  $prefetchedServingOptions    = []; // [siteId => decoded /servingOptionCategories/ response]
  private   array  $sideloadedImages            = []; // [mediaItemId => WP attachment ID] per-deploy sideload cache
  protected array  $siteStats                   = [  // per-site deploy counters; reset at start of each site
    'items_created'             => 0,
    'items_updated'             => 0,
    'items_skipped_fingerprint' => 0,
    'items_inactive'            => 0,
    'images_unchanged'          => 0,
    'images_resideloaded'       => 0,
    'images_uploaded'           => 0,
    'images_repaired'           => 0,
    'categories_total'          => 0,
  ];
  protected float  $siteDeployStartTime         = 0.0; // microtime(true) recorded at start of each site's processing

  public function __construct()
  {
    $this->configuration = ConfigurationRepository::create();
    $this->processRepository = ProcessRepository::create();
    $this->fileManager = FileManager::create();
    $this->daypartMenusRepository = DaypartMenusRepository::create();
  }

  /**
   * Run the product deploy process.
   *
   * @param string     $runId       Optional UUID of an already-opened deploy run created
   *                                by DeployLockService::tryAcquire(). When provided, the
   *                                lock has already been acquired and the legacy
   *                                ProcessRepository lock check is skipped. When empty,
   *                                the legacy lock path is used for backward compatibility
   *                                (e.g. direct CLI calls via save_product.php).
   * @param array|null $onlySiteIds Optional deploy scope: only sites whose siteid is in
   *                                this list are prefetched and deployed. Null (or empty)
   *                                deploys every site. Stale-product deletion and the
   *                                daypart swap are both per-site, so out-of-scope sites
   *                                are left completely untouched.
   * @param bool       $forceFull   Bypass the menu-hash skip so every menu is fully
   *                                reprocessed (admin button / manual resync). Fresh
   *                                hashes are still recorded for subsequent runs.
   * @return array{success: bool, message: string|array}
   */
  public function store( string $runId = '', ?array $onlySiteIds = null, bool $forceFull = false, bool $skipImages = false ): array
  {
    global $wpdb;

    // Get current products
    $this->productIds = $wpdb->get_col("
    SELECT ID
    FROM {$wpdb->prefix}posts
    WHERE post_type = 'product'
    ");

    // Legacy lock check — only used when called directly without DeployLockService.
    // When $runId is provided the lock was already acquired by the caller.
    if ( empty( $runId ) ) {
      $status = $this->processRepository->getStatus('save_product');

      if($status->result == 1){
        $mailoption  = get_option('mail_login_cbs_mail_name' , '');
        $processedString = str_replace(' ', '', $mailoption);
        EmailService::send(
          $processedString,
          'Error on Save Product',
          'fail-save-product',
          [
            'origin' => $_SERVER['HTTP_ORIGIN'] ?? '',
            'host' => $_SERVER['HTTP_HOST'] ?? '',
            'server_name' => $_SERVER['SERVER_NAME'] ?? '',
            'message'=> 'The process is already running',
          ]
        );
        return [
          'success' => false,
          'message' => 'The process is already running'
        ];
      }

      $this->processRepository->updateStatus('save_product', 1);
    }

    $configuration = $this->configuration->getDetails();
    $instanceName = $configuration->instance;
    $instance = $this->configuration->getInstance($instanceName);

    $tokenId = $configuration->id;
    $siteToken = $configuration->token;
    $instanceEcmUrl = $instance->instance_ecmurl;
    $instanceOeapiUrl = $instance->instance_oeapiurl;

    $this->instanceOeapiUrl = $instanceOeapiUrl;
    $this->instanceEcmUrl = $instanceEcmUrl;
    $this->siteToken = $siteToken;
    $this->currentCategories             = array();
    $this->currentCategoriesDescription  = array();
    $this->productCategories             = array();
    $this->termExpectedItemIds           = [];
    $this->currentPostCat                = array();
    $this->savedCategoriesPerMenu        = array();
    $this->notAvailableTerms             = array();
    $this->visitedLinkToCategories       = [];
    $this->createdItemPostIds            = [];
    $this->currentSiteMenuItemCategory   = [];
    $this->currentMenuId                 = '';
    $this->currentRunId                  = $runId;
    $this->skipImages                    = $skipImages || (bool) carbon_get_theme_option( 'olo_skip_deploy_images' );

    if ( (bool) carbon_get_theme_option( 'olo_simulate_deploy_failure' ) ) {
      update_option( '_olo_simulate_deploy_failure', '' );
      \CBSNorthStar\Logger\CBSLogger::general()->warning( 'store(): simulated failure flag was set — returning failure immediately', [
        'run_id' => $runId ?: 'legacy',
      ] );
      return [
        'success'            => false,
        'message'            => 'Simulated failure (debug mode)',
        'products_attempted' => 0,
        'products_succeeded' => 0,
        'products_failed'    => 0,
        'products_skipped'   => 0,
      ];
    }

    $this->progressProcessedProducts     = 0;
    $this->progressTotalProducts         = 0;
    $this->progressLastFlushCount        = 0;
    $this->progressLastFlushTime         = 0;
    $this->menuItemMap                   = [];
    $this->unavailableItemsCache         = [];
    $this->unavailableComponentsCache    = [];
    $this->menuItemDatesCache            = [];
    $this->menuItemDatesFetchFailed       = [];
    $this->siteDataCache                 = [];
    $this->prefetchedRootResponses       = [];
    $this->prefetchedRules               = [];
    $this->prefetchedServingOptions      = [];
    $this->prefetchedMenuResponses       = [];
    $this->prefetchedMediaItems          = [];
    $this->storedAuxHashes               = [];
    $this->pendingAuxHashes              = [];
    $this->siteAuxUnchanged              = [];
    $this->cancelDetected                = false;

    // Normalize the deploy scope into a lowercase lookup set. Empty array is
    // treated as "no scope" (full deploy) — a scoped run with zero matching
    // sites must never be allowed to no-op silently while looking successful.
    $this->deployScope = null;
    if ( ! empty( $onlySiteIds ) ) {
      $this->deployScope = [];
      foreach ( $onlySiteIds as $scopedId ) {
        $this->deployScope[ strtolower( (string) $scopedId ) ] = true;
      }
      CBSLogger::products()->info( 'Deploy scoped to specific sites', [
        'run_id'   => $runId ?: 'legacy',
        'site_ids' => implode( ',', $onlySiteIds ),
      ] );
    }

    // Menu-hash skip configuration. The kill switch disables the feature
    // outright (no loads, no skips, no persists); force-full keeps recording
    // fresh hashes but never skips, so an admin resync re-primes the cache
    // for subsequent webhook deploys.
    $this->menuHashSkipEnabled = (bool) apply_filters( 'cbs_deploy_menu_hash_skip_enabled', true );
    $this->forceFullDeploy     = (bool) apply_filters( 'cbs_deploy_force_full', $forceFull );
    $this->storedMenuHashes    = [];
    $this->pendingMenuHashes   = [];

    CBSLogger::products()->info( 'Menu-hash skip configuration', [
      'run_id'     => $runId ?: 'legacy',
      'enabled'    => $this->menuHashSkipEnabled ? 'yes' : 'no',
      'force_full' => $this->forceFullDeploy ? 'yes' : 'no',
    ] );


    $sites = $this->configuration->getSiteDetails($tokenId);
    $hasError    = false;
    $messages    = [];
    $attempted   = 0;
    $succeeded   = 0;
    $failedCount = 0;
    $skipped     = 0;
    if (!empty($sites)) {
      // Prefetch all site menu data before the main loop so totalProducts is
      // known upfront and the progress bar never regresses.
      if ( ! empty( $this->currentRunId ) ) {
        $prefetchedTotal = $this->prefetchAllSiteData( $sites );

        // If cancel was requested during prefetch, exit before the main loop.
        if ( DeployProgress::isCancelRequested( $this->currentRunId ) ) {
          $this->clearSaveProductStatus( $runId, false, [] );
          return [
            'success'            => false,
            'message'            => 'Deploy cancelled during prefetch',
            'products_attempted' => 0,
            'products_succeeded' => 0,
            'products_failed'    => 0,
            'products_skipped'   => 0,
          ];
        }

        // Seed the running accumulator so that dynamic additions in getItemDetails()
        // (for LinkToDataJson subcategory items) build on top of this baseline
        // instead of starting from zero — preventing the visible counter dip.
        $this->progressTotalProducts = $prefetchedTotal;

        CBSLogger::products()->debug( 'Prefetch complete — seeding progressTotalProducts', [
            'run_id'          => $this->currentRunId,
            'prefetchedTotal' => $prefetchedTotal,
        ] );

        DeployProgress::update( $this->currentRunId, [
          'totalProducts' => $prefetchedTotal,
        ] );
      }

      try {

        foreach ($sites as $site) {
          // Check cancel flag between sites.
          if ( ! empty( $this->currentRunId ) && DeployProgress::isCancelRequested( $this->currentRunId ) ) {
            DeployProgress::cancel( $this->currentRunId );
            CBSLogger::products()->info( 'Deploy cancelled between sites', [ 'run_id' => $this->currentRunId ] );
            break;
          }

          $siteId = $site->siteid;
          $menuType = $site->menu_type;
          $areaId = $site->areaid;

          if ( ! $this->isSiteInScope( $siteId ) ) {
            CBSLogger::products()->debug( 'store: site skipped — not in deploy scope', [
              'site_id' => $siteId,
              'run_id'  => $this->currentRunId,
            ] );
            continue;
          }

          if ($menuType == "Default") {
            $attempted++;

            // Reset per-site deployed-ID accumulator before each site deploy.
            $this->deployedItemIdsBySite[$siteId] = [];
            // Reset the per-(site,menu) active-item accumulator too — it drives the
            // per-menu _menuid pruning so an item disabled in one daypart menu (but
            // still active in another menu of the same site) stops rendering in the
            // menu it was disabled in (OE-26435).
            $this->deployedItemIdsBySiteMenu[$siteId] = [];
            // Reset the matching category-term accumulator for the same defensive reason —
            // store() is currently always called on a fresh instance, but symmetry with
            // the two properties above ensures correctness if that ever changes.
            $this->deployedCategoryTermIdsBySiteMenu[$siteId] = [];
            $this->siteDeployStartTime = microtime( true );
            $this->siteStats = [
              'items_created'             => 0,
              'items_updated'             => 0,
              'items_skipped_fingerprint' => 0,
              'items_inactive'            => 0,
              'images_unchanged'          => 0,
              'images_resideloaded'       => 0,
              'images_uploaded'           => 0,
              'images_repaired'           => 0,
              'categories_total'          => 0,
            ];

            // Load the menu hashes recorded by this site's last successful
            // deploy and reset the pending set collected during this run,
            // then hash the prefetched aux payloads (rules / serving options
            // / site root) so unchanged ones can skip their DB rewrites and
            // gate the menu skip.
            $this->loadStoredMenuHashes( $siteId );
            $this->evaluateSiteAuxHashes( $siteId );

            if ( ! empty( $this->currentRunId ) ) {
              DeployProgress::update( $this->currentRunId, [
                'currentStep'    => 'Deploying site: ' . $site->site_name,
                'currentSiteId'  => $siteId,
                'currentSiteName'=> $site->site_name,
              ] );
            }

            $result = $this->getDefaultWebOrderingMenuid($siteId, $areaId, $site->site_name);

            if ( $this->cancelDetected ) {
              break;
            }

            if(!$result['success']) {
              $hasError = true;
              $failedCount++;
              $messages[] = "{$site->site_name}: {$result['message']}";
              if ( ! empty( $this->currentRunId ) ) {
                DeployProgress::appendError( $this->currentRunId, "{$site->site_name}: {$result['message']}" );
              }
            }else{
              // Prune per-menu associations FIRST: an item disabled (or removed) in one
              // daypart menu but still active in another menu of the same site keeps its
              // _siteid (correct — it is still on the site), so deleteStaleProductsForSite
              // below will not touch it. Without this, its stale _menuid row for the
              // disabled menu survives and the item keeps rendering in that menu
              // (OE-26435). Runs after all menus are saved so every _menuid row exists.
              foreach ( $this->deployedItemIdsBySiteMenu[ $siteId ] ?? [] as $menuId => $activeIds ) {
                $this->deleteStaleMenuAssociations( $siteId, (string) $menuId, $activeIds );
                $this->deleteStaleMenuCategoryMeta(
                  $siteId,
                  (string) $menuId,
                  $this->deployedCategoryTermIdsBySiteMenu[ $siteId ][ $menuId ] ?? []
                );
              }

              // Delete products that are no longer in the API for this site.
              $this->deleteStaleProductsForSite( $siteId, $this->deployedItemIdsBySite[$siteId] ?? [] );

              // Persist menu hashes ONLY after the site fully succeeded —
              // a failed or cancelled site keeps its old hashes so the next
              // run reprocesses everything it needs to.
              $this->persistMenuHashes( $siteId );

              CBSLogger::products()->info( 'deploy: site completed', array_merge( $this->siteStats, [
                'site_id'         => $siteId,
                'run_id'          => $this->currentRunId,
                'elapsed_seconds' => round( microtime( true ) - $this->siteDeployStartTime, 2 ),
              ] ) );

              $succeeded++;
              $messages[] = "{$site->site_name}: Menu saved";
              if ( ! empty( $this->currentRunId ) ) {
                DeployProgress::update( $this->currentRunId, [
                  'processedSites' => $succeeded,
                  'currentStep'    => 'Site completed: ' . $site->site_name,
                ] );
                // Update both heartbeats so stale-recovery doesn't kill a slow deploy:
                // the run-log row (audit/report) and the authoritative deploy-lock row.
                \CBSNorthStar\Repositories\DeployRunRepository::create()->updateHeartbeat( $this->currentRunId );
                \CBSNorthStar\Services\DeployLockService::create()->heartbeat( $this->currentRunId );
              }
            }

          } else {
            // Non-Default menu types are not yet implemented.
            // Count them as skipped so the deploy report reflects the full site list.
            $skipped++;
            $messages[] = "{$site->site_name}: Skipped (menu disabled)";
          }
        }

        // If cancel was detected anywhere in the loop, finalise the cancelled state here.
        // We deliberately do NOT call cancel() inside processMenuItems() to avoid deleting
        // the cancel key before all loops have had a chance to check isCancelRequested().
        if ( $this->cancelDetected && ! empty( $this->currentRunId ) ) {
          $progress = DeployProgress::get( $this->currentRunId );
          if ( ( $progress['status'] ?? '' ) !== 'cancelled' ) {
            DeployProgress::cancel( $this->currentRunId );
          }
          $this->clearSaveProductStatus( $runId, false, [] );
          return [
            'success'            => false,
            'message'            => 'Deploy cancelled',
            'products_attempted' => $attempted,
            'products_succeeded' => $succeeded,
            'products_failed'    => $failedCount,
            'products_skipped'   => $skipped,
          ];
        }

        $summary = sprintf(
          'Saved %d of %d site(s). %d skipped, %d failed.',
          $succeeded,
          $attempted + $skipped,
          $skipped,
          $failedCount
        );
        array_unshift($messages, $summary);

        $result = [
          'success'            => !$hasError,
          'message'            => $messages,
          'products_attempted' => $attempted,
          'products_succeeded' => $succeeded,
          'products_failed'    => $failedCount,
          'products_skipped'   => $skipped,
        ];

      } catch (Exception $e) {
        $this->clearSaveProductStatus($runId, false, $messages);
        return [
          'success'            => false,
          'message'            => $e->getMessage(),
          'products_attempted' => $attempted,
          'products_succeeded' => $succeeded,
          'products_failed'    => $failedCount,
          'products_skipped'   => $skipped,
        ];
      }
    } else {
      $this->clearSaveProductStatus($runId, false, $messages);
      return [
        'success'            => false,
        'message'            => 'Sites not found',
        'products_attempted' => 0,
        'products_succeeded' => 0,
        'products_failed'    => 0,
        'products_skipped'   => 0,
      ];
    }


    $this->deleteProductImgFromFolder();
    $this->cleanProductCache();


    CBSLogger::products()->info('Save product result', ['success' => $result['success'], 'run_id' => $runId ?: 'legacy']);

    // Legacy status reset — only used when not going through DeployLockService.
    $this->clearSaveProductStatus($runId, $result['success'], $messages);



    // Prime the object→term relationship cache for every touched product in a
    // single query, so the get_the_terms() calls below are all cache hits
    // instead of one query per product (N+1).
    $cleanupProductIds = array_filter( array_map( 'intval', array_keys( $this->productCategories ) ) );
    if ( ! empty( $cleanupProductIds ) ) {
      update_object_term_cache( $cleanupProductIds, 'product' );
    }

    foreach ($this->productCategories as $product_categories_key => $product_categories_value) {
        $terms = get_the_terms($product_categories_key, 'product_cat');
        $this->currentPostCat = (!is_wp_error($terms) && is_array($terms)) ? $terms : [];
      foreach ($this->currentPostCat as $current_post_cat_key => $current_post_cat_value) {
        foreach ($product_categories_value as $value2) {
          if($current_post_cat_value->term_id == $value2 ){
            unset($this->currentPostCat[$current_post_cat_key]);
          }
        }
      }
      if(!empty($this->currentPostCat)){
        foreach ($this->currentPostCat as $value_post_cat) {
          wp_remove_object_terms( $product_categories_key, $value_post_cat->term_id , 'product_cat' );
        }
      }
    }

    // Reverse-validation: for every term we processed this deploy, remove any
    // product whose _itemid is NOT in the expected set. This repairs corrupted
    // assignments from previous deploys (e.g. a term that used to hold two
    // distinct category IDs still has products from the "wrong" category).
    $this->removeStaleTermProducts();

    $categoriesByTaxonomy = get_terms(array(
      'taxonomy'   => 'product_cat',
      'hide_empty' => false
    ));
    $categoriesTermsIds = wp_list_pluck($categoriesByTaxonomy, 'term_id');
    $this->notAvailableCategoryTerms = array_diff($categoriesTermsIds, array_keys($this->savedCategoriesPerMenu));

    // SCOPED runs only register categories for in-scope sites, so the diff
    // above would otherwise contain every out-of-scope site's categories and
    // delete them. Restrict deletions to terms whose recorded site_id metas
    // all fall inside the scope; terms with no site_id meta (unknown
    // ownership) are never deleted on a scoped run. Full runs keep the
    // original behavior unchanged. (get_terms primes the term-meta cache, so
    // these lookups are memory hits.)
    if ( $this->deployScope !== null ) {
      $this->notAvailableCategoryTerms = array_filter(
        $this->notAvailableCategoryTerms,
        function ( $termId ) {
          $termSiteIds = get_term_meta( (int) $termId, 'site_id', false );
          if ( empty( $termSiteIds ) ) {
            return false;
          }
          foreach ( $termSiteIds as $termSiteId ) {
            if ( ! isset( $this->deployScope[ strtolower( (string) $termSiteId ) ] ) ) {
              return false;
            }
          }
          return true;
        }
      );
    }

    foreach ($this->notAvailableCategoryTerms as $termId) {
      wp_delete_term($termId, 'product_cat');
    }


    // Bulk-load all category terms once so the per-term get_term() below is a
    // cache hit rather than a query per category (N+1).
    $descTermIds = array_filter( array_map( 'intval', array_keys( $this->currentCategoriesDescription ) ) );
    if ( ! empty( $descTermIds ) ) {
      get_terms( array( 'taxonomy' => 'product_cat', 'include' => $descTermIds, 'hide_empty' => false ) );
    }

    foreach ($this->currentCategoriesDescription as $key => $value) {
      $term = get_term( $key );
      if ( ! ( $term instanceof \WP_Term ) ) {
        continue;
      }
      $termName = $term->name;
      $siteMenuItemCategory = $this->currentSiteMenuItemCategory;
      $newCategoryDescription = $this->searchDescription($termName, $siteMenuItemCategory);

      wp_update_term( $key , 'product_cat' ,  array('description' => $newCategoryDescription,
        'category_description' => $newCategoryDescription));
    }

    if ( ! empty( $result['success'] ) ) {
      $this->bumpCatalogCacheVersion();
    }

    return $result;
  }

  /**
   * Bump a monotonic version used by product list caches (server + browser).
   *
   * Any successful deploy increments this value so product HTML caches keyed
   * by version are invalidated immediately without global cache flushes.
   */
  private function bumpCatalogCacheVersion(): void
  {
    $optionKey = 'cbs_catalog_cache_version';
    $current   = (int) get_option( $optionKey, '0' );
    $next      = (string) max( 1, $current + 1 );

    update_option( $optionKey, $next, false );

    CBSLogger::products()->info( 'Catalog cache version bumped after successful deploy', [
      'cache_version' => $next,
    ] );
  }

  /**
   * Reset the legacy ProcessRepository status for save_product.
   * No-op when $runId is set (lock is managed by DeployLockService in that case).
   */
  private function clearSaveProductStatus(string $runId, bool $success, array $messages): void
  {
    if (empty($runId)) {
      if ($success) {
        $this->processRepository->updateStatus('save_product', 0);
      } else {
        $this->processRepository->updateStatus('save_product', 2, $messages);
        $mailoption      = get_option('mail_login_cbs_mail_name', '');
        $processedString = str_replace(' ', '', $mailoption);
        $messageString   = implode(', ', $messages);
        EmailService::send(
          $processedString,
          'Error on Save Product',
          'fail-save-product',
          [
            'origin'      => $_SERVER['HTTP_ORIGIN'] ?? '',
            'host'        => $_SERVER['HTTP_HOST'] ?? '',
            'server_name' => $_SERVER['SERVER_NAME'] ?? '',
            'message'     => $messageString,
          ]
        );
      }
    }
  }

  /**
   * Delete all downloaded images from plugins folder after fetching products from api
   *
   * @return void
   */
  protected function deleteProductImgFromFolder(): void
  {
    // Delete both temp folders after each deploy — disk is clean between runs.
    // The skip mechanism is picture_record (persistent DB) + WP media library,
    // not file_exists on disk.
    $productImagePath = plugin_dir_path(CBS_PLUGIN_FILE) . self::IMG_PRODUCTS;
    $productLoadPath  = plugin_dir_path(CBS_PLUGIN_FILE) . self::IMG_PRODUCT_LOAD;
    $this->fileManager->deleteFilesFromFolder($productImagePath);
    $this->fileManager->deleteFilesFromFolder($productLoadPath);
  }

  /**
   * Fetch default web ordering menu id
   *
   * @param string $siteId site id provided.
   * @param string $menutype type of the menu.
   */
  /**
   * Pre-fetch all site menu data before the main deploy loop.
   *
   * Calls the OEAPI menu endpoint for each Default site, caches the raw
   * response in $this->siteDataCache[$siteId], and counts all product items
   * filtered to each site's own $site->areaid. Returns the grand total so
   * DeployProgress can display an accurate percent from the first poll.
   *
   * Each site is fetched exactly once here; the main processing loop reads
   * from the cache (getWholeSiteData checks $this->siteDataCache) and makes
   * no additional full-site API calls.
   *
   * @param array $sites  All configured sites (from ConfigurationRepository::getSiteDetails).
   * @return int          Total product item count across all Default sites, or 0 on cancel.
   */
  protected function prefetchAllSiteData( array $sites ): int
  {
    $total = 0;

    // Fire the four per-site GETs (root menu, rules, servingOptionCategories,
    // unavailableitems) for every in-scope site concurrently. Wall-clock for
    // the prefetch phase drops from (4 × sites × latency) to roughly the
    // slowest response per chunk. On any failure the per-site loop below (and
    // the lazy fetch paths for rules/serving-options/unavailable items) fall
    // back to the sequential behavior unchanged.
    $this->prefetchSiteEndpointsParallel( $sites );

    foreach ( $sites as $site ) {
      // Honour cancel requests between sites.
      if ( ! empty( $this->currentRunId ) && DeployProgress::isCancelRequested( $this->currentRunId ) ) {
        DeployProgress::cancel( $this->currentRunId );
        CBSLogger::products()->info( 'Deploy cancelled during prefetch', [ 'run_id' => $this->currentRunId ] );
        return 0;
      }

      if ( $site->menu_type !== 'Default' ) {
        continue;
      }

      if ( ! $this->isSiteInScope( $site->siteid ) ) {
        continue; // Scoped deploy — this site is not part of the run.
      }

      $siteId = $site->siteid;
      $areaId = $site->areaid;
      $url    = $this->instanceOeapiUrl . self::SITES . $siteId . '/menu/';

      // Use the parallel-batch response when available; fall back to a
      // sequential fetch for any site whose batch request failed.
      $response = $this->prefetchedRootResponses[ $siteId ]
        ?? $this->getApiData( $url, $this->siteToken, 'Token' );

      if ( is_wp_error( $response ) ) {
        CBSLogger::products()->warning( 'Prefetch: API error for site — contributing 0 to total', [
          'site_id' => $siteId,
          'error'   => $response->get_error_message(),
        ] );
        continue;
      }

      $response = (object) $response;

      if ( empty( $response->Ok ) || empty( $response->Data ) || is_null( $response->Data->DataDefinitions ) ) {
        CBSLogger::products()->warning( 'Prefetch: invalid response for site — contributing 0 to total', [
          'site_id' => $siteId,
        ] );
        continue;
      }

      // Cache the valid response for the processing pass.
      $this->siteDataCache[ $siteId ] = $response;

      // Count items for this site filtered to its own areaId.
      // ensureArray(): the OEAPI returns a one-element collection as a bare
      // object — iterating it raw walks its properties, not its records.
      $areaDayPartMenu       = $this->ensureArray( $response->Data->DataDefinitions->AreaDayPartMenus->AreaDayPartMenu ?? [] );
      $siteMenuDefinitions   = $this->ensureArray( $response->Data->MenuDefinitions->Menu ?? [] );
      $countedMenuIds        = [];

      foreach ( $areaDayPartMenu as $adpm ) {
        if ( $adpm->AreaId !== $areaId || in_array( $adpm->MenuId, $countedMenuIds, true ) ) {
          continue;
        }
        foreach ( $siteMenuDefinitions as $menuDef ) {
          if ( $menuDef->MenuId == $adpm->MenuId ) {
            $countedMenuIds[] = $adpm->MenuId;
            foreach ( $this->ensureArray( $menuDef->MenuMenuCategories->MenuMenuCategory ?? [] ) as $mmcat ) {
              $items = $mmcat->MenuItemCategory->MenuItemCategoryItems->MenuItemCategoryItem ?? [];
              if ( is_array( $items ) ) {
                $total += count( $items );
              } elseif ( is_object( $items ) ) {
                $total += 1; // API returns a single item as stdClass, not a one-element array
              }
            }
            break;
          }
        }
      }
    }

    return $total;
  }

  protected function getDefaultWebOrderingMenuid($siteId, $areaId, string $siteName = '' )
  {
    try {


      // Skip the rules DB rewrite when the prefetched /rules/ payload is
      // byte-identical to the last successful deploy's — the stored row is
      // already correct. Any uncertainty (no prefetched payload, hash
      // mismatch, force-full) takes the normal path.
      if ( empty( $this->siteAuxUnchanged['rules'] ) ) {
        (new Component())->getComponentsRule($siteId, 0, $this->prefetchedRules[$siteId] ?? null);
      } else {
        CBSLogger::products()->info('Rules payload unchanged — skipping rules rewrite', ['site_id' => $siteId]);
      }

      $result = $this->getWholeSiteData($siteId, $areaId, $siteName);

      if (is_array($result) && !($result['success'] ?? true)) {
        CBSLogger::products()->error('getWholeSiteData returned failure', ['site_id' => $siteId, 'message' => $result['message'] ?? '']);
        return ['success' => false, 'message' => $result['message'] ?? 'getWholeSiteData failed'];
      }

      CBSLogger::products()->debug('getDefaultWebOrderingMenuid: getWholeSiteData completed', ['site_id' => $siteId]);

      return [
        'success' => true,
        'message' => 'Menu fetched'
      ];

    } catch (Exception $e) {

      CBSLogger::products()->error('getDefaultWebOrderingMenuid exception', ['message' => $e->getMessage()]);
      return [
        'success' => false,
        'message' => $e->getMessage()
      ];

    }
  }



  /**
   * Fetch complete data of an site id
   *
   * @param string $menuid menu id.
   * @param string $siteId site id.
   * @param string $menutype menu type.
   */
  protected function getWholeSiteData( $siteId, $areaId, string $siteName = '' )
  {
    try {
      $url = $this->instanceOeapiUrl . self::SITES . $siteId . '/menu/';

      $token = $this->siteToken;
      $tokenType = 'Token';

      // Use the prefetched response when available — avoids a duplicate API call.
      // Falls back to a live call when the prefetch failed or was skipped for this site.
      if ( isset( $this->siteDataCache[ $siteId ] ) ) {
        $response = $this->siteDataCache[ $siteId ];
      } else {
        $response = $this->getApiData($url, $token, $tokenType);
      }

      if (is_wp_error($response)) {
        return ['success' => false, 'message' => $response->get_error_message()];
      }

      $response = (object) $response;

      if(!$response->Ok){
        return [
          'success'=>false,
          'message' => 'Error fetching site data'
        ];
      }

      if ( $response->Data->SiteId =='00000000-0000-0000-0000-000000000000') {
        return [
          'success'=>false,
          'message' => 'Error site id'
        ];
      }
      
      if(is_null($response->Data->DataDefinitions)){
        return [
          'success'=> false,
          'message' => 'Error data definition empty'
        ];
      }

      $siteCompleteData = $response->Data;  // get complete data from site id
      $siteComponentCategory = $siteCompleteData->DataDefinitions->ComponentCategories->ComponentCategory;

      $siteComponent = $siteCompleteData->DataDefinitions->Components->Component;
      $this->globalSiteComponent = $siteComponent;

      $siteServingOptionCategory =
        $siteCompleteData->DataDefinitions
          ->ServingOptionCategories
          ->ServingOptionCategory;
      $siteServingOption = $siteCompleteData->DataDefinitions->ServingOptions->ServingOption;

      // Build site-level lookup maps once per site (replaces O(n) scans per item).
      $this->buildSiteDataMaps(
        $siteComponentCategory,
        $siteComponent,
        $siteServingOptionCategory,
        $siteServingOption
      );

      // Normalize the three API nodes to arrays. The OEAPI returns a bare
      // object (not a one-element array) when a site/area has a single menu,
      // daypart, or area-daypart mapping. Iterating that object directly walks
      // its *properties*, never its records — so nothing gets re-saved and the
      // daypart rebuild silently produces zero rows. This is the same
      // single-object guard prefetchAllSiteData() applies.
      $siteMenuDefinitionsMenu = $this->ensureArray($siteCompleteData->MenuDefinitions->Menu ?? []);

      $this->areasDayPartMenus = $response->Data->DataDefinitions->AreaDayPartMenus;
      $this->dayparts          = $this->ensureArray($response->Data->DataDefinitions->DayParts->DayPart ?? []);
      $this->areaDayPartMenu   = $this->ensureArray($this->areasDayPartMenus->AreaDayPartMenu ?? []);

      // Snapshot current daypart rows before wiping — protects frontend during deploy window.
      // Uses wp_options directly (not transients) so it is immune to broken external object cache
      // (Pressable: object-cache.php present but Redis disabled → set_transient silently drops data).
      $existingDayparts = $this->daypartMenusRepository->getSiteDaypartMenus($siteId);
      $snapshotStored   = update_option(
        'cbs_daypart_snapshot_' . $siteId,
        [
          'data'       => $existingDayparts,
          'expires_at' => time() + ( 15 * MINUTE_IN_SECONDS ),
        ],
        false // do not autoload — only read during deploy window fallback
      );
      CBSLogger::general()->info( 'Deploy: daypart snapshot stored', [
        'site_id'        => $siteId,
        'existing_count' => count( (array) $existingDayparts ),
        'option_key'     => 'cbs_daypart_snapshot_' . $siteId,
        'stored_result'  => $snapshotStored ? 'ok' : 'unchanged',
      ] );

      if ( empty( $areaId ) ) {
        CBSLogger::products()->warning( 'Deploy: site has no areaid configured; daypart menus may not be written.', [
          'site_id' => $siteId,
          'areaId' => $areaId,
          'run_id' => $this->currentRunId,
        ] );
        if ( ! empty( $this->currentRunId ) ) {
          DeployRunRepository::create()->appendEvent(
            $this->currentRunId,
            DeployRunRepository::EVENT_SITE_NO_AREA,
            DeployRunRepository::SEVERITY_WARNING,
            'Site has no areaid configured; daypart menus may not be written.',
            [ 'site_id' => $siteId, 'areaId' => $areaId ]
          );
        }
      }

      // Same skip as the rules rewrite: byte-identical serving-option payload
      // means the stored cbs_save_api_response row is already correct.
      if ( empty( $this->siteAuxUnchanged['serving'] ) ) {
        ServingOption::getOrSaveServingOptionRules($siteId, 0, $this->prefetchedServingOptions[$siteId] ?? null);
      } else {
        CBSLogger::products()->info('Serving-option payload unchanged — skipping rewrite', ['site_id' => $siteId]);
      }

      // Build the full daypart-menu state in memory first, then swap it in
      // atomically (delete + re-insert inside one transaction). Replaces the
      // old "delete first, rebuild across slow per-menu API calls" pattern that
      // left the storefront menu empty for the whole deploy window — and
      // permanently whenever the process died, was cancelled, or hit a fatal
      // mid-rebuild. The wp_options snapshot above remains as a second line of
      // defense for readers, but with the atomic swap the live table itself is
      // never observable half-written.
      $daypartRows     = $this->buildDaypartRows($siteId, $areaId, $siteMenuDefinitionsMenu);
      $daypartsSwapped = false;
      if (empty($daypartRows)) {
        if (!empty($areaId)) {
          if (!empty($this->areaDayPartMenu)) {
            // Situation B: API returned daypart data but no entry matched this areaId.
            // ECM intentionally has no dayparts for this area — clear stale rows.
            $this->daypartMenusRepository->deleteMenus($siteId);
            CBSLogger::products()->warning('No daypart found for areaId in ECM — confirmed intentional empty, stale rows cleared', [
              'site_id' => $siteId,
              'areaId'   => $areaId,
              'siteName' => $siteName,
            ]);
          } else {
            // Situation A: API returned no AreaDayPartMenu data at all.
            // Could be a transient fetch failure or ECM has no dayparts configured.
            // Preserve existing rows as a safety measure.
            CBSLogger::products()->warning('No daypart rows built for site — preserving existing rows (suspected fetch/parse issue)', [
              'site_id' => $siteId,
              'areaId' => $areaId,
            ]);
          }
        }
      } else {
        $daypartsSwapped = $this->daypartMenusRepository->replaceSiteMenus($siteId, $daypartRows);
        if (!$daypartsSwapped) {
          CBSLogger::products()->error('Failed to replace daypart menus for site — existing rows left intact', [
            'site_id' => $siteId,
            'rowCount' => count($daypartRows),
          ]);
        }
      }

      // Fetch every distinct menu for this area concurrently before the
      // processing loop — the loop then consumes the prefetched responses and
      // only falls back to a sequential retrieveMenuData() for any menu whose
      // batch request failed. (The menu list is fully known here: area
      // mappings × menu definitions, deduped.)
      $menuUrlsToFetch = [];
      $knownAreaMenuIds = [];
      foreach ( $siteMenuDefinitionsMenu as $menuDef ) {
        $knownAreaMenuIds[ (string) $menuDef->MenuId ] = true;
      }
      foreach ( $this->areaDayPartMenu as $adpm ) {
        $adpmMenuId = (string) $adpm->MenuId;
        if ( $adpm->AreaId === $areaId && isset( $knownAreaMenuIds[ $adpmMenuId ] ) ) {
          $menuUrlsToFetch[ $adpmMenuId ] = $this->instanceOeapiUrl . '/sites/' . $siteId . '/menu/' . $adpmMenuId;
        }
      }
      $this->prefetchedMenuResponses = $this->parallelGetJson( $menuUrlsToFetch );
      if ( ! empty( $menuUrlsToFetch ) ) {
        CBSLogger::products()->info( 'Menu responses prefetched in parallel', [
          'site_id' => $siteId,
          'requested' => count( $menuUrlsToFetch ),
          'fetched'   => count( $this->prefetchedMenuResponses ),
        ] );
      }

      $processedMenuIds = [];
      $matched          = false;

      foreach ($this->areaDayPartMenu as $areasDayPartMenu) {
        if ($areasDayPartMenu->AreaId === $areaId) {
          $matched = true;

          foreach ($siteMenuDefinitionsMenu as $value) {
            if ($value->MenuId == $areasDayPartMenu->MenuId) {

              // Skip menu items processing if this MenuId was already handled by another daypart
              if (in_array($areasDayPartMenu->MenuId, $processedMenuIds, true)) {
                continue;
              }
              $processedMenuIds[] = $areasDayPartMenu->MenuId;

              // Reset circular LinkTo guard per menu to avoid cross-menu carryover.
              $this->visitedLinkToCategories = [];

              $this->currentMenuId = $areasDayPartMenu->MenuId;
              $menuMenuCategoryArr = $value->MenuMenuCategories->MenuMenuCategory;
              $menuResponse = $this->prefetchedMenuResponses[ (string) $areasDayPartMenu->MenuId ]
                ?? $this->retrieveMenuData($siteId, $areasDayPartMenu->MenuId);

              // empty(Data) check added for parity: the sequential path returns
              // WP_Error on empty Data, the parallel path returns the raw object.
              if(is_wp_error($menuResponse) || !is_object($menuResponse) || !$menuResponse->Ok || empty($menuResponse->Data)) {
                CBSLogger::products()->warning('Failed to retrieve menu data', ['site_id' => $siteId, 'menu_id' => $areasDayPartMenu->MenuId]);
                continue;
              }
              $menuData = $menuResponse->Data;

              // ── Menu-hash skip ─────────────────────────────────────────────
              // When this menu's payload is byte-identical to the one processed
              // by the last successful deploy, skip all product/category work.
              // registerSkippedMenuState() re-registers the menu's existing WP
              // state into the cleanup accumulators so the end-of-run deletion
              // steps never touch a skipped menu's products or categories.
              $menuId   = (string) $areasDayPartMenu->MenuId;
              $menuHash = md5( (string) wp_json_encode( $menuData ) );
              $this->pendingMenuHashes[ $menuId ] = $menuHash;

              // registerSkippedMenuState() returns false when the menu's WP
              // state cannot be safely re-registered (e.g. legacy terms with
              // missing meta) — in that case fall through and process the menu
              // normally instead of risking the cleanup deleting its data.
              //
              // menuHasLinkToData() is the menu-level counterpart of the
              // LinkToDataJson guard in computeItemFingerprint(): a LinkToDataJson
              // item assembles its linked subcategory from a separate
              // /menuitemsbycategory/ endpoint that $menuHash does not cover, so a
              // new linked sub-item leaves the parent menu payload byte-identical.
              // Skipping such a menu would silently stale its linked items until a
              // forced full deploy (OE-26396) — never skip when one is present.
              if ( $this->shouldSkipUnchangedMenu( $menuId, $menuHash )
                && ! $this->menuHasLinkToData( $menuData->DataDefinitions->MenuItems->MenuItem ?? null )
                && $this->registerSkippedMenuState( $siteId, $menuId, $value, $menuData->DataDefinitions->MenuItems->MenuItem ?? null ) ) {
                CBSLogger::products()->debug( 'saveCategoryAndItems: menu skipped — hash unchanged', [
                  'site_id' => $siteId,
                  'menu_id' => $menuId,
                ] );
                continue;
              }
              // ───────────────────────────────────────────────────────────────

              $siteMenuItemCategory = $menuData->DataDefinitions->MenuItemCategories->MenuItemCategory;
              $siteMenuItem = $menuData->DataDefinitions->MenuItems->MenuItem;
              $menuPricingLevel = $menuData->DataDefinitions->PricingLevels->PricingLevel;

              // Build per-menu pricing map (replaces rebuild inside buildPricingLevelsArray).
              $this->buildMenuPricingLevelMap( $menuPricingLevel );

              // Build [MenuItemId => item] map so getItemDetails() is an O(1)
              // lookup instead of a full O(n) scan per menu item (O(n²) per menu).
              $this->buildMenuItemMap( $siteMenuItem );

              $this->currentSiteMenuItemCategory = $siteMenuItemCategory;

              CBSLogger::products()->info('Saving categories and items for menu', ['site_id' => $siteId, 'menu_id' => $areasDayPartMenu->MenuId]);
              $this->saveCategoryAndItems($menuMenuCategoryArr, $siteMenuItemCategory, $siteMenuItem, $siteId, $areasDayPartMenu->MenuId);

              if ( $this->cancelDetected ) {
                break; // stop iterating menus for this daypart
              }
            }
          }

          if ( $this->cancelDetected ) {
            break; // stop iterating areaDayPartMenu entries
          }
        }
      }

      // ── Post-write daypart diagnostics ──────────────────────────────────────
      if ( ! $matched && ! empty( $areaId ) ) {
        CBSLogger::products()->warning( 'Deploy: areaid not found in remote AreaDayPartMenus; no daypart menus written.', [
          'site_id' => $siteId,
          'areaId' => $areaId,
          'run_id' => $this->currentRunId,
        ] );
        if ( ! empty( $this->currentRunId ) ) {
          DeployRunRepository::create()->appendEvent(
            $this->currentRunId,
            DeployRunRepository::EVENT_SITE_DAYPART_NOT_MATCHED,
            DeployRunRepository::SEVERITY_WARNING,
            'AreaId not found in remote AreaDayPartMenus; no daypart menus written.',
            [ 'site_id' => $siteId, 'areaId' => $areaId ]
          );
        }
      }

      $newDayparts = $this->daypartMenusRepository->getSiteDaypartMenus($siteId);

      if ( empty( $newDayparts ) ) {
        CBSLogger::products()->error( 'Deploy: no daypart menus in DB after deploy.', [
          'site_id' => $siteId,
          'areaId' => $areaId,
          'run_id' => $this->currentRunId,
        ] );
        if ( ! empty( $this->currentRunId ) ) {
          DeployRunRepository::create()->appendEvent(
            $this->currentRunId,
            DeployRunRepository::EVENT_SITE_DAYPART_EMPTY,
            DeployRunRepository::SEVERITY_ERROR,
            'No daypart menus in DB after deploy.',
            [ 'site_id' => $siteId, 'areaId' => $areaId ]
          );
        }
      } else {
        $beforeMenuIds = array_column( (array) $existingDayparts, 'menuid' );
        $afterMenuIds  = array_column( (array) $newDayparts, 'menuid' );
        if ( $beforeMenuIds !== $afterMenuIds ) {
          CBSLogger::products()->warning( 'Deploy: daypart menus changed.', [
            'site_id' => $siteId,
            'areaId'       => $areaId,
            'run_id'       => $this->currentRunId,
            'before_count' => count( $beforeMenuIds ),
            'after_count'  => count( $afterMenuIds ),
            'before_menuids' => $beforeMenuIds,
            'after_menuids'  => $afterMenuIds,
          ] );
          if ( ! empty( $this->currentRunId ) ) {
            DeployRunRepository::create()->appendEvent(
              $this->currentRunId,
              DeployRunRepository::EVENT_SITE_DAYPART_CHANGED,
              DeployRunRepository::SEVERITY_WARNING,
              'Daypart menus changed during deploy.',
              [
                'site_id' => $siteId,
                'areaId'         => $areaId,
                'before_count'   => count( $beforeMenuIds ),
                'after_count'    => count( $afterMenuIds ),
                'before_menuids' => $beforeMenuIds,
                'after_menuids'  => $afterMenuIds,
              ]
            );
          }
        }
      }

      // Keep the snapshot whenever the rebuild did not verifiably succeed —
      // an empty (or unswapped) daypart table is the exact failure mode the
      // snapshot exists to cover, so deleting it here would destroy the
      // fallback at the moment it is needed.
      $rebuildHealthy = $daypartsSwapped && ! empty( $newDayparts );
      $keepSnapshot   = (bool) carbon_get_theme_option( 'olo_keep_daypart_transient' ) || ! $rebuildHealthy;
      CBSLogger::general()->info( 'Deploy: daypart snapshot cleanup check', [
        'site_id'         => $siteId,
        'rebuild_healthy' => $rebuildHealthy ? 'yes' : 'no',
        'keep_snapshot'   => $keepSnapshot ? 'yes' : 'no',
        'action'          => $keepSnapshot ? 'kept' : 'deleted',
      ] );
      if ( ! $keepSnapshot ) {
        delete_option( 'cbs_daypart_snapshot_' . $siteId );
      }

      if ( ! empty( $this->currentRunId ) ) {
        DeployRunRepository::create()->appendEvent(
          $this->currentRunId,
          DeployRunRepository::EVENT_SITE_DAYPART_SYNCED,
          DeployRunRepository::SEVERITY_INFO,
          'Daypart menus synced.',
          [
            'site_id' => $siteId,
            'areaId'      => $areaId,
            'menu_count'  => count( (array) $newDayparts ),
          ]
        );
      }

    } catch (\Throwable $e) {
      // Catch \Throwable (not just \Exception) so a fatal Error raised
      // mid-rebuild — e.g. a property access on an unexpected API shape — is
      // contained here. The atomic daypart swap above has already committed or
      // rolled back, so the table is never left half-populated, and the caller
      // still releases the deploy lock instead of leaving it stuck "running".
      CBSLogger::products()->error('getWholeSiteData exception', ['message' => $e->getMessage()]);
      return [
        'success' => false,
        'message' => $e->getMessage()
      ];
    }
  }

  /**
   * Save category and items of the category
   *
   * @param array $menuMenuCategoryArr complete array of category with items.
   * @param array $siteMenuItemCategory category list.
   * @param array $siteMenuItem menu items of a category .
   * @param string $siteId site id.
   * @param string $menuid menu id.
   */

  protected function saveCategoryAndItems(
    $menuMenuCategoryArr,
    $siteMenuItemCategory,
    $siteMenuItem,
    $siteId,
    $menuid
  )
  {

    $taxonomy     = 'product_cat';
    $orderBy      = 'name';
    $showCount   = 0;      // 1 for yes, 0 for no
    $padCounts   = 0;      // 1 for yes, 0 for no
    $hierarchical = 1;      // 1 for yes, 0 for no
    $title        = '';
    $empty        = 0;
    $displayOrder = 0;
    $args = array(
      'taxonomy'     => $taxonomy,
      'orderby'      => $orderBy,
      'show_count'   => $showCount,
      'pad_counts'   => $padCounts,
      'hierarchical' => $hierarchical,
      'title_li'     => $title,
      'hide_empty'   => $empty
    );

    $allCategories = get_categories( $args );

    //Get current categories items and store in array
    foreach ($allCategories as $cat) {
      if($cat->category_parent == 0 & $cat->slug != "uncategorized") {
        $this->currentCategories[$cat->term_id] =  html_entity_decode($cat->name);
        $this->currentCategoriesDescription[$cat->term_id] = $cat->description;
      }
    }

    // Hoisted out of the loop — the adapter is stateless, so reuse one instance
    // for every category instead of constructing one per iteration.
    $productAdapter = new WoapiProductAdapter();

    // Bulk-load all product_cat terms for this site into a PHP map keyed by
    // their external menu_item_category_id, so each per-category lookup below is a
    // memory array access rather than a DB meta_query per category.
    //
    // NOTE: This query runs once per saveCategoryAndItems() call (i.e. once per menu).
    // Since the lookup is now site-scoped (not menu-scoped), the same result is
    // re-fetched for each menu on the same site. Moving it outside the per-menu call
    // would save N-1 queries, but terms created during earlier menus in the same sync
    // would be absent from a pre-built cache — those cross-menu terms correctly fall
    // through to findTermByExternalCategoryId() anyway, so correctness is unaffected.
    // Accepted as a known minor inefficiency; revisit if site category counts grow large.
    $preloadedTerms = [];
    $siteTerms = get_terms( array(
      'taxonomy'   => 'product_cat',
      'hide_empty' => false,
      'meta_query' => array(
        array( 'key' => 'site_id', 'value' => $siteId ),
      ),
    ) );
    if ( is_array( $siteTerms ) ) {
      foreach ( $siteTerms as $term ) {
        $extIds   = get_term_meta( $term->term_id, 'menu_item_category_id', false );
        $distinct = array_values( array_unique( array_map( 'strval', $extIds ) ) );

        if ( count( $distinct ) > 1 ) {
          // Term has multiple distinct external IDs — skip the preload map entirely.
          // Both categories would otherwise claim this term via the fast path and
          // fight over it, mixing their products. Falling through to the DB query
          // (findTermByExternalCategoryId) lets each catId resolve to its own term
          // via a precise meta lookup, and the end-of-run cleanup will move any
          // mis-assigned products in the same deploy.
          CBSLogger::products()->warning( 'Skipping preload for term with conflicting menu_item_category_id values', array(
            'term_id'   => $term->term_id,
            'term_name' => $term->name,
            'ext_ids'   => $distinct,
          ) );
          continue;
        }

        foreach ( $extIds as $extId ) {
          if ( ! isset( $preloadedTerms[ $extId ] ) ) {
            $preloadedTerms[ $extId ] = $term;
          }
        }
      }
    }

    //get list categories and menuitems of categories
    foreach ($menuMenuCategoryArr as $menuMenuCategoryValue) {
      if ( $this->cancelDetected ) {
        break;
      }
      $catId        = $menuMenuCategoryValue->MenuItemCategory->MenuItemCategoryId;
      $menuItemsArr = $menuMenuCategoryValue->MenuItemCategory->MenuItemCategoryItems->MenuItemCategoryItem;
      $displayOrder = $menuMenuCategoryValue->MenuItemCategory->DisplayOrder;

      // Initialize with safe defaults before the inner search loop.
      $itemCategoryName = '';
      $itemCategoryDesc = '';
      $imgCat           = null;

      foreach ($siteMenuItemCategory as $siteMenuItemCategoryValue) { //for 1
        if ($catId == $siteMenuItemCategoryValue->MenuItemCategoryId) {
          $itemCategoryName = $siteMenuItemCategoryValue->Name;
          $itemCategoryDesc = $siteMenuItemCategoryValue->Description;
          $images     = $siteMenuItemCategoryValue->MenuItemCategoryMediaItems ?? null;
          $mediaItems = $this->ensureArray( $images ? ( $images->MenuItemCategoryMediaItem ?? [] ) : [] );
          $imgCat     = ! empty( $mediaItems ) ? $mediaItems[0]->MediaItemId : null;
          break;
        }
      } //end for 1

      // Guard: if no matching category definition was found, skip this entry.
      if ($itemCategoryName === '') {
        CBSLogger::products()->warning('Category definition not found in siteMenuItemCategory, skipping', ['catId' => $catId]);
        continue;
      }

      // Primary lookup: resolve existing WC term by external category ID.
      // Memory map is checked first; DB query is a safety fallback for any gap.
      $existingTerm = $preloadedTerms[ $catId ] ?? $this->findTermByExternalCategoryId( $siteId, $catId );

      if ( $existingTerm instanceof \WP_Term ) {
        $catID = $existingTerm->term_id;

        if ( ! array_key_exists( $catID, $this->savedCategoriesPerMenu ) ) {
          $this->savedCategoriesPerMenu[ $catID ] = $itemCategoryName;
        }
        $this->deployedCategoryTermIdsBySiteMenu[ $siteId ][ (string) $menuid ][] = (int) $catID;
        unset( $this->currentCategories[ $catID ] );
        unset( $this->currentCategoriesDescription[ $catID ] );

        // Sync name and/or description in-place when the API value changed.
        // No duplicate term is created — only the existing term is updated.
        $needsUpdate = [];
        $storedName  = trim( $existingTerm->name );
        if ( $storedName !== $itemCategoryName ) {
          $needsUpdate['name']        = $itemCategoryName;
          $needsUpdate['slug']        = sanitize_title( $itemCategoryName );
          $needsUpdate['description'] = $itemCategoryDesc;
          CBSLogger::products()->info( 'Renaming category in-place', array(
            'term_id'  => $catID,
            'old_name' => $storedName,
            'new_name' => $itemCategoryName,
          ) );
        } elseif ( trim( $existingTerm->description ) !== $itemCategoryDesc ) {
          $needsUpdate['description'] = $itemCategoryDesc;
        }
        if ( ! empty( $needsUpdate ) ) {
          wp_update_term( $catID, 'product_cat', $needsUpdate );
        }

        // Enforce exactly one menu_item_category_id row on this term.
        // Corrupted terms can accumulate extra rows; since we resolved this term
        // by $catId we know that is the single correct value.
        $storedIds = get_term_meta( $catID, 'menu_item_category_id', false );
        if ( ! ( count( $storedIds ) === 1 && (string) $storedIds[0] === (string) $catId ) ) {
          delete_term_meta( $catID, 'menu_item_category_id' );
          add_term_meta( $catID, 'menu_item_category_id', $catId, false );
          CBSLogger::products()->warning( 'Enforced single menu_item_category_id on term', array(
            'term_id'    => $catID,
            'old_ids'    => $storedIds,
            'correct_id' => $catId,
          ) );
        }

        update_term_meta( $catID, 'order', $displayOrder );

        // Attach this menu to the term if it is not already listed.
        // A term is shared across menus — menu_id is multi-value meta.
        $attachedMenuIds = get_term_meta( $catID, 'menu_id', false );
        if ( ! in_array( (string) $menuid, array_map( 'strval', $attachedMenuIds ), true ) ) {
          add_term_meta( $catID, 'menu_id', $menuid, false );
        }

        // Stamp this site's site_id onto the shared term so shortcode queries
        // (which filter by site_id + menu_id) can find it for this site too.
        $attachedSiteIds = get_term_meta( $catID, 'site_id', false );
        if ( ! in_array( (string) $siteId, array_map( 'strval', $attachedSiteIds ), true ) ) {
          add_term_meta( $catID, 'site_id', $siteId, false );
        }

        if ( $imgCat && ! $this->skipImages ) {
          $imgID = null;
          if ( ! $this->getAttachmentById( $imgCat ) ) {
            $pictureUrl = $this->retrieveAndCreateImage( $imgCat );
            if ( $pictureUrl ) {
              $this->uploadImage( $pictureUrl, null, $imgCat, 'src' ) ?? null;
              $imgID = ( $this->getAttachmentById( $imgCat ) )->ID;
            }
          } else {
            $imgID = ( $this->getAttachmentById( $imgCat ) )->ID;
          }
          if ( ! metadata_exists( 'term', $catID, 'thumbnail_id' ) ) {
            add_term_meta( $catID, 'thumbnail_id', $imgID, true );
          } else {
            if ( $imgID ) {
              update_term_meta( $catID, 'thumbnail_id', $imgID );
              CBSLogger::products()->debug( 'Updating category thumbnail', array( 'img_id' => $imgID, 'cat_id' => $catID ) );
            }
          }
        }

      } else {
        // No term found by external ID — create a new term.
        // catId-suffixed slug guarantees uniqueness even when display names collapse
        // to the same slug after sanitize_title() (e.g. "Appetizers." vs "Appetizers").
        $catID      = null;
        $uniqueSlug = sanitize_title( $itemCategoryName ) . '-' . substr( str_replace( '-', '', $catId ), 0, 8 );
        $catData    = wp_insert_term(
          $itemCategoryName,
          'product_cat',
          array(
            'description' => $itemCategoryDesc,
            'slug'        => $uniqueSlug,
          )
        );
        if ( is_wp_error( $catData ) ) {
          CBSLogger::products()->error( 'wp_insert_term failed for new category', array(
            'catId'   => $catId,
            'name'    => $itemCategoryName,
            'error'   => $catData->get_error_message(),
            'site_id' => $siteId,
            'menu_id' => $menuid,
          ) );
          continue;
        }
        $catID = $catData['term_id'];

        $imgID = null;
        if ( $imgCat && ! $this->skipImages ) {
          if ( ! $this->getAttachmentById( $imgCat ) ) {
            $pictureUrl = $this->retrieveAndCreateImage( $imgCat );
            if ( $pictureUrl ) {
              $this->uploadImage( $pictureUrl, null, $imgCat, 'src' ) ?? null;
              $imgID = ( $this->getAttachmentById( $imgCat ) )->ID;
            }
          } else {
            $imgID = ( $this->getAttachmentById( $imgCat ) )->ID;
          }
          if ( ! metadata_exists( 'term', $catID, 'thumbnail_id' ) ) {
            add_term_meta( $catID, 'thumbnail_id', $imgID, true );
          }
        }
        $this->safeAddCategoryIdMeta( $catID, (string) $catId, (string) $siteId, (string) $menuid );
        add_term_meta( $catID, 'site_id', $siteId,       false );
        add_term_meta( $catID, 'menu_id', $menuid,       false );
        add_term_meta( $catID, 'order',   $displayOrder, false );

        if ( ! array_key_exists( $catID, $this->savedCategoriesPerMenu ) ) {
          $this->savedCategoriesPerMenu[ $catID ] = $itemCategoryName;
        }
        $this->deployedCategoryTermIdsBySiteMenu[ $siteId ][ (string) $menuid ][] = (int) $catID;
        unset( $this->currentCategories[ $catID ] );
        unset( $this->currentCategoriesDescription[ $catID ] );

      } // end else (no ID match)

      // Backfill scope meta for pre-existing LinkTo children under this category.
      // Legacy terms without site_id/menu_id escape per-menu stale cleanup.
      $this->ensureLinkToChildrenScopedToMenu( (int) $catID, (string) $siteId, (string) $menuid );

      $menuItems        = $productAdapter->toMenuItemsObject( $menuItemsArr );
      $unavailableItems = $this->getUnavailableItemsForSite( $siteId );

      $_catStatsSnapshot = $this->siteStats;
      $this->siteStats['categories_total']++;
      $this->processMenuItems( $menuItems, $siteMenuItem, $siteId, $catId, $catID, $unavailableItems );
      CBSLogger::products()->debug( 'deploy: category items processed', [
        'site_id'                   => $siteId,
        'menu_id'                   => $this->currentMenuId,
        'term_id'                   => $catID,
        'items_total_in_api'        => count( (array) $menuItems ),
        'items_active'              => ( $this->siteStats['items_created'] - $_catStatsSnapshot['items_created'] )
                                     + ( $this->siteStats['items_updated'] - $_catStatsSnapshot['items_updated'] )
                                     + ( $this->siteStats['items_skipped_fingerprint'] - $_catStatsSnapshot['items_skipped_fingerprint'] ),
        'items_inactive'            => $this->siteStats['items_inactive'] - $_catStatsSnapshot['items_inactive'],
        'items_skipped_fingerprint' => $this->siteStats['items_skipped_fingerprint'] - $_catStatsSnapshot['items_skipped_fingerprint'],
      ] );

    }

  }

  /**
   * Search for a key description in a array
   *
   * @param string $name.
   * @param array $array.
   */
  protected function searchDescription($name, $array) {
    foreach ($array as $val) {
      if ($val->Name === $name) {
        return $val->Description;
      }
    }
    return null;
  }

  /**
   * Fetch data from api
   *
   * @param string $url url of api endpoints with required parameters.
   * @param string $token token provided.
   * @param string $tokenType token type.
   * @return array
   */
  protected function getApiData($url, $token, $tokenType)
  {
    CBSLogger::products()->debug('Fetching API data', ['url' => $url]);

    $response = \CBSNorthStar\Helpers\WoapiRequest::create()->get($url, [
      'token'     => $token,
      'tokenType' => $tokenType
    ]);

    if (!empty($response->Data)) {
      return $response;
    }

    CBSLogger::products()->warning('API returned empty data', ['url' => $url]);
    return new \WP_Error('api_empty_data', 'API returned empty Data for: ' . $url);
  }

  /**
   * Fetch item details
   *
   * @param array $siteMenuItem menu items of a category .
   * @param string $menuItemId menu id.
   * @param string $siteId site id.
   * @return array an array of item details.
 */
 protected function getItemDetails($siteMenuItem, $menuItemId, $siteId , $catId)
  {
    $itemName = '';
    $itemDesc = '';
    $isActive= true;
    $numberOfPlacements = '';
    $type = '';
    $comboQualifierIds = array();
    $pricinglevelId = '';
    $mediaItemId = '';
    $price = '';
    $displayOrder = 0;
    $productComponents = array();
    $componentsArr = array();
    $productServingOptions = array();
    $completeItemDetailArr = array();
    $linkToCategory = null;
    $linkToMetadata = [];
    $servingOptions = array();

    // O(1) lookup via the per-menu map (was a full linear scan of $siteMenuItem).
    $site_MenuItem_value = $this->menuItemMap[ (string) $menuItemId ] ?? null;
    if ($site_MenuItem_value !== null) {

        $itemName = $site_MenuItem_value->Name;
        $isActive= $site_MenuItem_value->IsActive;

        $itemDesc = $site_MenuItem_value->Description;
        $displayOrder = $site_MenuItem_value->DisplayOrder;
        $numberOfPlacements = $site_MenuItem_value->NumberOfPlacements;
        $type = $site_MenuItem_value->Type;
        $comboQualifierIds = $site_MenuItem_value->ComboQualifierIds;
        // ensureArray(): WOAPI serializes a one-member PricingLevels->PricingLevel
        // collection as a bare object rather than an array (confirmed at
        // RemoteRequestAbstract::getResponse() — json_decode() with no `true`
        // flag, so a single element decodes as stdClass, not a 1-element array).
        // Same normalization already applied to this exact shape in
        // computeItemFingerprint().
        $pricinglevelId = $this->ensureArray( $site_MenuItem_value->PricingLevels->PricingLevel ?? [] )[0]->PricingLevelId ?? null;
        $mediaItemId = $site_MenuItem_value->MenuItemMediaItems->MenuItemMediaItem[0]->MediaItemId;

        // Prefer the PricingLevelId-resolved price (same resolution already used for
        // components/serving options) whenever it yields a real, non-zero value.
        // Items reached via a LinkToDataJson category recursion come from
        // /menuitemsbycategory/, whose MenuItem objects carry no Price field at all —
        // only a PricingLevelId — so relying on ->Price alone leaves them permanently
        // stale. Falls back to the direct Price field untouched when resolution fails,
        // so this never turns a working price into $0.
        $directPrice   = $site_MenuItem_value->Price ?? null;
        $resolvedPrice = $pricinglevelId !== null ? $this->getPricelevelId($pricinglevelId) : 0;
        $price         = ($resolvedPrice > 0) ? $resolvedPrice : ($directPrice ?? 0);
        $price1 = sprintf($price);

        if($site_MenuItem_value->LinkToDataJson){
          $linkToCategory = $site_MenuItem_value->LinkToDataJson->MenuItemCategoryId;
          $addedLinkToCategoryToVisited = false;

          // Guard against circular LinkToDataJson references
          if (in_array($linkToCategory, $this->visitedLinkToCategories, true)) {
            CBSLogger::products()->warning('Circular LinkToDataJson detected, skipping', [
              'menuItemId' => $menuItemId,
              'linkToCategory' => $linkToCategory,
            ]);
          } else {
            $this->visitedLinkToCategories[] = $linkToCategory;
            $addedLinkToCategoryToVisited = true;

            $subcategory = (new Categories())->createLinktoCategory($catId, $site_MenuItem_value->MenuItemId, $itemName);

            if ( ! ( $subcategory instanceof \WP_Term ) ) {
              CBSLogger::products()->warning( 'createLinktoCategory returned no term — skipping linkToCategory item', array(
                'menuItemId'       => $site_MenuItem_value->MenuItemId,
                'parentCategoryId' => $catId,
                'itemName'         => $itemName,
              ) );
            } else {

            // LinkTo subcategories are shared taxonomy terms too; stamp the current
            // site/menu memberships so per-menu stale cleanup can include them.
            $currentMenuId = (string) $this->currentMenuId;
            if ( $currentMenuId !== '' ) {
                $attachedMenuIds = get_term_meta( $subcategory->term_id, 'menu_id', false );
                if ( ! in_array( $currentMenuId, array_map( 'strval', $attachedMenuIds ), true ) ) {
                    add_term_meta( $subcategory->term_id, 'menu_id', $currentMenuId, false );
                }

                $attachedSiteIds = get_term_meta( $subcategory->term_id, 'site_id', false );
                if ( ! in_array( (string) $siteId, array_map( 'strval', $attachedSiteIds ), true ) ) {
                    add_term_meta( $subcategory->term_id, 'site_id', $siteId, false );
                }

                $this->deployedCategoryTermIdsBySiteMenu[ $siteId ][ $currentMenuId ][] = (int) $subcategory->term_id;
            }

            if (!array_key_exists($subcategory->term_id, $this->savedCategoriesPerMenu)) {
                $this->savedCategoriesPerMenu[$subcategory->term_id] = $itemName;
            }
            $linkToMetadata = [
                'slug' => $subcategory->slug,
                'name' => $subcategory->name,
            ];

            $url = '/menuitemsbycategory/'. $linkToCategory;
            $getCategoryItems = (new Connection)->getData($siteId, $url, 'Token');

            $categoryDataItems = $this->ensureArray( $getCategoryItems ? ( $getCategoryItems->Data ?? null ) : null );
            if ( ! empty( $categoryDataItems ) ) {
                $menuItems = $this->ensureArray( $categoryDataItems[0]->MenuItems ?? null );

                // OE-26552: a LinkTo subcategory is filled from the linked category's
                // /menuitemsbycategory/ endpoint, which does NOT reflect this site's per-item
                // disable. processMenuItems() judges active state via $this->menuItemMap (the
                // site's served menu) and isMenuItemActive(null) === true, so a disabled item
                // — absent from the map, or present with IsActive=false — would be treated as
                // active here, kept in the deployed set (its _siteid never stripped) and marked
                // expected on the subcategory term (never removed by removeStaleTermProducts).
                // It then survives under the LinkTo subcategory even after it was removed from
                // its real category. Keep only items the site actually serves: present AND
                // active in this run's menu map.
                $menuItems = array_values( array_filter( $menuItems, function ( $linkedItem ) {
                    $mapped = $this->menuItemMap[ (string) ( $linkedItem->MenuItemId ?? '' ) ] ?? null;
                    return $mapped !== null && WoapiProductAdapter::isMenuItemActive( $mapped );
                } ) );

                // Update totalProducts BEFORE processMenuItems increments processedProducts,
                // so the progress bar never shows processedProducts > totalProducts.
                if ( ! empty( $this->currentRunId ) ) {
                    $prevTotal = $this->progressTotalProducts;
                    if ( is_array( $menuItems ) ) {
                        $this->progressTotalProducts += count( $menuItems );
                    } elseif ( is_object( $menuItems ) ) {
                        $this->progressTotalProducts += 1;
                    }
                    CBSLogger::products()->debug( 'getItemDetails: LinkToDataJson adds to totalProducts', [
                        'run_id'          => $this->currentRunId,
                        'prev_total'      => $prevTotal,
                        'added'           => $this->progressTotalProducts - $prevTotal,
                        'new_total'       => $this->progressTotalProducts,
                        'menuItems_type'  => gettype( $menuItems ),
                        'menuItems_count' => is_array( $menuItems ) ? count( $menuItems ) : ( is_object( $menuItems ) ? 1 : 0 ),
                    ] );
                    DeployProgress::update( $this->currentRunId, [ 'totalProducts' => $this->progressTotalProducts ] );
                }

                $unavailableItems = $this->getUnavailableItemsForSite($siteId);

                $this->processMenuItems($menuItems, $siteMenuItem, $siteId, $catId, $subcategory->term_id, $unavailableItems);
            }
            } // end subcategory WP_Term guard
          }

          // Pop current LinkTo category so only the active recursion path is tracked.
          if ($addedLinkToCategoryToVisited) {
            $lastIndex = array_key_last($this->visitedLinkToCategories);
            if ($lastIndex !== null && $this->visitedLinkToCategories[$lastIndex] === $linkToCategory) {
              unset($this->visitedLinkToCategories[$lastIndex]);
            } else {
              $removeIndex = array_search($linkToCategory, $this->visitedLinkToCategories, true);
              if ($removeIndex !== false) {
                unset($this->visitedLinkToCategories[$removeIndex]);
              }
            }
            $this->visitedLinkToCategories = array_values($this->visitedLinkToCategories);
          }

        }
        $productComponents = $site_MenuItem_value->Components;
        if(!empty($productComponents)){
          $componentsArr = $this->getItemComponentsDetails($productComponents, $siteId);
        }
        $productServingOptions = $site_MenuItem_value->MenuItemComponentServingOptions;

        if(!empty($productServingOptions)){
          $servingOptions = $this->getServingOptionsData($productServingOptions, $siteId);
        }

        $completeItemDetailArr['MenuItemId'] = $site_MenuItem_value->MenuItemId;
        $completeItemDetailArr['siteId'] = $siteId;
        $completeItemDetailArr['catId'] = $catId;
        $completeItemDetailArr['itemName'] = $this->sanitizeApostrophes($itemName);
        $completeItemDetailArr['itemDesc'] = $this->sanitizeApostrophes($itemDesc);
        $completeItemDetailArr['numberOfPlacements'] = $numberOfPlacements;
        $completeItemDetailArr['type'] = $type;
        $completeItemDetailArr['comboQualifierIds'] = $comboQualifierIds;
        $completeItemDetailArr['mediaItemId'] = $mediaItemId;
        $completeItemDetailArr['price'] = $price1;
        $completeItemDetailArr['displayOrder'] = $displayOrder;
        $completeItemDetailArr['components_data'] = $componentsArr;
        $completeItemDetailArr['servingOptionsData'] = $servingOptions;
        $completeItemDetailArr['linkTo'] =  json_encode($linkToMetadata, JSON_UNESCAPED_UNICODE);

        // product-active-date-window (design.md Decision 5): a separate, newly
        // added /menuitems fetch — this item's node in $site_MenuItem_value (the
        // /menu/{menuId} payload) carries no date fields at all. Missing from the
        // lookup (item absent from /menuitems, or /menuitems unreachable this run)
        // means both stay null, which custombiz*SimpleProduct() below already
        // treats as "no constraint" — the same fail-open behavior as an explicit
        // null StartDate/EndDate.
        $menuItemDates = $this->getMenuItemDatesForSite( $siteId )[ (string) $menuItemId ] ?? null;
        $completeItemDetailArr['activeStart'] = $menuItemDates
          ? WoapiProductAdapter::parseActiveDate( $menuItemDates->StartDate ?? null, $siteId )
          : null;
        $completeItemDetailArr['activeStop'] = $menuItemDates
          ? WoapiProductAdapter::parseActiveDate( $menuItemDates->EndDate ?? null, $siteId )
          : null;
        // DIAGNOSTIC (OE — /menuitems visibility): confirms whether this item was
        // present in this site's /menuitems lookup at all, and how its raw
        // StartDate/EndDate parsed — the direct answer to "is the date data
        // coming through for this site".
        CBSLogger::products()->debug( 'getItemDetails: active-date lookup', [
            'site_id'          => $siteId,
            'item_id'          => $menuItemId,
            'found_in_menuitems' => null !== $menuItemDates,
            'raw_start'        => $menuItemDates->StartDate ?? null,
            'raw_stop'         => $menuItemDates->EndDate ?? null,
            'parsed_start'     => $completeItemDetailArr['activeStart'],
            'parsed_stop'      => $completeItemDetailArr['activeStop'],
        ] );

        if(!$isActive)
        {
          $completeItemDetailArr['status'] = "trash";
        }
        else{
          $completeItemDetailArr['status']="publish";
        }

        return $completeItemDetailArr;

    }

    // DIAGNOSTIC (OE — cross-site fingerprint / category-attachment investigation):
    // the item was not found in this site's menuItemMap (built from THIS site's
    // /menu/{menuId} payload) at all — meaning it never reaches the fingerprint
    // check, buildProductForMenuItem(), or either write path below for this site.
    // If WOAPI's raw feed for this site DOES include the item (confirm separately
    // against /sites/{siteId}/menu/{menuId}), this log firing means something
    // upstream of getItemDetails() (e.g. the category's item list passed into
    // processMenuItems(), or menuHasLinkToData()/hash-skip routing) filtered it
    // out before this lookup ever ran.
    CBSLogger::products()->warning( 'getItemDetails: item not found in this site\'s menuItemMap', [
        'site_id'      => $siteId,
        'menu_id'      => $this->currentMenuId,
        'item_id'      => $menuItemId,
        'cat_id'       => $catId,
        'map_size'     => count( $this->menuItemMap ),
    ] );

    return null;
  }


  private function getServingOptionsData($productServingOptions, $siteId)
  {
    if(empty($productServingOptions)){
      return [];
    }

    $servingOptionsData = [];

    foreach ($productServingOptions as $productServingOptions_value) {
      $servingOptionId = $productServingOptions_value->ServingOptionId;

      // ensureArray(): the existing is_array() check only covers PricingLevels
      // itself being an empty JSON array; it doesn't normalize the case where
      // ->PricingLevel collapses to a bare object for a serving option with
      // exactly one pricing level — same WOAPI single-item-collapse shape as
      // getItemDetails()/getcomponentDetail(), and the same crash risk against
      // buildPricingLevelsArray()'s `array` type hint.
      $pricingLevelsRaw = $productServingOptions_value->ServingOption->PricingLevels ?? null;
      $pricinLevelsData = $this->ensureArray( is_array($pricingLevelsRaw) ? $pricingLevelsRaw : ($pricingLevelsRaw->PricingLevel ?? []) );
      $priceId = $pricinLevelsData[0]->PricingLevelId ?? null;
      $servingOptionPricingLevels = $this->buildPricingLevelsArray($pricinLevelsData);
      $servingOptionPrice = $this->getBasePriceFromLevels($servingOptionPricingLevels);

      if ( ! isset( $this->servingOptionMap[ $servingOptionId ] ) ) {
        continue;
      }

      $sites_ServingOption_value = $this->servingOptionMap[ $servingOptionId ];
      $servingOptionName         = $sites_ServingOption_value->Name;
      $servingOptionCatId        = $sites_ServingOption_value->ServingOptionCategory->ServingOptionCategoryId;
      $servingOptionDisplayOrder = $sites_ServingOption_value->DisplayOrder;
      $servingOptionCategory     = $this->getServingoptionCategoryDetails($servingOptionCatId);

      $servingOptionsData[$servingOptionCatId][] = [
        'servingOptionName'          => $servingOptionName,
        'servingOptionPrice'         => $servingOptionPrice,
        'servingOptionPricingLevels' => $servingOptionPricingLevels,
        'pricingLevelsId'            => $priceId,
        'isDefault'                  => $productServingOptions_value->IsDefault ? '1' : '0',
        'siteId'                     => $siteId,
        'servingOptionId'            => $servingOptionId,
        'categoryName'               => $servingOptionCategory['name'],
        'displayOrder'               => $servingOptionDisplayOrder,
      ];
    }

    return $servingOptionsData;
  }

  /**
   * Fetch variation category details
   *
   * @param string $servingoptioncatid serving option category id .
   * @return string.
   */

  protected function getServingoptionCategoryDetails($servingoptioncatid)
  {
    if ( isset( $this->servingOptionCatMap[ $servingoptioncatid ] ) ) {
      $cat = $this->servingOptionCatMap[ $servingoptioncatid ];
      return [
        'name'   => $cat->Name,
        'minreq' => $cat->MinRequired,
      ];
    }
    return ['name' => '', 'minreq' => 0];
  }

  /**
   * Fetch complete component details of the item
   *
   * @param array $productComponents array of prodcut componets .
   * @param string $siteId site id.
   * @return array.
   */
  protected function getItemComponentsDetails($productComponents, $siteId)
  {
    try {
      $componentsNew = array();

      if ( empty( $productComponents ) ) {
        return $componentsNew;
      }

      // Display-order lookups derived from the pre-indexed site maps.
      $categoryOrder  = array_map( fn( $cat )  => $cat->DisplayOrder,  $this->componentCategoryMap );
      $componentOrder = array_map( fn( $comp ) => $comp->DisplayOrder, $this->componentMap );

      // Group this product's components by category in ONE pass, rather than
      // re-scanning the full $productComponents list for every site category
      // (the old shape was O(siteCategories × productComponents²)).
      $byCategory = [];
      foreach ( $productComponents as $productComponent ) {
        $categoryId = $productComponent->ComponentCategory->ComponentCategoryId;
        $byCategory[ $categoryId ][] = $productComponent;
      }

      // Emit categories in their global display order.
      uksort( $byCategory, fn( $a, $b ) =>
        ( $categoryOrder[ $a ] ?? PHP_INT_MAX ) <=> ( $categoryOrder[ $b ] ?? PHP_INT_MAX )
      );

      foreach ( $byCategory as $categoryId => $categoryComponents ) {
        $componentCategoryInfo = $this->getcomponentcategoryDetail( $categoryId );
        if ( empty( $componentCategoryInfo ) ) {
          continue;
        }

        // Order components within the category by their display order.
        usort( $categoryComponents, fn( $a, $b ) =>
          ( $componentOrder[ $a->ComponentId ] ?? PHP_INT_MAX ) <=> ( $componentOrder[ $b->ComponentId ] ?? PHP_INT_MAX )
        );

        $built = [];
        foreach ( $categoryComponents as $productComponent ) {
          $componentId      = $productComponent->ComponentId;
          $isDefault        = $productComponent->IsDefault;
          $ruleId           = $productComponent->Rules[0]->RuleId ?? null;
          $componentInfoNew = $this->getcomponentDetail( $componentId, $siteId, $isDefault, $ruleId );

          if ( empty( $componentInfoNew ) ) {
            CBSLogger::products()->info( 'Component inactive or missing — skipped', [ 'componentId' => $componentId ] );
            continue;
          }

          $component = array_map( function ( $v ) {
            return is_string( $v ) ? str_replace( '\\', '/', $v ) : $v;
          }, $componentInfoNew );
          $component['categoryName']       = $componentCategoryInfo['categoryName'];
          $component['numberOfPlacements'] = $componentCategoryInfo['numberOfPlacements'];

          $built[] = $component;
        }

        if ( ! empty( $built ) ) {
          $componentsNew[ $componentCategoryInfo['categoryId'] ] = $built;
        }
      }

      return $componentsNew;
    } catch (Exception $e) {
      CBSLogger::products()->error('getItemComponentsDetails exception', ['message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Fetch component category details
   *
   * @param string $componentcateID component category id .
   * @param string $siteId site id.
   */
  protected function getcomponentcategoryDetail($componentcateID)
  {
    try {
      if ( isset( $this->componentCategoryMap[ $componentcateID ] ) ) {
        $cat = $this->componentCategoryMap[ $componentcateID ];
        return [
          'categoryName'       => $cat->Name,
          'categoryId'         => $componentcateID,
          'numberOfPlacements' => $cat->NumberOfPlacements,
        ];
      }
    } catch (Exception $e) {
      CBSLogger::products()->error('getcomponentcategoryDetail exception', ['message' => $e->getMessage()]);
    }
  }

  /**
   * Fetch component details
   *
   * @param string $componentId component id .
   * @param string $siteId site id.
   * @param string $isDefault is compoent default .
   * @param string $ruleId rule id for component .
   */

  protected function getcomponentDetail($componentId, $siteId, $isDefault, $ruleId){
    try {
      $isActiveComponent = true;
      $componentName = '';
      $componentprice = 0;
      $componentPricingLevels = [];
      $componentServingOptions = [];
      $componentMediaId = null;

      if ( isset( $this->componentMap[ $componentId ] ) ) {
        $componentInfo_value = $this->componentMap[ $componentId ];

        $componentName = $this->sanitizeApostrophes($componentInfo_value->Name);

        // ensureArray(): a component with exactly one pricing level decodes from
        // WOAPI as a bare object, not an array — passing that directly would throw
        // a fatal TypeError against buildPricingLevelsArray()'s `array` type hint
        // (not caught by the surrounding catch(Exception), since TypeError extends
        // Error). Same normalization as the PricingLevels->PricingLevel reads in
        // getItemDetails() and buildMenuPricingLevelMap().
        $pricingLevelsData      = $this->ensureArray( $componentInfo_value->PricingLevels->PricingLevel ?? [] );
        $componentPricingLevels = $this->buildPricingLevelsArray($pricingLevelsData);
        $componentprice         = $this->getBasePriceFromLevels($componentPricingLevels);
        $componentServingOptions = $this->getServingOptionsData($componentInfo_value->MenuItemComponentServingOptions, $siteId);

        if ($componentInfo_value->IsActive === false) {
          $isActiveComponent = false;
        }
        $componentMediaId = $componentInfo_value->ComponentMediaItems->ComponentMediaItem[0]->MediaItemId ?? null;
      }

      if ($isActiveComponent && $componentName !== '') {
        return [
          'componentName' => $componentName,
          'componentprice' => $componentprice,
          'pricingLevels' => $componentPricingLevels,
          'isDefault' => $isDefault,
          'ruleId' => $ruleId,
          'siteId' => $siteId,
          'componentId' => $componentId,
          'mediaItemId' => $componentMediaId,
          'componentServingOptions' => $componentServingOptions,
          'outofstock' => isset( $this->getUnavailableComponentsSetForSite( $siteId )[ (string) $componentId ] )
        ];
      } else {
        return null;
      }
    } catch (Exception $e) {
      CBSLogger::products()->error('getcomponentDetail exception', ['message' => $e->getMessage()]);
      return null;
    }
  }

  /**
   * Fetch price by price level id
   *
   * @param string $componentId component id .
   * @param string $pricinglevelId price level id.

   * @return int

   */
  public function getPricelevelId($pricinglevelId)
  {
    if ($pricinglevelId === null) {
      return 0;
    }

    return isset( $this->pricingLevelMap[ $pricinglevelId ] )
      ? $this->pricingLevelMap[ $pricinglevelId ]->Price
      : 0;
  } 

  /**
   * Build an array of pricing levels with Layer and Price.
   *
   * Maps pricing level IDs to their full pricing data from global definitions.
   * Returns an array indexed by Layer for easy price lookup by position.
   *
   * @param array $itemPricingLevels Array of pricing level objects from component or serving option.
   * @return array<int, array{layer: int, price: float, pricingLevelId: string}>
   */
  protected function buildPricingLevelsArray(array $itemPricingLevels): array
  {
    $pricingLevels = [];

    foreach ($itemPricingLevels as $itemLevel) {
      $pricingLevelId = $itemLevel->PricingLevelId ?? null;
      if ($pricingLevelId === null) {
        continue;
      }

      if (isset($this->pricingLevelMap[$pricingLevelId])) {
        $levelData = $this->pricingLevelMap[$pricingLevelId];
        $layer = (int) ($levelData->Layer ?? 1);
        $pricingLevels[$layer] = [
          'layer'          => $layer,
          'price'          => (float) ($levelData->Price ?? 0),
          'pricingLevelId' => $pricingLevelId,
        ];
      } else {
        // The layer silently vanishes from _components when its definition is
        // absent from this menu's PricingLevels — keep that behavior, but make
        // it observable so a missing layer can be traced to the payload
        // instead of the skip tiers.
        CBSLogger::products()->warning('buildPricingLevelsArray: referenced PricingLevelId missing from per-menu map — layer dropped', [
          'pricing_level_id' => (string) $pricingLevelId,
          'menu_id'          => (string) $this->currentMenuId,
          'resolved_levels'  => count($pricingLevels),
        ]);
      }
    }

    ksort($pricingLevels);

    return $pricingLevels;
  }

  /**
   * Get base price from pricing levels array (Layer 1 or first available).
   *
   * @param array $pricingLevels Array of pricing levels indexed by layer.
   * @return float Base price for the component.
   */
  protected function getBasePriceFromLevels(array $pricingLevels): float
  {
    if (isset($pricingLevels[1])) {
      return $pricingLevels[1]['price'];
    }

    return 0.0;
  }

  /**
   * Fetch image url of the product
   *
   * @param string $mediaItemId media Item Id.
   * @param string $postId post Item Id.
   * @param string $ItemId media Item Id.
 * @return string.
 */
  protected function getImage( $mediaItemId, $postId, $itemid, $isPost = true ): ?int
  {
    $existingRecordPicture = $this->imageExistInRecord( $itemid );
    $recordMediaItemId     = $existingRecordPicture[0]->mediaitemid ?? null;
    $recordPictureId       = $existingRecordPicture[0]->pictureid   ?? null;
    $recordExpectedBytes   = $existingRecordPicture[0]->expected_bytes ?? null;

    // Skip: same external ID and WP attachment still exists in the media library.
    if ( $recordMediaItemId === $mediaItemId && $recordPictureId ) {
      $attachmentUrl = wp_get_attachment_url( (int) $recordPictureId );
      if ( $attachmentUrl ) {
        $verified = $this->verifyRecordedImageIntegrity(
          (int) $recordPictureId,
          $recordExpectedBytes !== null ? (int) $recordExpectedBytes : null,
          $itemid
        );

        if ( $verified !== false ) {
          $this->siteStats['images_unchanged']++;
          return (int) $recordPictureId;
        }

        // Local integrity check failed (byte-size mismatch, or a legacy row's
        // one-time structural check failed) — treat as unrecorded and repair
        // just this one item below. Does not touch ECM for any other item.
        $this->siteStats['images_repaired'] = ( $this->siteStats['images_repaired'] ?? 0 ) + 1;
        CBSLogger::products()->warning( 'Recorded image failed integrity verification — repairing', [
          'itemid'          => $itemid,
          'mediaItemId'     => $mediaItemId,
          'recordPictureId' => $recordPictureId,
        ] );
      } else {
        // Attachment was deleted from WP media — fall through to re-sideload.
        CBSLogger::products()->warning( 'WP attachment missing from media library, will re-sideload', [
          'mediaItemId'     => $mediaItemId,
          'recordPictureId' => $recordPictureId,
        ] );
      }
    }

    // In-memory per-run cache: avoid downloading the same image more than once per deploy.
    if ( isset( $this->sideloadedImages[ $mediaItemId ] ) ) {
      return $this->sideloadedImages[ $mediaItemId ];
    }

    $raw     = str_replace( '-', '', $mediaItemId );
    $fileUrl = get_site_url() . '/' . self::PLUGIN_PATH . self::IMG_PRODUCTS . $raw . '.PNG';

    // Disk cache: another product earlier in this run already downloaded + optimized this file.
    $localFile = plugin_dir_path( CBS_PLUGIN_FILE ) . self::IMG_PRODUCTS . $raw . '.PNG';
    if ( file_exists( $localFile ) ) {
      $attachmentId = $this->sideloadImageUrl( $fileUrl, (int) $postId );
      if ( $attachmentId ) {
        $this->sideloadedImages[ $mediaItemId ] = $attachmentId;
        $this->siteStats['images_resideloaded']++;
      }
      return $attachmentId;
    }


    try {
      $response = $this->getMediaItemFromAPI( $mediaItemId );
      $imgSrc   = $this->storeAndOptimizeImage( $response );
      if ( empty( $imgSrc ) ) {
        return null;
      }
      $sideloadUrl  = get_site_url() . '/' . self::PLUGIN_PATH . self::IMG_PRODUCTS . $imgSrc . '.PNG';
      $attachmentId = $this->sideloadImageUrl( $sideloadUrl, (int) $postId );
      if ( $attachmentId ) {
        $this->sideloadedImages[ $mediaItemId ] = $attachmentId;
        $this->siteStats['images_uploaded']++;
      }
      return $attachmentId;
    } catch ( Exception $e ) {
      CBSLogger::products()->error( 'getImage exception fetching media item', [ 'message' => $e->getMessage() ] );
      return null;
    }
  }

  /**
   * Cheap local verification of a previously-recorded image before trusting
   * the deploy's "unchanged" skip. No network call — only reads the file
   * already on disk. See openspec/changes/harden-deploy-image-resilience.
   *
   * @param int      $attachmentId  WP attachment ID from the picture_record row.
   * @param int|null $expectedBytes Stored byte-size signal, or null for a legacy row.
   * @param string   $itemId        picture_record itemid, used to backfill a legacy row.
   * @return bool false if the file is missing or fails verification; true otherwise
   *   (including a legacy row that passed its one-time check and was backfilled).
   */
  private function verifyRecordedImageIntegrity( int $attachmentId, ?int $expectedBytes, string $itemId ): bool
  {
    $path = get_attached_file( $attachmentId );
    if ( ! $path || ! file_exists( $path ) ) {
      return false;
    }

    if ( $expectedBytes !== null ) {
      return filesize( $path ) === $expectedBytes;
    }

    // Legacy row written before the expected_bytes column existed: verify
    // once via the cheap structural check, then backfill so every future
    // check for this item is a plain filesize() stat.
    if ( ! ImageIntegrity::verifyFileComplete( $path ) ) {
      return false;
    }

    $size = filesize( $path );
    if ( $size !== false ) {
      global $wpdb;
      $wpdb->update(
        $wpdb->prefix . 'picture_record',
        [ 'expected_bytes' => $size ],
        [ 'itemid' => $itemId ]
      );
    }

    return true;
  }

  /**
   * Sideload an image from a URL into the WP media library.
   *
   * URLs that point into this plugin's own image folder are attached straight
   * from the local file — the old behavior handed our own URL to
   * media_sideload_image(), which re-downloaded the file from this server over
   * HTTP (one full loopback round trip per image, and a hard failure on hosts
   * that block loopback requests). Genuinely remote URLs still go through the
   * HTTP sideload path.
   *
   * @param string $url    Publicly accessible URL of the image to sideload.
   * @param int    $postId Parent post ID (0 = no parent, attachment created unattached).
   * @return int|null WP attachment ID on success, null on failure.
   */
  private function sideloadImageUrl( string $url, int $postId ): ?int
  {
    $localPath = $this->pluginUrlToLocalPath( $url );
    if ( $localPath !== null ) {
      return $this->attachLocalImage( $localPath, $postId );
    }

    require_once ABSPATH . 'wp-admin/includes/admin.php';
    $this->addDeployImageFilters();
    try {
      $result = media_sideload_image( $url, $postId, '', 'id' );
    } finally {
      $this->removeDeployImageFilters();
    }
    if ( is_wp_error( $result ) ) {
      CBSLogger::products()->error( 'sideloadImageUrl failed', [
        'url'   => $url,
        'error' => $result->get_error_message(),
      ] );
      return null;
    }
    return (int) $result ?: null;
  }

  /**
   * Map a URL inside this plugin's directory back to its filesystem path.
   *
   * @param string $url
   * @return string|null Absolute local path, or null when the URL is not ours.
   */
  private function pluginUrlToLocalPath( string $url ): ?string
  {
    $prefix = get_site_url() . '/' . self::PLUGIN_PATH;
    if ( strpos( $url, $prefix ) !== 0 ) {
      return null;
    }
    return plugin_dir_path( CBS_PLUGIN_FILE ) . substr( $url, strlen( $prefix ) );
  }

  /**
   * Create a WP media attachment directly from a local file — no HTTP.
   *
   * Copies the file into the uploads directory, inserts the attachment post,
   * and generates the (deploy-restricted) intermediate sizes.
   *
   * @param string $localPath Absolute path of the already-downloaded image.
   * @param int    $postId    Parent post ID (0 = unattached).
   * @param string $title     Attachment title; the slug derived from it must
   *                          stay the mediaItemId for category/component images
   *                          because getAttachmentById() looks attachments up
   *                          by slug.
   * @return int|null WP attachment ID on success, null on failure.
   */
  private function attachLocalImage( string $localPath, int $postId = 0, string $title = '' ): ?int
  {
    if ( ! file_exists( $localPath ) ) {
      CBSLogger::products()->error( 'attachLocalImage: source file missing', [ 'path' => $localPath ] );
      return null;
    }

    $uploads = wp_upload_dir();
    if ( ! empty( $uploads['error'] ) ) {
      CBSLogger::products()->error( 'attachLocalImage: uploads dir unavailable', [ 'error' => $uploads['error'] ] );
      return null;
    }

    $filename = wp_unique_filename( $uploads['path'], basename( $localPath ) );
    $newPath  = trailingslashit( $uploads['path'] ) . $filename;

    if ( ! copy( $localPath, $newPath ) ) {
      CBSLogger::products()->error( 'attachLocalImage: copy into uploads failed', [
        'source' => $localPath,
        'target' => $newPath,
      ] );
      return null;
    }

    $filetype   = wp_check_filetype( $filename );
    $attachment = [
      'post_mime_type' => $filetype['type'] ?: 'image/png',
      'post_title'     => $title !== '' ? $title : preg_replace( '/\.[^.]+$/', '', $filename ),
      'post_content'   => '',
      'post_status'    => 'inherit',
      'guid'           => trailingslashit( $uploads['url'] ) . $filename,
    ];

    $attachmentId = wp_insert_attachment( $attachment, $newPath, $postId );
    if ( is_wp_error( $attachmentId ) || ! $attachmentId ) {
      CBSLogger::products()->error( 'attachLocalImage: wp_insert_attachment failed', [
        'path'  => $newPath,
        'error' => is_wp_error( $attachmentId ) ? $attachmentId->get_error_message() : 'returned 0',
      ] );
      return null;
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    $this->addDeployImageFilters();
    try {
      $metadata = wp_generate_attachment_metadata( $attachmentId, $newPath );
      wp_update_attachment_metadata( $attachmentId, $metadata );
    } finally {
      $this->removeDeployImageFilters();
    }

    return (int) $attachmentId;
  }

  /**
   * Restrict generated thumbnail sizes while deploy images are processed.
   * WP otherwise regenerates EVERY registered intermediate size per image —
   * the dominant CPU cost on image-heavy catalogs. The allowlist covers the
   * sizes the storefront actually serves and is filterable for themes that
   * need more.
   */
  private function addDeployImageFilters(): void
  {
    add_filter( 'intermediate_image_sizes_advanced', [ $this, 'restrictDeployImageSizes' ] );
    add_filter( 'big_image_size_threshold', '__return_false' );
  }

  private function removeDeployImageFilters(): void
  {
    remove_filter( 'intermediate_image_sizes_advanced', [ $this, 'restrictDeployImageSizes' ] );
    remove_filter( 'big_image_size_threshold', '__return_false' );
  }

  /**
   * Filter callback for intermediate_image_sizes_advanced (must be public).
   *
   * @param array $sizes Registered sizes.
   * @return array Only the deploy-relevant sizes.
   */
  public function restrictDeployImageSizes( $sizes ): array
  {
    $allowed = (array) apply_filters( 'cbs_deploy_image_sizes', [
      'woocommerce_thumbnail',
    ] );

    return array_intersect_key( (array) $sizes, array_flip( $allowed ) );
  }

  /**
   * Upload image to the woocoommerce
   *
   * @param string $url image url.
   * @param string $postId post id.
 * @return string.
 */
  protected function uploadImage($url, $postId, $description = "cbs-attachment" ,  $returnType= 'id')
  {
    CBSLogger::products()->debug('Uploading image', ['url' => $url, 'post_id' => $postId]);
    try {

      // Plugin-dir URLs (the normal case — the file was just written by
      // storeAndOptimizeImage) are attached straight from disk instead of
      // re-downloading our own URL over HTTP. $description becomes the
      // attachment title so the slug stays the mediaItemId, which
      // getAttachmentById() relies on for lookups.
      $localPath = $this->pluginUrlToLocalPath( (string) $url );
      if ( $localPath !== null ) {
        $attachmentId = $this->attachLocalImage( $localPath, (int) $postId, (string) $description );
        if ( $attachmentId === null ) {
          return null;
        }
        return $returnType === 'src' ? wp_get_attachment_url( $attachmentId ) : $attachmentId;
      }

      include_once ABSPATH . 'wp-admin/includes/admin.php';
      $this->addDeployImageFilters();
      try {
        $attchId = media_sideload_image($url, $postId, $description, $returnType);
      } finally {
        $this->removeDeployImageFilters();
      }
      if (is_wp_error($attchId)) {
        $wpError = $attchId->get_error_message();
        CBSLogger::products()->error('media_sideload_image failed', ['url' => $url, 'error' => $wpError]);
        return null;
      }
      return $attchId;
    } catch (Exception $e) {
      CBSLogger::products()->error('uploadImage exception', ['message' => $e->getMessage()]);
    }
  }


  /**
   * Write the meta fields that are identical for both new and updated products.
   * Callers handle stock, siteid/menuid append logic, thumbnail, and terms separately.
   *
   * @param int           $postId
   * @param ProductParams $params
   * @param string        $price  Regular/sale price value.
   */
  private function syncProductMeta( int $postId, ProductParams $params, string $price ): void
  {
    if ( $price === '' || $price === '0' ) {
      CBSLogger::products()->warning( 'deploy: product has zero or empty price — verify OLO data', [
        'post_id' => $postId,
        'item_id' => $params->itemId,
        'site_id' => $params->siteId,
      ] );
    }

    update_post_meta( $postId, '_visibility',            'visible' );
    update_post_meta( $postId, '_downloadable',          'no' );
    update_post_meta( $postId, '_virtual',               'no' );
    update_post_meta( $postId, '_sale_price',            '' );
    update_post_meta( $postId, '_purchase_note',         '' );
    update_post_meta( $postId, '_featured',              'no' );
    update_post_meta( $postId, '_weight',                '' );
    update_post_meta( $postId, '_length',                '' );
    update_post_meta( $postId, '_width',                 '' );
    update_post_meta( $postId, '_height',                '' );
    update_post_meta( $postId, '_sku',                   '' );
    update_post_meta( $postId, '_sale_price_dates_from', '' );
    update_post_meta( $postId, '_sale_price_dates_to',   '' );
    update_post_meta( $postId, '_sold_individually',     '' );
    update_post_meta( $postId, '_manage_stock',          'yes' );
    update_post_meta( $postId, '_backorders',            'no' );
    update_post_meta( $postId, 'total_sales',            '0' );
    update_post_meta( $postId, '_regular_price',         $price );
    update_post_meta( $postId, '_price',                 $price );
    update_post_meta( $postId, '_itemid',                $params->itemId );
    update_post_meta( $postId, '_itemname',              $params->name );
    update_post_meta( $postId, '_numberofplacement',     $params->numberOfPlacements );
    update_post_meta( $postId, '_type',                  $params->type );
    update_post_meta( $postId, '_comboqualifierids',     serialize( $params->comboQualifierIds ) );
  }

  /**
   * Create simple product
   *
   * @param ProductParams $params An object containing the following properties:
   *   - string $proName: Product name.
   *   - string $proprice: Product price.
   *   - string $proDes: Product description.
   *   - string $numberOfPlacements: Number of placements allowed.
   *   - string $termId: Term ID.
   *   - string $proImg: Product image URL.
   *   - array $components: Item components.
   *   - string $siteid: Site ID.
   *   - string $itemid: Item ID.
   *   - array $servingOptions: Serving options.
   */
  protected function custombizAddSimpleProduct(ProductParams $params)
  {
    CBSLogger::products()->debug('custombizAddSimpleProduct called', ['item_id' => $params->itemId, 'productName' => $params->name, 'img' => $params->img]);
    try {
      $postId = wp_insert_post([
        'post_title'   => $params->name,
        'post_content' => $params->description,
        'post_status'  => 'publish',
        'post_type'    => 'product',
        'menu_order'   => $params->displayOrder,
      ]);

      if ( is_wp_error( $postId ) || ! $postId ) {
        CBSLogger::products()->error( 'wp_insert_post failed for new product', [
          'item_id' => $params->itemId,
          'error'  => is_wp_error( $postId ) ? $postId->get_error_message() : 'returned 0',
        ] );
        return null;
      }

      $attchId = $this->updateProductImage($params->img, $postId, $params->itemId, $params->mediaItemId);

      $typeResult = wp_set_object_terms($postId, 'simple', 'product_type');
      if ( is_wp_error( $typeResult ) ) {
        CBSLogger::products()->warning( 'wp_set_object_terms failed on product create — product_type not set', [
          'post_id' => $postId,
          'item_id' => $params->itemId,
          'error'   => $typeResult->get_error_message(),
        ] );
      }
      $catResult = wp_set_object_terms($postId, intval($params->termId), 'product_cat');
      if ( is_wp_error( $catResult ) ) {
        CBSLogger::products()->warning( 'wp_set_object_terms failed on product create — product may be uncategorized', [
          'post_id' => $postId,
          'term_id' => $params->termId,
          'item_id' => $params->itemId,
          'site_id' => $params->siteId,
          'error'   => $catResult->get_error_message(),
        ] );
      }

      $this->syncProductMeta($postId, $params, (string) $params->price);

      update_post_meta($postId, '_stock_status', 'instock');
      update_post_meta($postId, '_stock', 999);

      if (!empty($params->components)) {
        update_post_meta($postId, '_components', wp_slash(json_encode($params->components)));
      }
      if (!empty($params->servingOptions)) {
        $updated = update_post_meta($postId, '_servingoptions', wp_slash(json_encode($params->servingOptions)));
        CBSLogger::products()->debug('Serving options saved for new product', ['post_id' => $postId, 'updated' => $updated]);
      }

      if ($attchId) {
        update_post_meta($postId, '_thumbnail_id', $attchId);
      }

      update_post_meta($postId, '_siteid', $params->siteId);
      update_post_meta($postId, '_menuid', $this->currentMenuId);
      $this->writeActiveDateMeta($postId, $params);

      if ($params->linkToCategory) {
        update_post_meta($postId, '_link_to_category', $params->linkToCategory);
      }
      if (empty($currentThumb) || empty($currentThumb[0])) {
        return (int) $postId;
      }

      $this->setAltText($postId, $params->description);
      return (int) $postId;
    } catch (Exception $e) {
      CBSLogger::products()->error('custombizAddSimpleProduct exception', ['message' => $e->getMessage()]);
      return null;
    }
  }

  /**
   * Update simple product
   *
   * @param ProductParams $params An object containing the following properties:
   *  - string $proName: Product name.
   *  - string $proPrice: Product price.
   *  - string $proDes: Product description.
   *  - string $numberOfPlacements: Number of placements allowed.
   *  - string $termId: Term ID.
   *  - string $proImg: Product image URL.
   *  - array $components: Item components.
   *  - string $siteid: Site ID.
   *  - string $itemid: Item ID.
   *  - string $mediaItemId: Media item ID.
   */
  protected function custombizUpdateSimpleProduct(ProductParams $params)
  {
    $postId = $params->postId;
    CBSLogger::products()->debug('custombizUpdateSimpleProduct called', ['post_id' => $postId, 'item_id' => $params->itemId, 'mediaItemId' => $params->mediaItemId]);
    try {
      // Prime the entire meta cache for this post in a single query.
      // WordPress's update_post_meta() compares new values against this cache,
      // so all subsequent calls are memory-only comparisons and only issue a
      // DB write when the value has actually changed.
      get_post_meta( $postId );

      // Skip wp_update_post() — which fires WooCommerce stock/price/cache hooks —
      // when no post fields have changed. This is the single biggest time-saver
      // for already-saved products.
      $currentPost = get_post( $postId );
      if (
        ! $currentPost ||
        $currentPost->post_title   !== (string) $params->name ||
        $currentPost->post_content !== (string) $params->description ||
        $currentPost->post_status  !== (string) $params->status ||
        (int) $currentPost->menu_order !== (int) $params->displayOrder
      ) {
        $updateResult = wp_update_post([
          'ID'           => $postId,
          'post_title'   => $params->name,
          'post_content' => $params->description,
          'post_status'  => $params->status,
          'post_type'    => 'product',
          'menu_order'   => $params->displayOrder,
        ], true);
        if (is_wp_error($updateResult)) {
          foreach ($updateResult->get_error_messages() as $error) {
            CBSLogger::products()->error('wp_update_post failed', ['post_id' => $postId, 'error' => $error]);
          }
        }
      }

      $attchId = $this->updateProductImage($params->img, $postId, $params->itemId, $params->mediaItemId);

      $typeResult = wp_set_object_terms($postId, 'simple', 'product_type');
      if ( is_wp_error( $typeResult ) ) {
        CBSLogger::products()->warning( 'wp_set_object_terms failed on product update — product_type not set', [
          'post_id' => $postId,
          'item_id' => $params->itemId,
          'error'   => $typeResult->get_error_message(),
        ] );
      }
      // OE-26454: assign (append) the product to its category term without stripping its other
      // same-site category terms. An item may legitimately belong to several categories on the
      // same site (incl. a LinkToDataJson sub-category); the end-of-deploy reconciliation
      // (removeStaleTermProducts) removes only assignments that are not in a term's expected
      // item set, so it is the single source of truth for cross-term cleanup.
      $catResult = wp_set_object_terms($postId, intval($params->termId), 'product_cat', true);
      if ( is_wp_error( $catResult ) ) {
        CBSLogger::products()->warning( 'custombizUpdateSimpleProduct: wp_set_object_terms failed on product update — product may be uncategorized', [
          'post_id' => $postId,
          'term_id' => $params->termId,
          'item_id' => $params->itemId,
          'site_id' => $params->siteId,
          'error'   => $catResult->get_error_message(),
        ] );
      }

      $this->syncProductMeta($postId, $params, (string) $params->price);

      if ($params->available == 1) {
        update_post_meta($postId, '_stock_status', 'outofstock');
        update_post_meta($postId, '_stock', 0);
      } else {
        update_post_meta($postId, '_stock_status', 'instock');
        update_post_meta($postId, '_stock', 999);
      }

      if (is_array($params->components)) {
        update_post_meta($postId, '_components', wp_slash(json_encode($params->components)));
      }
      if (is_array($params->servingOptions)) {
        $soResult = update_post_meta($postId, '_servingoptions', wp_slash(json_encode($params->servingOptions)));
        CBSLogger::products()->debug('Serving options updated for existing product', ['post_id' => $postId, 'result' => $soResult]);
      }

      if ($attchId) {
        update_post_meta($postId, '_thumbnail_id', $attchId);
      }

      if ($params->linkToCategory) {
        update_post_meta($postId, '_link_to_category', $params->linkToCategory);
      }

      // Append siteid / menuid only when not already present (products can belong to multiple menus).
      $existingSiteIds = get_post_meta($postId, '_siteid');
      if (empty($existingSiteIds) || !in_array($params->siteId, $existingSiteIds)) {
        $addResult = add_post_meta($postId, '_siteid', $params->siteId, false);
        // DIAGNOSTIC (OE — cross-site fingerprint investigation): confirms this
        // site's _siteid was actually added on the full (non-fingerprint-skip)
        // path for a shared post — pair with applySkippedItemState()'s equivalent
        // log to see which of the two paths a given item took, and whether either
        // one's write actually persisted.
        CBSLogger::products()->info('custombizUpdateSimpleProduct: re-adding missing _siteid for this site', [
          'post_id'       => $postId,
          'item_id'       => $params->itemId,
          'site_id'       => $params->siteId,
          'add_succeeded' => false !== $addResult,
        ]);
      }

      $existingMenuIds = get_post_meta($postId, '_menuid');
      if (empty($existingMenuIds) || !in_array($this->currentMenuId, $existingMenuIds)) {
        $addResult = add_post_meta($postId, '_menuid', $this->currentMenuId, false);
        CBSLogger::products()->info('custombizUpdateSimpleProduct: re-adding missing _menuid for this menu', [
          'post_id'       => $postId,
          'item_id'       => $params->itemId,
          'menu_id'       => $this->currentMenuId,
          'add_succeeded' => false !== $addResult,
        ]);
      }

      $this->writeActiveDateMeta($postId, $params);

      $this->setAltText($postId, $params->description);
      return true;
    } catch (Exception $e) {
      CBSLogger::products()->error('custombizUpdateSimpleProduct exception', ['message' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * Write (or clear) `_active_start_{siteId}`/`_active_stop_{siteId}` for one
   * product (product-active-date-window / save-product-deploy capabilities).
   * A null value on $params means WOAPI supplied no date for this site, or the
   * raw value was unparseable (WoapiProductAdapter::parseActiveDate() already
   * failed open to null) — either way the existing meta for this site is
   * cleared rather than left stale, per the "WOAPI supplies no dates for a
   * site" / "unrecognized format" scenarios in the save-product-deploy spec.
   */
  private function writeActiveDateMeta( int $postId, ProductParams $params ): void
  {
    $siteId = (string) $params->siteId;

    // /menuitems failed for this site this run — activeStart/activeStop are
    // unreliable (both null regardless of what's actually configured), so
    // leave whatever's already stored alone rather than deleting good data
    // because of a transient API error (product-active-date-window).
    if ( ! empty( $this->menuItemDatesFetchFailed[ $siteId ] ) ) {
      return;
    }

    $startKey = MenuItemActiveWindow::startKey( $siteId );
    $stopKey  = MenuItemActiveWindow::stopKey( $siteId );

    if ( null !== $params->activeStart ) {
      update_post_meta( $postId, $startKey, (int) $params->activeStart );
    } else {
      delete_post_meta( $postId, $startKey );
    }

    if ( null !== $params->activeStop ) {
      update_post_meta( $postId, $stopKey, (int) $params->activeStop );
    } else {
      delete_post_meta( $postId, $stopKey );
    }
  }

  /**
   * Set or Update Alt text for image
   * @param string $postId post id.
   * @param string $proDes product description.
   */
  protected function setAltText($postId, $altText)
  {
    $currentThumb = get_post_meta($postId, '_thumbnail_id');
    $currentAlt = get_post_meta($currentThumb[0], '_wp_attachment_image_alt');
    if (empty($currentAlt) || $currentAlt[0] !== $altText) {
      update_post_meta($currentThumb[0], '_wp_attachment_image_alt', $altText);
    }
  }

  /**
   * Check if a image is coming and update the product image.
   *
   * @param string $proImg product image url.
   * @param int $postId post id.
   * @param string $itemid item id.
   * @param string $mediaItemId media item id.
   * @return int|null attachment id if image is uploaded, null otherwise.
   */
  protected function updateProductImage( $proImg, $postId, $itemid, $mediaItemId )
  {
    $attachmentId = (int) $proImg;
    if ( $attachmentId <= 0 ) {
      CBSLogger::products()->debug( 'No attachment ID, skipping image update', [ 'itemid' => $itemid ] );
      return null;
    }

    // Set the WP product thumbnail and persist the attachment ID in picture_record.
    set_post_thumbnail( $postId, $attachmentId );
    $this->updateImageProductRecord( $itemid, (string) $attachmentId, $mediaItemId );

    return $attachmentId;
  }

  /**
   * Update image records table
   *
   * @param string $itemId.
   * @param string $proImg.
   * @param string $mediaItemId
 */
  protected function updateImageProductRecord( string $itemId, string $proImg, string $mediaItemId ): void
  {
    global $wpdb;
    $table = $wpdb->prefix . 'picture_record';

    // The file has already passed storeAndOptimizeImage()/compressImage()'s
    // integrity checks by the time an attachment ID exists to get here — its
    // resolved on-disk size is the verification signal future skip checks
    // compare against (see verifyRecordedImageIntegrity()).
    $expectedBytes = $this->resolveAttachmentByteSize( (int) $proImg );

    $existing = $wpdb->get_row(
      $wpdb->prepare( "SELECT pictureid, expected_bytes FROM {$table} WHERE itemid = %s LIMIT 1", $itemId )
    );

    if ( $existing === null ) {
      $wpdb->insert( $table, [
        'itemid'         => $itemId,
        'mediaitemid'    => $mediaItemId,
        'pictureid'      => $proImg,
        'expected_bytes' => $expectedBytes,
      ] );
      CBSLogger::products()->debug( 'Image record inserted into picture_record table', [ 'item_id' => $itemId, 'mediaItemId' => $mediaItemId ] );
      return;
    }

    if ( $existing->pictureid === $proImg ) {
      if ( $existing->expected_bytes === null && $expectedBytes !== null ) {
        $wpdb->update( $table, [ 'expected_bytes' => $expectedBytes ], [ 'itemid' => $itemId ] );
        CBSLogger::products()->debug( 'Backfilled expected_bytes for existing picture_record row', [ 'item_id' => $itemId ] );
      } else {
        CBSLogger::products()->debug( 'Image record not updated — same picture already recorded', [ 'item_id' => $itemId ] );
      }
      return;
    }

    $wpdb->update(
      $table,
      [ 'pictureid' => $proImg, 'mediaitemid' => $mediaItemId, 'expected_bytes' => $expectedBytes ],
      [ 'itemid'    => $itemId ]
    );
    CBSLogger::products()->debug( 'Image record updated in picture_record table', [ 'item_id' => $itemId, 'mediaItemId' => $mediaItemId ] );
  }

  /**
   * Resolve the on-disk byte size of a WP attachment's file, or null if the
   * attachment/file cannot be resolved.
   */
  private function resolveAttachmentByteSize( int $attachmentId ): ?int
  {
    if ( $attachmentId <= 0 ) {
      return null;
    }

    $path = get_attached_file( $attachmentId );
    if ( ! $path || ! file_exists( $path ) ) {
      return null;
    }

    $size = filesize( $path );
    return $size === false ? null : $size;
  }

  /**
   * Save or update the components' image.
   *
   * This method iterates through the components array and checks if each component has a media item ID.
   * If a component does not have a media item ID, it is skipped.
   * For components with a media item ID, it checks if the image already exists in the record.
   * If the image does not exist, it retrieves the image URL using the media item ID.
   * If the image URL is not available, the component is skipped.
   * If the image URL is available, it updates the component's image using the existing record and media item ID.
   * Finally, it updates the 'image' property of the component in the components array with the updated image source.
   *
   * @param array $components The array of components.
   * @return void
   */
  protected function saveOrUpdateComponentsImage(&$componentsData)
  {
    if(!$componentsData){
      return;
    }

    foreach ($componentsData as &$components) {
      $this->saveOrUpdateSingleComponentImage($components);
    }
  }

  /**
   * Saves or updates the image for each component in the given array.
   *
   * @param array $components The array of components.
   * @return void
   */
  protected function saveOrUpdateSingleComponentImage(&$components)
  {
    foreach($components as &$component){
      if(!$component['mediaItemId']){
        continue;
      }
      $mediaItemId = $component['mediaItemId'];

      $componentPictureUrl = $this->getNewComponentImage($mediaItemId);
      $component['image'] = $componentPictureUrl;
    }
  }

  /**
   * Retrieves the component image based on the media item ID.
   *
   * @param int $mediaItemId The ID of the media item.
   * @return string|null The URL of the component image, or null if already exist.
   */
  protected function getNewComponentImage($mediaItemId)
  {
    $attachment = $this->getAttachmentById($mediaItemId);

    if (!$attachment) {
      $pictureUrl = $this->retrieveAndCreateImage($mediaItemId);
      return $pictureUrl ? $this->updateComponentImage($pictureUrl, $mediaItemId) : null;
    }

    return $attachment->guid ?: null;
  }

  /**
   * Retrieves the attachment ID based on the given media item ID.
   *
   * @param string $mediaItemId The media item ID.
   * @return object|null The attachment found, null otherwise.
   */
  protected function getAttachmentById($mediaItemId){
    $page = get_page_by_path($mediaItemId, OBJECT, 'attachment');
    return $page ? $page : null;
  }

  /**
   * Updates the component image based on the existing record, component ID, image source, and media item ID.
   *
   * @param array $existingRecord The existing record of the component.
   * @param int $componentId The ID of the component.
   * @param string $imgSrc The source of the image.
   * @param int $mediaItemId The ID of the media item.
   * @return string The updated image source.
   */
  protected function updateComponentImage($imgSrc, $mediaItemId)
  {
    return $this->uploadImage($imgSrc, null, $mediaItemId , 'src') ?? null;
  }

  /**
   * Checks if an image exists in the picture_record table for a given media item ID and item ID.
   *
   * @param int $itemId The item ID to check.
   * @return array|null Returns an array of existing record(s) if found, or null if not found.
   */
  protected function imageExistInRecord($itemId)
  {
    global $wpdb;
    $tableNamePictureRecords = $wpdb->prefix . 'picture_record';
    $existingRecordPicture = $wpdb->get_results(
      $wpdb->prepare( "SELECT * FROM {$tableNamePictureRecords} WHERE itemid = %s LIMIT 1", $itemId )
    );

    if (empty($existingRecordPicture)) {
      return null;
    }

    return $existingRecordPicture;
  }

  /**
   * Retrieves and creates an image for a given media item ID.
   *
   * @param int $mediaItemId The ID of the media item.
   * @return string The source URL of the created image.
   */
  protected function retrieveAndCreateImage($mediaItemId)
  {
    try {
      $response = $this->getMediaItemFromAPI($mediaItemId);
      $imgSrc   = $this->storeAndOptimizeImage($response);
    } catch (Exception $e) {
      CBSLogger::products()->error('retrieveAndCreateImage exception', ['message' => $e->getMessage()]);
      return null;
    }

    if ( empty( $imgSrc ) ) {
      CBSLogger::products()->warning( 'retrieveAndCreateImage: empty imgSrc — skipping URL build', [ 'mediaItemId' => $mediaItemId ] );
      return null;
    }

    $imgSrc = get_site_url() . '/' . self::PLUGIN_PATH . self::IMG_PRODUCTS . $imgSrc . '.PNG';
    CBSLogger::products()->debug('Image source retrieved', ['imgSrc' => $imgSrc]);
    return $imgSrc;
  }

  /**
   * Retrieves a media item from the API based on its ID.
   *
   * @param int $mediaItemId The ID of the media item to retrieve.
   * @return mixed The response from the API.
   */
  protected function getMediaItemFromAPI($mediaItemId)
  {
    // Serve from the parallel batch when available. The entry is freed
    // immediately — ECM responses carry the full image as base64, so holding
    // consumed payloads would balloon memory on image-heavy menus.
    $cacheKey = (string) $mediaItemId;
    if ( isset( $this->prefetchedMediaItems[ $cacheKey ] ) ) {
      $response = $this->prefetchedMediaItems[ $cacheKey ];
      unset( $this->prefetchedMediaItems[ $cacheKey ] );
      return $response;
    }

    $url = $this->instanceEcmUrl . '/ecm/api/v1/mediaitem/' . $mediaItemId;
    CBSLogger::products()->debug('Fetching media item from API', ['url' => $url]);
    $token = $this->siteToken;

    return \CBSNorthStar\Helpers\WoapiRequest::create()->get($url, [
      'token' => $token
    ]);
  }

  /**
   * Parallel-fetch the ECM media payloads a batch of menu items will need.
   *
   * Candidate filter mirrors getImage()'s skip logic so nothing is downloaded
   * that the per-item path would not download anyway:
   *   - deploy-wide image skip off, item actually has a mediaItemId;
   *   - picture_record (one bulk query) says the image is new or changed —
   *     a matching record means getImage() short-circuits without fetching;
   *   - not already sideloaded this run, not already prefetched, and not
   *     already on disk from earlier in this run.
   *
   * The batch is capped (cbs_deploy_media_prefetch_max, default 50) because
   * ECM responses embed the full image as base64 — overflow candidates simply
   * fall back to the sequential per-item fetch.
   *
   * @param mixed $menuItems The batch passed to processMenuItems().
   * @return void
   */
  private function prefetchMediaItems( $menuItems ): void
  {
    if ( $this->skipImages ) {
      return;
    }

    // [itemId => mediaItemId] for items in this batch that have an image.
    $wanted = [];
    foreach ( $this->ensureArray( $menuItems ) as $menuItem ) {
      $item        = $this->menuItemMap[ (string) ( $menuItem->MenuItemId ?? '' ) ] ?? null;
      $mediaItemId = $item->MenuItemMediaItems->MenuItemMediaItem[0]->MediaItemId ?? null;
      if ( ! empty( $mediaItemId ) ) {
        $wanted[ (string) $menuItem->MenuItemId ] = (string) $mediaItemId;
      }
    }

    if ( empty( $wanted ) ) {
      return;
    }

    // One bulk picture_record lookup instead of one per item.
    global $wpdb;
    $table        = $wpdb->prefix . 'picture_record';
    $placeholders = implode( ',', array_fill( 0, count( $wanted ), '%s' ) );
    $rows         = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT itemid, mediaitemid, pictureid, expected_bytes FROM {$table} WHERE itemid IN ($placeholders)",
        array_keys( $wanted )
      )
    );
    $recorded = [];
    foreach ( (array) $rows as $row ) {
      $recorded[ (string) $row->itemid ] = $row;
    }

    $candidates = [];
    foreach ( $wanted as $itemId => $mediaItemId ) {
      $record = $recorded[ $itemId ] ?? null;
      if ( $record && (string) $record->mediaitemid === $mediaItemId && ! empty( $record->pictureid ) ) {
        // Cheap local check before trusting the skip — mirrors getImage()'s
        // verifyRecordedImageIntegrity(). A failed check falls through to
        // become a prefetch candidate for this one item only.
        $expectedBytes = $record->expected_bytes ?? null;
        $verified      = $this->verifyRecordedImageIntegrity(
          (int) $record->pictureid,
          $expectedBytes !== null ? (int) $expectedBytes : null,
          $itemId
        );
        if ( $verified ) {
          continue; // Unchanged image — getImage() will not fetch.
        }
        // Not counted here: getImage() re-runs this same check per-item right after
        // prefetch and increments images_repaired itself — counting it here too would
        // double it. This branch only decides whether to warm the ECM cache.
        CBSLogger::products()->warning( 'Recorded image failed integrity verification — prefetching repair', [
          'itemid'      => $itemId,
          'mediaItemId' => $mediaItemId,
        ] );
      }
      if ( isset( $this->sideloadedImages[ $mediaItemId ] )
        || isset( $this->prefetchedMediaItems[ $mediaItemId ] ) ) {
        continue; // Already handled this run.
      }
      $raw = str_replace( '-', '', $mediaItemId );
      if ( file_exists( plugin_dir_path( CBS_PLUGIN_FILE ) . self::IMG_PRODUCTS . $raw . '.PNG' ) ) {
        continue; // Disk cache from earlier in this run.
      }
      $candidates[ $mediaItemId ] = $this->instanceEcmUrl . '/ecm/api/v1/mediaitem/' . $mediaItemId;
    }

    if ( empty( $candidates ) ) {
      return;
    }

    $max = max( 0, (int) apply_filters( 'cbs_deploy_media_prefetch_max', 50 ) );
    if ( $max > 0 && count( $candidates ) > $max ) {
      $candidates = array_slice( $candidates, 0, $max, true );
    }

    // ECM authenticates with the Bearer scheme (WoapiRequest's default when no
    // tokenType is passed — see getMediaItemFromAPI), unlike the OEAPI's Token.
    $fetched = $this->parallelGetJson( $candidates, 'Bearer' );
    $this->prefetchedMediaItems += $fetched;

    CBSLogger::products()->info( 'ECM media payloads prefetched in parallel', [
      'requested' => count( $candidates ),
      'fetched'   => count( $fetched ),
    ] );
  }

  /**
   * Stores and optimizes an image.
   *
   * This method takes a response object and performs the following steps:
   * 1. Replaces any hyphens in the file name and media item ID with an empty string.
   * 2. Retrieves the plugin directory path.
   * 3. Decodes the base64 image data.
   * 4. Writes the image file to the specified directory.
   * 5. Optimizes the image by compressing it with the specified quality.
   * 6. Logs the destination of the optimized image.
   *
   * @param object $response The response object containing the image data.
   * @return string The optimized image source.
   */
  protected function storeAndOptimizeImage($response)
  {
    $data = $response->Data ?? null;

    if (
      empty( $data->FileName   ) ||
      empty( $data->MediaItemId ) ||
      empty( $data->MediaData  )
    ) {
      CBSLogger::products()->warning( 'storeAndOptimizeImage: API response missing required image fields', [
        'hasFileName'    => ! empty( $data->FileName ),
        'hasMediaItemId' => ! empty( $data->MediaItemId ),
        'hasMediaData'   => ! empty( $data->MediaData ),
      ] );
      return null;
    }

    $img    = str_replace( '-', '', $data->FileName );
    $imgSrc = str_replace( '-', '', $data->MediaItemId );
    $path   = plugin_dir_path( CBS_PLUGIN_FILE );

    $imageBase64 = base64_decode( $data->MediaData );

    // Source-integrity check: reject a truncated ECM payload before it ever
    // reaches GD. GD frequently "succeeds" on a truncated JPEG (decodes what
    // it has, pads the rest), silently producing a structurally valid but
    // visually cut-off output — this check runs on the raw bytes, before
    // that can happen. See design.md Decision 1.
    $sourceCheck = ImageIntegrity::verifyBinaryComplete( $imageBase64 );
    if ( ! $sourceCheck['ok'] ) {
      CBSLogger::products()->warning( 'storeAndOptimizeImage: source image data failed integrity check', [
        'mediaItemId'    => $data->MediaItemId,
        'detectedFormat' => $sourceCheck['format'],
      ] );
      return null;
    }

    $file = $path . self::IMG_PRODUCT_LOAD . $img;
    $written = file_put_contents( $file, $imageBase64 );
    if ( $written === false || $written !== strlen( $imageBase64 ) ) {
      CBSLogger::products()->warning( 'storeAndOptimizeImage: raw image write incomplete', [
        'mediaItemId' => $data->MediaItemId,
        'expected'    => strlen( $imageBase64 ),
        'written'     => $written,
      ] );
      return null;
    }

    $optimized    = $path . self::IMG_PRODUCTS . $img;
    $quality      = get_option( 'image_compression', 9 );
    $optimizedImg = $this->fileManager->compressImage( $file, $optimized, $quality );
    CBSLogger::products()->debug( 'Image stored and optimized', [ 'destination' => $optimizedImg ] );

    return $imgSrc;
  }



  /**
   * Check if product already saved by itemid
   *
   * @param string $itemId item id.
   * @return string
   */
  protected function checkProductExistsByItemId($itemId)
  {
    CBSLogger::products()->debug('Checking if product exists by item ID', ['item_id' => $itemId]);

    $args = array(
      'post_type'   => 'product',
      'numberposts' => 1,
      'post_status' => array('trash', 'publish'),
      'fields'      => 'ids',
      'meta_query'  => array(
        array(
          'key'   => '_itemid',
          'value' => $itemId,
        )
      )
    );

    try {
      $postslist = get_posts($args);

      if (!empty($postslist)) {
        return $postslist[0]; // Return the first matching post ID
      }

      return false; // No matching post found
    } catch (Exception $e) {
      CBSLogger::products()->error('checkProductExistsByItemId exception', ['message' => $e->getMessage()]);
    }
  }






  /**
   * Find an existing product_cat WP_Term by the external category ID stored in term meta.
   * Returns null when no matching term exists (safe; the old method crashed on empty results).
   *
   * @param string $siteId site id.
   * @param string $catId  external menu_item_category_id value.
   * @return \WP_Term|null
   */
  protected function findTermByExternalCategoryId( $siteId, $catId ): ?\WP_Term
  {
    $terms = get_terms( array(
      'taxonomy'   => 'product_cat',
      'hide_empty' => false,
      'orderby'    => 'term_id',
      'order'      => 'ASC',
      'meta_query' => array(
        array( 'key' => 'menu_item_category_id', 'value' => $catId ),
      ),
    ) );

    if ( ! is_array( $terms ) || empty( $terms ) ) {
      return null;
    }

    if ( count( $terms ) > 1 ) {
      CBSLogger::products()->warning( 'findTermByExternalCategoryId: multiple terms match same catId — using lowest term_id', array(
        'site_id'   => $siteId,
        'cat_id'    => $catId,
        'term_ids'  => array_map( static fn( $t ) => $t->term_id, $terms ),
      ) );
    }

    return $terms[0] instanceof \WP_Term ? $terms[0] : null;
  }


  protected function cleanProductCache(): void
  {
    CacheService::clearProductCache();
  }

    /**
   * Process a set of menu items: adapt product data, attach category mapping,
   * resolve images, and either update an existing product or create a new one.
   *
   * @param iterable        $menuItems
   * @param object          $siteMenuItem
   * @param int|string      $siteId
   * @param int|string      $categoryId
   * @param \WP_Term        $term_id
   * @param array<int,object> $unavailableItems Items with ->Id matching MenuItemId when unavailable
   * @return void
   */
    public function processMenuItems(
        $menuItems,
        $siteMenuItem,
        $siteId,
        $categoryId,
        $termId,
        array $unavailableItems
    ): void {
    $unavailableSet = $this->buildUnavailableSet($unavailableItems);

    // Collect all item IDs and resolve existing posts in one query (eliminates N+1).
    $itemIds    = [];
    foreach ($menuItems as $menuItem) {
        $itemIds[] = (string) $menuItem->MenuItemId;
    }

    $productMap = $this->prefetchProductMap($itemIds);

    // Prime the post-meta and object→term caches for the whole batch in two
    // queries. Every subsequent get_post_meta()/update_post_meta() comparison
    // and wp_set_object_terms() read is then a memory hit — this also turns
    // custombizUpdateSimpleProduct's per-post meta prime into a cache hit.
    $batchPostIds = array_filter( array_map( 'intval', array_values( $productMap ) ) );
    if ( ! empty( $batchPostIds ) ) {
        update_meta_cache( 'post', $batchPostIds );
        update_object_term_cache( $batchPostIds, 'product' );
    }

    // Fetch the ECM media payloads this batch will actually need, in parallel,
    // before the per-item loop starts pulling them one at a time.
    $this->prefetchMediaItems($menuItems);

    // Accumulate into the per-site deployed-ID set for stale-cleanup after save.
    // ECM-disabled items (falsy IsActive) are left out so the end-of-run
    // stale cleanup removes their _siteid association the same way it handles
    // items dropped from the menu (OE-26435). $itemIds itself stays unfiltered
    // for prefetchProductMap() above.
    $activeItemIds   = [];
    $inactiveItemIds = [];
    foreach ( $itemIds as $id ) {
        if ( WoapiProductAdapter::isMenuItemActive( $this->menuItemMap[ $id ] ?? null ) ) {
            $activeItemIds[] = $id;
        } else {
            $inactiveItemIds[] = $id;
        }
    }
    $this->siteStats['items_inactive'] += count( $inactiveItemIds );
    if ( ! empty( $inactiveItemIds ) ) {
        CBSLogger::products()->info( 'processMenuItems: excluded inactive items from deployed set', [
            'site_id' => $siteId,
            'count'   => count( $inactiveItemIds ),
            'itemIds' => $inactiveItemIds,
        ] );
    }
    if ( ! isset( $this->deployedItemIdsBySite[ $siteId ] ) ) {
        $this->deployedItemIdsBySite[ $siteId ] = [];
    }
    $this->deployedItemIdsBySite[ $siteId ] = array_values( array_unique(
        array_merge( $this->deployedItemIdsBySite[ $siteId ], $activeItemIds )
    ) );

    // Accumulate the active items per (site, menu) as well. processMenuItems() runs
    // once per category, so this unions across the current menu's categories; the
    // menu is identified by $this->currentMenuId (set once per menu, stable across
    // nested LinkToDataJson calls). The end-of-site prune uses this to delete the
    // _menuid association for items disabled/absent in this specific menu (OE-26435).
    if ( '' !== (string) $this->currentMenuId ) {
        $menuKey = (string) $this->currentMenuId;
        if ( ! isset( $this->deployedItemIdsBySiteMenu[ $siteId ][ $menuKey ] ) ) {
            $this->deployedItemIdsBySiteMenu[ $siteId ][ $menuKey ] = [];
        }
        $this->deployedItemIdsBySiteMenu[ $siteId ][ $menuKey ] = array_values( array_unique(
            array_merge( $this->deployedItemIdsBySiteMenu[ $siteId ][ $menuKey ], $activeItemIds )
        ) );
    }

    foreach ($menuItems as $menuItem) {
        // Check cancel flag between products.
        // Do NOT call DeployProgress::cancel() here — cancel() deletes the cancel key,
        // which causes subsequent isCancelRequested() calls to return false.
        // Let store() call cancel() once after all loops exit.
        if ( ! empty( $this->currentRunId ) && DeployProgress::isCancelRequested( $this->currentRunId ) ) {
            CBSLogger::products()->info( 'Deploy cancelled mid-batch', [ 'run_id' => $this->currentRunId ] );
            $this->cancelDetected = true;
            return;
        }

        $itemKey     = (string) $menuItem->MenuItemId;
        // Fall back to products created earlier in THIS run: the $productMap
        // snapshot is taken once before the loop and never re-queries the DB,
        // so without this an item that appears twice in the same batch — or one
        // created by a nested LinkToDataJson processMenuItems() call mid-loop —
        // would be seen as new and inserted a second time (OE-26396).
        $foundPost   = $productMap[ $itemKey ] ?? ( $this->createdItemPostIds[ $itemKey ] ?? false );
        $fingerprint = $this->computeItemFingerprint( $itemKey, $siteId );

        // Track every item ID for this term (active, inactive, or fingerprint-skipped)
        // so removeStaleTermProducts() can remove any product whose _itemid is not
        // in this expected set — the core fix for pre-existing contaminated assignments.
        $this->termExpectedItemIds[(int)$termId][$itemKey] = true;

        // ── Item-fingerprint skip (Tier A) ─────────────────────────────────
        // Unchanged item + unchanged referenced definitions → skip the whole
        // assembly. Stock, category placement, and the cleanup accumulator
        // are still maintained (cheap, cache-backed) so the skipped item is
        // indistinguishable from a processed one to the end-of-run steps.
        if ( $fingerprint !== null && $foundPost
            && hash_equals( (string) get_post_meta( (int) $foundPost, '_cbs_item_hash', true ), $fingerprint ) ) {
            // DIAGNOSTIC (OE — cross-site fingerprint investigation): a shared post
            // (same _itemid across sites) can have an IDENTICAL fingerprint on a
            // site that has never processed it before — e.g. a brand-new site
            // deploy for an item whose definition happens to match what another
            // site already stored in _cbs_item_hash. That's fine IF
            // applySkippedItemState() still adds this site's _siteid/_menuid, but
            // this line makes that first-time-for-this-site case visible instead
            // of silently indistinguishable from a routine re-skip.
            $siteIdsBeforeSkip = get_post_meta( (int) $foundPost, '_siteid' );
            CBSLogger::products()->debug( 'processMenuItems: Tier-A fingerprint skip', [
                'site_id'                  => $siteId,
                'menu_id'                  => $this->currentMenuId,
                'term_id'                  => $termId,
                'item_id'                  => $itemKey,
                'post_id'                  => $foundPost,
                'site_already_associated'  => in_array( $siteId, (array) $siteIdsBeforeSkip, true ),
                'existing_site_ids'        => $siteIdsBeforeSkip,
            ] );
            $this->progressProcessedProducts++;
            $this->maybeFlushProgress();
            $this->applySkippedItemState( (int) $foundPost, $itemKey, (int) $termId, $siteId, $unavailableSet );
            $this->siteStats['items_skipped_fingerprint']++;
            continue;
        }
        // ────────────────────────────────────────────────────────────────────

        $product = $this->buildProductForMenuItem($menuItem, $siteMenuItem, $siteId, $categoryId, (int)$termId);

        // Count every item as processed — whether saved, skipped, or trashed —
        // so processedProducts always reaches totalProducts at the end.
        // The DB write is throttled (see maybeFlushProgress) to avoid one
        // wp_options write per product on large catalogs.
        $this->progressProcessedProducts++;
        $this->maybeFlushProgress();

        if ($product === null) {
            // Could not adapt / missing data / marked as trash — already counted above.
            continue;
        }

        $this->appendCategoryForPostKey($foundPost, (int)$termId);

        // $product->img stores the WP attachment ID (int|null). Callers pass it to
        // updateProductImage() which calls set_post_thumbnail() directly.
        $product->img = $this->skipImages ? null : $this->buildImageSrc(
            $product->mediaItemId ?? null,
            $itemKey,
            $foundPost ? (int) $foundPost : 0
        );

        if ($foundPost) {
            $saved = $this->handleExistingProduct($product, $foundPost, $itemKey, $unavailableSet);
            // The fingerprint is only recorded after a clean save, so a failed
            // item is always reprocessed on the next run.
            if ($saved && $fingerprint !== null) {
                update_post_meta( (int) $foundPost, '_cbs_item_hash', $fingerprint );
            }
        } else {
            $newPostId = $this->createNewProduct($product);
            if ($newPostId) {
                // Record the new post so a later occurrence of the same item id
                // in this batch (or via LinkToDataJson recursion) resolves to it
                // and takes the update path instead of inserting a duplicate.
                $productMap[ $itemKey ]               = $newPostId;
                $this->createdItemPostIds[ $itemKey ] = $newPostId;
                if ($fingerprint !== null) {
                    update_post_meta( $newPostId, '_cbs_item_hash', $fingerprint );
                }
            }
        }
    }

    // Persist the final processed count for this batch (per-item writes above
    // are throttled, so force one write here to keep the progress bar accurate).
    $this->maybeFlushProgress( true );
  }

  /**
   * Compose a product object for a given menu item.
   * Returns null if it cannot adapt or the product is marked as 'trash'.
   */
  private function buildProductForMenuItem($menuItem, $siteMenuItem, $siteId, $categoryId, int $termId): ?object
  {
      $itemsDetails = $this->getItemDetails($siteMenuItem, $menuItem->MenuItemId, $siteId, $categoryId);
      if (!$itemsDetails) {
          CBSLogger::products()->warning('getItemDetails returned empty for menu item', ['menuItemId' => $menuItem->MenuItemId]);
          return null;
      }

      $product = WoapiProductAdapter::adaptProductData($itemsDetails, $termId);
      if (!$product) {
          CBSLogger::products()->warning('adaptProductData returned null for menu item', ['menuItemId' => $menuItem->MenuItemId]);
          return null;
      }

      if (!empty($product->status) && $product->status === 'trash') {
          return null;
      }

      if (!empty($product->components) && ! $this->skipImages) {
          $this->saveOrUpdateComponentsImage($product->components);
      }

      return $product;
  }

  /**
   * Fetch or sideload the product image into WP media.
   *
   * @param mixed  $mediaItemId External media item UUID.
   * @param string $menuItemId  Used as the picture_record key (itemid).
   * @param int    $postId      Parent post ID for the sideload; 0 for new products.
   * @return int|null WP attachment ID, or null when no image is available.
   */
  private function buildImageSrc( $mediaItemId, string $menuItemId, int $postId = 0 ): ?int
  {
      if ( empty( $mediaItemId ) ) {
          return null;
      }
      return $this->getImage( $mediaItemId, $postId, $menuItemId );
  }

  /** Handle the "existing product" path: availability, cleanup list, and update. */
  /** @return bool True when the product update completed without error. */
  private function handleExistingProduct(object $product, $foundPost, string $menuItemId, array $unavailableSet): bool
  {
      $product->postId    = $foundPost;
      $product->available = $this->availabilityFlag($menuItemId, $unavailableSet);

      // Remove handled post from pending IDs (guard against null)
      $pending = $this->productIds ?? [];
      $this->productIds = array_values(array_filter($pending, fn ($id) => $id !== (string)$foundPost));

      try {
          $updated = (bool) $this->custombizUpdateSimpleProduct($product);
          if ($updated) {
              $this->siteStats['items_updated']++;
          } else {
              CBSLogger::products()->warning( 'deploy: product update returned false — product state may be stale', [
                  'post_id' => $product->postId,
                  'item_id' => $product->itemId,
                  'site_id' => $product->siteId,
                  'menu_id' => $this->currentMenuId,
              ] );
          }
          return $updated;
      } catch (\Throwable $th) {
          CBSLogger::products()->error('handleExistingProduct exception', ['message' => $th->getMessage()]);
          return false;
      }
  }

  /**
   * Handle the "create new product" path with logging & error capture.
   * @return int|null New post ID on success, null on failure.
   */
  private function createNewProduct(object $product): ?int
  {
      try {
          $newPostId = $this->custombizAddSimpleProduct($product);
          if ($newPostId) {
              $this->siteStats['items_created']++;
              CBSLogger::products()->debug('deploy: product created', [
                  'item_id' => $product->itemId ?? null,
                  'post_id' => $newPostId,
                  'site_id' => $product->siteId ?? null,
              ]);
          }
          return $newPostId ?: null;
      } catch (\Throwable $th) {
          CBSLogger::products()->error('createNewProduct exception', ['message' => $th->getMessage()]);
          return null;
      }
  }


  private function buildUnavailableSet(array $unavailableItems): array
  {
      $set = [];
      foreach ($unavailableItems as $item) {
          if (isset($item->Id)) {
              $set[(string)$item->Id] = true;
          }
      }
      return $set;
  }

  /** Return 1 if menu item is in unavailable set; otherwise 0  */
  private function availabilityFlag(string $menuItemId, array $unavailableSet): int
  {
      return isset($unavailableSet[$menuItemId]) ? 1 : 0;
  }

  /**
   * Append a term to the productCategories map for the given post key.
   * 
   *
   * @param mixed $postKey
   * @param int   $termId
   * @return void
   */
  private function appendCategoryForPostKey($postKey, int $termId): void
  {
      $key = (string)$postKey; 
      if (empty($this->productCategories[$key])) {
          $this->productCategories[$key] = [];
      }
      $this->productCategories[$key][] = $termId;
      $this->productCategories[$key] = array_values(array_unique($this->productCategories[$key]));
  }
  /**
   * Normalizes apostrophes in the given text (keeps meaningful apostrophes).
   *
   * @param ?string $text The input text to sanitize.
   * @return string The sanitized text.
   */
  protected function sanitizeApostrophes(?string $text): string 
  {
    $text = (string) $text;
    $text = str_replace(['’','‘','`','´','ʼ'], "'", $text);
    $text = preg_replace("/(?<!\p{L})'(?!\p{L})/u", "", $text);
    $text = preg_replace('/\s{2,}/', ' ', $text);
    return trim($text);
  }


  // -------------------------------------------------------------------------
  // Phase-4 stale product cleanup
  // -------------------------------------------------------------------------

  /**
   * Delete WooCommerce products that belong to $siteId but whose _itemid
   * is no longer present in the API response for that site.
   *
   * Called only after a *successful* site deploy, so we never delete based
   * on a partially-fetched or failed run.
   *
   * Safety guard: if $deployedItemIds is empty the method does nothing —
   * an empty set almost certainly means a data-fetch problem, not an
   * intentionally empty menu.
   *
   * @param string   $siteId          Site ID (value stored in _siteid meta).
   * @param string[] $deployedItemIds All MenuItemId strings returned by the API for this site.
   */
  private function deleteStaleProductsForSite( string $siteId, array $deployedItemIds ): void
  {
    if ( empty( $deployedItemIds ) ) {
      CBSLogger::products()->warning(
        'deleteStaleProductsForSite: deployed set is empty — skipping to prevent mass deletion',
        [ 'site_id' => $siteId ]
      );
      return;
    }

    global $wpdb;
    $placeholders = implode( ',', array_fill( 0, count( $deployedItemIds ), '%s' ) );

    $stalePostIds = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT DISTINCT p.ID
           FROM {$wpdb->posts}     p
           JOIN {$wpdb->postmeta}  pm_site ON pm_site.post_id = p.ID
                                          AND pm_site.meta_key   = '_siteid'
                                          AND pm_site.meta_value = %s
           JOIN {$wpdb->postmeta}  pm_item ON pm_item.post_id = p.ID
                                          AND pm_item.meta_key   = '_itemid'
          WHERE p.post_type   = 'product'
            AND p.post_status IN ('publish', 'trash')
            AND pm_item.meta_value NOT IN ($placeholders)",
        $siteId,
        ...$deployedItemIds
      )
    );

    if ( empty( $stalePostIds ) ) {
      CBSLogger::products()->debug( 'deleteStaleProductsForSite: no stale products found', [ 'site_id' => $siteId ] );
      return;
    }

    CBSLogger::products()->info( 'deleteStaleProductsForSite: processing stale products', [
      'site_id' => $siteId,
      'count'  => count( $stalePostIds ),
      'ids'    => $stalePostIds,
    ] );

    // OE-26454 subset-daypart guard. A deploy may fetch only a SUBSET of the site's daypart
    // menus — the ECM AreaDayPartMenus payload varies by schedule/daypart (logs show the menu
    // set bouncing 3→1→2). The stale query above is site-wide, so a product belonging only to
    // a menu we did NOT cover this run would be wrongly deleted (one such run purged 22
    // products). The set of menus this run actually covered is the keys of
    // deployedItemIdsBySiteMenu (filled for processed menus in processMenuItems() and for
    // hash-skipped menus in registerSkippedMenuState()). Any product still carrying a _menuid
    // outside that set is retained — we have no authority to prune a menu we never refreshed.
    // Per-menu pruning for covered menus already ran in deleteStaleMenuAssociations().
    $coveredMenuSet = array_fill_keys(
      array_map( 'strval', array_keys( $this->deployedItemIdsBySiteMenu[ $siteId ] ?? [] ) ),
      true
    );

    foreach ( $stalePostIds as $postId ) {
      $postMenuIds              = array_map( 'strval', (array) get_post_meta( (int) $postId, '_menuid' ) );
      $belongsToUncoveredMenu   = false;
      foreach ( $postMenuIds as $postMenuId ) {
        if ( '' !== $postMenuId && ! isset( $coveredMenuSet[ $postMenuId ] ) ) {
          $belongsToUncoveredMenu = true;
          break;
        }
      }
      if ( $belongsToUncoveredMenu ) {
        CBSLogger::products()->info( 'deleteStaleProductsForSite: retained (belongs to a daypart menu not covered this run)', [
          'postId'         => $postId,
          'siteId'         => $siteId,
          'postMenuIds'    => $postMenuIds,
          'coveredMenuIds' => array_keys( $coveredMenuSet ),
        ] );
        continue;
      }

      // Remove only the _siteid association for this site.
      delete_post_meta( (int) $postId, '_siteid', $siteId );

      // Check whether any other site associations remain.
      $remainingSiteIds = get_post_meta( (int) $postId, '_siteid' );

      if ( empty( $remainingSiteIds ) ) {
        wp_delete_post( (int) $postId, true );
        CBSLogger::products()->info( 'deleteStaleProductsForSite: fully deleted product (no remaining site associations)', [
          'post_id' => $postId,
          'site_id' => $siteId,
        ] );
      } else {
        CBSLogger::products()->info( 'deleteStaleProductsForSite: removed site association only (product retained for other sites)', [
          'post_id' => $postId,
          'removedSiteId'    => $siteId,
          'remainingSiteIds' => $remainingSiteIds,
        ] );
      }

      // OE-26454: the category's `site_id` term meta is intentionally NOT removed here.
      // The per-term emptiness probe used to misfire (publish-only count, duplicate terms,
      // subset-daypart deploys) and strip the meta from categories that still had items,
      // dropping the whole category from the legacy [menuitems] nav (which is gated purely
      // by term meta) and causing a deploy-to-deploy delete/re-add oscillation. Term meta is
      // now stable structural membership; empty categories are hidden at render time by the
      // categoryHasActiveProducts() gate. Supersedes OE-26435 requirement #4.
    }

    global $wpdb;
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_' . $wpdb->esc_like( 'wc_products_' ) . '%',
        '_transient_timeout_' . $wpdb->esc_like( 'wc_products_' ) . '%'
      )
    );
    CBSLogger::products()->info( 'deleteStaleProductsForSite: flushed wc_products_ transients', [ 'site_id' => $siteId ] );
  }

  /**
   * Remove the per-menu (`_menuid`) association of products that are no longer active
   * in a given menu, plus the `menu_id` term meta of categories left empty for that menu.
   *
   * Menu-granularity counterpart of {@see deleteStaleProductsForSite()}. A product post is
   * shared across sites and daypart menus (multi-value `_siteid` / `_menuid`). `IsActive` is
   * per (site, menu, item): disabling an item in ONE menu while it stays active in ANOTHER
   * menu of the same site keeps it in the per-site deployed set, so its `_siteid` is correctly
   * retained and {@see deleteStaleProductsForSite()} never touches it. But `_menuid` is
   * append-only and otherwise never pruned, so the stale row for the disabled menu survives and
   * the read paths (`_siteid` AND `_menuid` scoped — see {@see ProductScope::metaQuery()}) keep
   * rendering the item in the menu it was disabled in (OE-26435).
   *
   * This removes ONLY the `_menuid = $menuId` row for items absent from $activeItemIds; it never
   * deletes the post or its `_siteid` (the per-site cleanup owns post lifecycle). When a category
   * is left with no products for this site+menu, its `menu_id` term meta is dropped so the now
   * empty category disappears from that menu's navigation on every render path.
   *
   * Fails CLOSED against mass pruning: an empty $activeItemIds (payload error or every item in
   * the menu disabled) skips the prune entirely, mirroring the site-level guard.
   *
   * @param string   $siteId        Active site id.
   * @param string   $menuId        Daypart menu id whose stale associations to prune.
   * @param string[] $activeItemIds External item ids (`_itemid`) that ARE active in this menu.
   */
  private function deleteStaleMenuAssociations( string $siteId, string $menuId, array $activeItemIds ): void
  {
    if ( empty( $activeItemIds ) ) {
      CBSLogger::products()->warning(
        'deleteStaleMenuAssociations: active set is empty — skipping to prevent mass _menuid pruning',
        [ 'site_id' => $siteId, 'menu_id' => $menuId ]
      );
      return;
    }

    global $wpdb;
    $placeholders = implode( ',', array_fill( 0, count( $activeItemIds ), '%s' ) );

    $stalePostIds = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT DISTINCT p.ID
           FROM {$wpdb->posts}     p
           JOIN {$wpdb->postmeta}  pm_site ON pm_site.post_id = p.ID
                                          AND pm_site.meta_key   = '_siteid'
                                          AND pm_site.meta_value = %s
           JOIN {$wpdb->postmeta}  pm_menu ON pm_menu.post_id = p.ID
                                          AND pm_menu.meta_key   = '_menuid'
                                          AND pm_menu.meta_value = %s
           JOIN {$wpdb->postmeta}  pm_item ON pm_item.post_id = p.ID
                                          AND pm_item.meta_key   = '_itemid'
          WHERE p.post_type   = 'product'
            AND p.post_status IN ('publish', 'trash')
            AND pm_item.meta_value NOT IN ($placeholders)",
        $siteId,
        $menuId,
        ...$activeItemIds
      )
    );

    if ( empty( $stalePostIds ) ) {
      CBSLogger::products()->debug( 'deleteStaleMenuAssociations: no stale menu associations found', [
        'site_id' => $siteId,
        'menu_id' => $menuId,
      ] );
      return;
    }

    CBSLogger::products()->info( 'deleteStaleMenuAssociations: pruning stale _menuid associations', [
      'site_id' => $siteId,
      'menu_id' => $menuId,
      'count'  => count( $stalePostIds ),
      'ids'    => $stalePostIds,
    ] );

    foreach ( $stalePostIds as $postId ) {
      // Remove only this menu's association — the product stays for its other menus/sites.
      delete_post_meta( (int) $postId, '_menuid', $menuId );
      // Signal Pressable's Nginx page cache to purge this product's cached HTML.
      // delete_post_meta fires deleted_post_meta which Pressable does NOT listen to;
      // clean_post_cache fires the clean_post_cache action which it does.
      clean_post_cache( (int) $postId );
    }

    // OE-26454: the category's `menu_id` term meta is intentionally NOT removed here (see the
    // matching note in deleteStaleProductsForSite()). The per-term emptiness probe misfired and
    // stripped meta from categories that still had items, dropping them from the legacy
    // [menuitems] nav. Per-menu emptiness is now enforced at render by categoryHasActiveProducts()
    // (scoped to site+menu). Term meta stays stable structural membership. Supersedes OE-26435 #4.

    CBSLogger::products()->info( 'deleteStaleMenuAssociations: fired clean_post_cache on pruned products', [
      'site_id' => $siteId,
      'menu_id' => $menuId,
      'affected_product_count' => count( $stalePostIds ),
      'product_ids'           => $stalePostIds,
    ] );

    // Flush the per-(site,menu,category) product-list transients written by the read
    // paths (MainController::getProductsHTML, key prefix `wc_products_`). These are
    // flat 1-hour keys that do NOT embed the WC cache version, so the deploy-wide
    // CacheService::clearProductCache() (which only bumps WC versions / clears
    // `wc_product_` singular) does not invalidate them. deleteStaleProductsForSite()
    // issues the same flush, but it early-returns before that when the site has no
    // fully-stale products — exactly the per-menu-only disable case handled here — so
    // this method must flush them itself or the pruned item lingers for up to an hour.
    global $wpdb;
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_' . $wpdb->esc_like( 'wc_products_' ) . '%',
        '_transient_timeout_' . $wpdb->esc_like( 'wc_products_' ) . '%'
      )
    );
    CBSLogger::products()->info( 'deleteStaleMenuAssociations: flushed wc_products_ transients', [
      'site_id' => $siteId,
      'menu_id' => $menuId,
    ] );
  }

  /**
   * Remove stale `menu_id` term meta from product_cat terms no longer in a menu's API response.
   *
   * Uses the 3-arg form of delete_term_meta() so only the specific menu's value is removed;
   * term meta entries for other menus on the same term are preserved.
   *
   * Fails CLOSED on an empty $activeTermIds (payload error or all categories removed) to
   * prevent mass pruning, mirroring the guard in deleteStaleMenuAssociations().
   *
   * @param string $siteId        Active site ID.
   * @param string $menuId        Daypart menu ID whose stale term meta to prune.
   * @param int[]  $activeTermIds Term IDs of product_cat terms that ARE active in this menu.
   */
  private function deleteStaleMenuCategoryMeta( string $siteId, string $menuId, array $activeTermIds ): void
  {
    if ( empty( $activeTermIds ) ) {
      CBSLogger::products()->warning(
        'deleteStaleMenuCategoryMeta: active set is empty — skipping to prevent mass menu_id pruning',
        [ 'site_id' => $siteId, 'menu_id' => $menuId ]
      );
      return;
    }

    $activeTermIds = array_map( 'intval', array_unique( $activeTermIds ) );

    $dbTerms = get_terms( [
      'taxonomy'   => 'product_cat',
      'hide_empty' => false,
      'meta_query' => [
        [ 'key' => 'menu_id', 'value' => $menuId ],
        [ 'key' => 'site_id', 'value' => $siteId ],
      ],
    ] );

    if ( is_wp_error( $dbTerms ) || empty( $dbTerms ) ) {
      CBSLogger::products()->debug( 'deleteStaleMenuCategoryMeta: no stale category meta found', [
        'site_id' => $siteId,
        'menu_id' => $menuId,
      ] );
      return;
    }

    $dbTermIds    = array_map( static fn( $t ) => (int) $t->term_id, $dbTerms );
    $staleTermIds = array_values( array_diff( $dbTermIds, $activeTermIds ) );

    if ( empty( $staleTermIds ) ) {
      CBSLogger::products()->debug( 'deleteStaleMenuCategoryMeta: no stale category meta found', [
        'site_id' => $siteId,
        'menu_id' => $menuId,
      ] );
      return;
    }

    foreach ( $staleTermIds as $termId ) {
      delete_term_meta( $termId, 'menu_id', $menuId );
      $remainingMenuIds = get_term_meta( $termId, 'menu_id', false );
      if ( empty( $remainingMenuIds ) ) {
        $deleted = wp_delete_term( $termId, 'product_cat' );
        if ( $deleted === false || $deleted === 0 || is_wp_error( $deleted ) ) {
          CBSLogger::products()->warning( 'deleteStaleMenuCategoryMeta: failed to delete term with no remaining menu_id', [
            'site_id' => $siteId,
            'menu_id' => $menuId,
            'term_id' => $termId,
            'error'   => is_wp_error( $deleted ) ? $deleted->get_error_message() : $deleted,
          ] );
        } else {
          CBSLogger::products()->info( 'deleteStaleMenuCategoryMeta: deleted term with no remaining menu_id', [
            'site_id' => $siteId,
            'menu_id' => $menuId,
            'term_id' => $termId,
          ] );
        }
      }
    }

    CBSLogger::products()->info( 'deleteStaleMenuCategoryMeta: pruned stale menu_id term meta', [
      'site_id'  => $siteId,
      'menu_id'  => $menuId,
      'count'    => count( $staleTermIds ),
      'term_ids' => $staleTermIds,
    ] );

    // No wc_products_ transient flush needed here (contrast with deleteStaleMenuAssociations).
    // Those transients cache rendered product HTML keyed by (site, menu, category). They go
    // stale when a product loses its _menuid product meta — the concern in
    // deleteStaleMenuAssociations. Here we only remove menu_id *term* meta from the category
    // term itself; no product meta changes. The shortcodes ([categories]/[menuitems]) build
    // the category list via raw $wpdb SQL that queries wp_termmeta directly, bypassing the
    // WordPress term cache, so the cleaned category disappears from the next page load
    // without any additional flush. Once the category is gone from the list, no frontend
    // code path requests the wc_products_ transient for that (menu, category) pair, making
    // any orphaned transients unreachable until they naturally expire.
  }

  /**
   * Ensure existing LinkTo child terms under a parent category carry the current
   * site/menu scope term meta. This allows stale prune to evaluate legacy terms
   * that were created before LinkTo children had site_id/menu_id metadata.
   */
  private function ensureLinkToChildrenScopedToMenu( int $parentTermId, string $siteId, string $menuId ): void
  {
    $children = get_terms( [
      'taxonomy'   => 'product_cat',
      'hide_empty' => false,
      'parent'     => $parentTermId,
      'meta_query' => [
        [ 'key' => 'linkToId', 'compare' => 'EXISTS' ],
      ],
    ] );

    if ( is_wp_error( $children ) || empty( $children ) ) {
      return;
    }

    foreach ( $children as $child ) {
      $childId = (int) $child->term_id;

      $menuIds = get_term_meta( $childId, 'menu_id', false );
      if ( ! in_array( $menuId, array_map( 'strval', $menuIds ), true ) ) {
        add_term_meta( $childId, 'menu_id', $menuId, false );
      }

      $siteIds = get_term_meta( $childId, 'site_id', false );
      if ( ! in_array( $siteId, array_map( 'strval', $siteIds ), true ) ) {
        add_term_meta( $childId, 'site_id', $siteId, false );
      }
    }
  }

  // -------------------------------------------------------------------------
  // Phase-2 performance helpers
  // -------------------------------------------------------------------------

  /**
   * Build all site-level lookup maps once per site visit.
   * Must be called after the four global arrays are populated in getWholeSiteData().
   */
  private function buildSiteDataMaps(
    $siteComponentCategory,
    $siteComponent,
    $siteServingOptionCategory,
    $siteServingOption
  ): void {
    $this->componentCategoryMap = [];
    foreach ( $siteComponentCategory as $cat ) {
      $this->componentCategoryMap[ $cat->ComponentCategoryId ] = $cat;
    }

    $this->componentMap = [];
    foreach ( $siteComponent as $comp ) {
      $this->componentMap[ $comp->ComponentId ] = $comp;
    }

    $this->servingOptionCatMap = [];
    foreach ( $siteServingOptionCategory as $cat ) {
      $this->servingOptionCatMap[ $cat->ServingOptionCategoryId ] = $cat;
    }

    $this->servingOptionMap = [];
    foreach ( $siteServingOption as $opt ) {
      $this->servingOptionMap[ $opt->ServingOptionId ] = $opt;
    }
  }

  /**
   * Build the per-menu pricing level map once per menu.
   * Must be called after $menuPricingLevel is retrieved in getWholeSiteData().
   *
   * Normalizes via ensureArray(): a menu with exactly one pricing level
   * decodes from WOAPI as a bare object rather than an array (see
   * ensureArray()'s docblock), and foreach over a bare object iterates its
   * properties rather than the object itself — silently leaving this map
   * empty for that menu. Mirrors buildMenuItemMap()'s established pattern of
   * normalizing inside the function rather than at each call site.
   */
  private function buildMenuPricingLevelMap( $menuPricingLevel ): void
  {
    $this->pricingLevelMap = [];
    foreach ( $this->ensureArray( $menuPricingLevel ) as $level ) {
      $this->pricingLevelMap[ $level->PricingLevelId ] = $level;
    }
  }

  /**
   * Build the per-menu [MenuItemId => item] map used by getItemDetails().
   * Normalizes the single-object API shape via ensureArray() so a one-item
   * menu still indexes correctly.
   */
  private function buildMenuItemMap( $siteMenuItem ): void
  {
    $this->menuItemMap = [];
    foreach ( $this->ensureArray( $siteMenuItem ) as $item ) {
      if ( isset( $item->MenuItemId ) ) {
        $this->menuItemMap[ (string) $item->MenuItemId ] = $item;
      }
    }
  }

  /**
   * Return the site-wide unavailable-items list, fetching it from the API at
   * most once per site per deploy. The /unavailableitems/ endpoint ignores the
   * category, so a per-category call repeats the same network round-trip C
   * times per site.
   *
   * @param string $siteId
   * @return array
   */
  private function getUnavailableItemsForSite( $siteId ): array
  {
    if ( ! array_key_exists( $siteId, $this->unavailableItemsCache ) ) {
      $data = ( new ProductManager( null, null, $siteId, null ) )->unavailableData();
      $this->unavailableItemsCache[ $siteId ]      = $this->ensureArray( $data->UnavailableMenuItems ?? [] );
      $this->unavailableComponentsCache[ $siteId ] = $this->buildUnavailableSet( $this->ensureArray( $data->UnavailableComponents ?? [] ) );
    }
    return $this->unavailableItemsCache[ $siteId ];
  }

  /**
   * Return the site-wide unavailable-components set (componentId => true),
   * sourced from the same /unavailableitems/ response as
   * getUnavailableItemsForSite() — no additional network call.
   *
   * @param string $siteId
   * @return array
   */
  private function getUnavailableComponentsSetForSite( $siteId ): array
  {
    if ( ! array_key_exists( $siteId, $this->unavailableComponentsCache ) ) {
      $this->getUnavailableItemsForSite( $siteId ); // populates both caches from one fetch
    }
    return $this->unavailableComponentsCache[ $siteId ] ?? [];
  }

  /**
   * Return the site's [MenuItemId => item] lookup from WOAPI's `/menuitems`
   * response, fetching it at most once per site per deploy run (design.md
   * Decision 5) — `/menuitems` is site-wide, not scoped to a menu or category,
   * so a per-menu/category call would repeat the same network round-trip.
   * Mirrors getUnavailableItemsForSite()'s identical once-per-site pattern.
   *
   * @param string $siteId
   * @return array [MenuItemId => object{StartDate, EndDate, ...}]
   */
  private function getMenuItemDatesForSite( $siteId ): array
  {
    if ( ! array_key_exists( $siteId, $this->menuItemDatesCache ) ) {
      $items = ( new ProductManager( null, null, $siteId, null ) )->menuItemDates();
      if ( null === $items ) {
        // /menuitems failed for this site this run. Still cache an empty
        // lookup (so we don't retry the call on every menu), but flag the
        // failure so writeActiveDateMeta() leaves existing active-date meta
        // untouched instead of deleting it on the strength of a transient
        // API error (product-active-date-window).
        $this->menuItemDatesFetchFailed[ $siteId ] = true;
        $items = [];
      }
      $lookup = [];
      foreach ( $items as $item ) {
        if ( isset( $item->MenuItemId ) ) {
          $lookup[ (string) $item->MenuItemId ] = $item;
        }
      }
      $this->menuItemDatesCache[ $siteId ] = $lookup;
    }
    return $this->menuItemDatesCache[ $siteId ];
  }

  /**
   * Persist deploy progress to the DB, throttled so a large catalog does not
   * issue one wp_options write per product. Writes when at least
   * PROGRESS_FLUSH_EVERY products have accumulated since the last write, when
   * PROGRESS_FLUSH_SECONDS have elapsed, or when $force is true (batch end).
   *
   * Each flush also refreshes the deploy-lock heartbeat, so even a single huge
   * site keeps the lock alive between the per-site heartbeats in store().
   *
   * @param bool $force Bypass the throttle and write immediately.
   */
  private function maybeFlushProgress( bool $force = false ): void
  {
    if ( empty( $this->currentRunId ) ) {
      return;
    }

    $now = time();

    if (
      ! $force
      && ( $this->progressProcessedProducts - $this->progressLastFlushCount ) < self::PROGRESS_FLUSH_EVERY
      && ( $now - $this->progressLastFlushTime ) < self::PROGRESS_FLUSH_SECONDS
    ) {
      return;
    }

    $this->progressLastFlushCount = $this->progressProcessedProducts;
    $this->progressLastFlushTime  = $now;

    DeployProgress::update( $this->currentRunId, [
      'processedProducts' => $this->progressProcessedProducts,
      'currentStep'       => 'Saving products...',
    ] );

    \CBSNorthStar\Services\DeployLockService::create()->heartbeat( $this->currentRunId );
  }

  /**
   * True when the given site is part of this deploy run.
   * A null scope means every site is in scope (full deploy).
   */
  private function isSiteInScope( $siteId ): bool
  {
    if ( $this->deployScope === null ) {
      return true;
    }
    return isset( $this->deployScope[ strtolower( (string) $siteId ) ] );
  }

  /**
   * Load the per-menu hashes stored by this site's last successful deploy and
   * reset the pending set. Reads via $wpdb directly (never get_option) so it
   * works on hosts with a broken external object cache — same pattern as the
   * daypart snapshot and the deploy DB lock.
   */
  private function loadStoredMenuHashes( string $siteId ): void
  {
    global $wpdb;

    $this->storedMenuHashes  = [];
    $this->pendingMenuHashes = [];
    $this->storedAuxHashes   = [];

    if ( ! $this->menuHashSkipEnabled ) {
      return;
    }

    $raw = $wpdb->get_var(
      $wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        'cbs_deploy_menu_hashes_' . $siteId
      )
    );

    $decoded = $raw ? maybe_unserialize( $raw ) : null;
    if ( is_array( $decoded ) && is_array( $decoded['menus'] ?? null ) ) {
      $this->storedMenuHashes = $decoded['menus'];
    }
    if ( is_array( $decoded ) && is_array( $decoded['aux'] ?? null ) ) {
      $this->storedAuxHashes = $decoded['aux'];
    }
  }

  /**
   * Hash the prefetched auxiliary payloads (rules, serving-option categories,
   * site root) for the current site and compare them with the hashes recorded
   * by the last successful deploy.
   *
   * - 'rules' / 'serving' unchanged → their cbs_save_api_response rewrites are
   *   skipped (the stored rows are byte-identical already).
   * - 'rules', 'serving' or 'components' NOT verifiably unchanged → the
   *   per-menu skip is disabled for this site (see shouldSkipUnchangedMenu):
   *   rule, serving-option AND component-definition values are denormalized
   *   into product meta at save time, so unchanged menu payloads can still
   *   need reprocessing.
   * - 'components' covers only the root payload's Components +
   *   ComponentCategories subtrees — component definitions (incl. their
   *   PricingLevelId refs) live in the site root payload, not in any per-menu
   *   payload, so a component change can leave every menu hash intact.
   * - 'root' is recorded for observability only — the daypart swap stays
   *   always-on deliberately; it is the safety mechanism for OE-26364 and
   *   costs a single small transaction.
   *
   * A payload that was not prefetched (parallel batch failed) simply yields
   * no verdict: unchanged stays false, which is the conservative direction
   * everywhere it is consumed.
   */
  private function evaluateSiteAuxHashes( string $siteId ): void
  {
    $this->siteAuxUnchanged = [ 'rules' => false, 'serving' => false, 'root' => false, 'components' => false, 'dates' => false ];
    $this->pendingAuxHashes = [];

    if ( ! $this->menuHashSkipEnabled ) {
      return;
    }

    $rootData = $this->siteDataCache[ $siteId ]->Data ?? null;

    $payloads = [
      'rules'   => $this->prefetchedRules[ $siteId ] ?? null,
      'serving' => $this->prefetchedServingOptions[ $siteId ] ?? null,
      'root'    => $rootData,
      // Component-definition subtrees only, NOT the whole root payload:
      // gating the menu skip on 'root' would disable skips on any unrelated
      // site change (menus list, dayparts, area mappings, …). The wrapper
      // nodes are hashed as-is, so the WOAPI single-object shape needs no
      // ensureArray normalization here — byte-identical in, identical hash out.
      'components' => isset( $rootData->DataDefinitions )
        ? [
            'components' => $rootData->DataDefinitions->Components ?? null,
            'categories' => $rootData->DataDefinitions->ComponentCategories ?? null,
          ]
        : null,
      // Fetched once per site here (cached in menuItemDatesCache) so this hash is
      // computed before any per-menu skip decision is made below. StartDate/EndDate
      // live ONLY in the /menuitems response, never in the per-menu payload that
      // $menuHash and computeItemFingerprint() otherwise hash — without this, an
      // ECM edit that touches only an item's active-date window is invisible to
      // both the menu-level and item-level skip and never gets reprocessed on a
      // non-forceFull (webhook/hook) deploy (product-active-date-window / OE-26686).
      'dates' => $this->getMenuItemDatesForSite( $siteId ),
    ];

    foreach ( $payloads as $kind => $payload ) {
      if ( $payload === null ) {
        continue;
      }

      $hash = md5( (string) wp_json_encode( $payload ) );
      $this->pendingAuxHashes[ $kind ] = $hash;

      $stored = $this->storedAuxHashes[ $kind ] ?? null;
      $this->siteAuxUnchanged[ $kind ] =
        ! $this->forceFullDeploy && is_string( $stored ) && hash_equals( $stored, $hash );
    }

    CBSLogger::products()->debug( 'Site aux hash verdicts', [
      'site_id'    => $siteId,
      'rules'      => $this->siteAuxUnchanged['rules'] ? 'unchanged' : 'changed-or-unknown',
      'serving'    => $this->siteAuxUnchanged['serving'] ? 'unchanged' : 'changed-or-unknown',
      'root'       => $this->siteAuxUnchanged['root'] ? 'unchanged' : 'changed-or-unknown',
      'components' => $this->siteAuxUnchanged['components'] ? 'unchanged' : 'changed-or-unknown',
      'dates'      => $this->siteAuxUnchanged['dates'] ? 'unchanged' : 'changed-or-unknown',
    ] );
  }

  /**
   * Persist the hashes collected during this run (processed AND skipped
   * menus). Called only after the site deploy fully succeeded — including
   * stale-product cleanup — so a failed or cancelled run leaves the old
   * hashes in place and the next run reprocesses in full. Menus that
   * disappeared from the API drop out naturally because the pending map
   * replaces the stored one wholesale.
   */
  private function persistMenuHashes( string $siteId ): void
  {
    if ( ! $this->menuHashSkipEnabled
      || ( empty( $this->pendingMenuHashes ) && empty( $this->pendingAuxHashes ) ) ) {
      return;
    }

    update_option(
      'cbs_deploy_menu_hashes_' . $siteId,
      [
        'menus'      => $this->pendingMenuHashes,
        'aux'        => $this->pendingAuxHashes,
        'updated_at' => current_time( 'mysql' ),
      ],
      false // not autoloaded — only read during deploys
    );
  }

  /**
   * True when any item in the menu carries a LinkToDataJson reference.
   *
   * Such items pull their linked subcategory from a separate
   * /menuitemsbycategory/ endpoint (see getItemDetails()) that the menu-payload
   * hash never covers, so a menu containing one must never be menu-hash-skipped
   * — otherwise a newly added linked sub-item stays invisible until a forced
   * full deploy (OE-26396).
   *
   * @param mixed $menuItems The menu's MenuItem list (array, single object, or null).
   */
  private function menuHasLinkToData( $menuItems ): bool
  {
    foreach ( $this->ensureArray( $menuItems ) as $item ) {
      if ( ! empty( $item->LinkToDataJson ) ) {
        return true;
      }
    }
    return false;
  }

  /**
   * True when this menu's payload hash matches the stored hash and the skip
   * is active for this run.
   */
  private function shouldSkipUnchangedMenu( string $menuId, string $menuHash ): bool
  {
    if ( ! $this->menuHashSkipEnabled || $this->forceFullDeploy ) {
      return false;
    }

    // Rule, serving-option and component-definition values are denormalized
    // INTO product meta (_components / _servingoptions) at save time, so a
    // change to any of them requires reprocessing menus even when the menu
    // payloads themselves are byte-identical. Component definitions (incl.
    // their price-layer refs) exist ONLY in the site root payload — without
    // the 'components' gate a component edit that touches no per-menu payload
    // would be skipped here and the item fingerprint would never run. 'dates'
    // is the same story for active-date windows: StartDate/EndDate live only
    // in the /menuitems response, never in the per-menu payload $menuHash
    // covers (product-active-date-window / OE-26686).
    // "Unknown" (payload not prefetched) counts as changed — the conservative
    // direction.
    if ( empty( $this->siteAuxUnchanged['rules'] )
      || empty( $this->siteAuxUnchanged['serving'] )
      || empty( $this->siteAuxUnchanged['components'] )
      || empty( $this->siteAuxUnchanged['dates'] ) ) {
      return false;
    }

    $stored = $this->storedMenuHashes[ $menuId ] ?? null;

    return is_string( $stored ) && hash_equals( $stored, $menuHash );
  }

  /**
   * Compute the change fingerprint for a single menu item, or null when the
   * item must always take the full processing path.
   *
   * The fingerprint covers everything that feeds the saved product, not just
   * the item node:
   *   - the raw item node (name, price, status, media, component/serving
   *     references, combo qualifiers, display order, …);
   *   - the DEFINITIONS of each component and component category the item
   *     references — component-precise invalidation: editing one modifier's
   *     definition reprocesses only the items that use it, not the menu;
   *   - the per-menu pricing-level definitions referenced by the item AND by
   *     each of its components (a component node carries only PricingLevelId
   *     refs — its layer prices live solely in these definitions, so a
   *     component price-layer edit never touches the component node itself);
   *   - the site rules and serving-option payload hashes (coarse — their
   *     values are denormalized into _components/_servingoptions meta).
   *
   * Returns null (no skip possible) when:
   *   - the kill switch is off or this is a force-full run;
   *   - the rules/serving payloads were not prefetched (their influence on
   *     the product cannot be accounted for);
   *   - the item is not in the per-menu map;
   *   - the item carries LinkToDataJson — its assembly traverses a separate
   *     /menuitemsbycategory/ endpoint no hash covers, so skipping it would
   *     silently stale its linked subcategory.
   *
   * @param string $menuItemId
   * @param string $siteId Used to look up this item's active-date window from
   *                       the /menuitems response — see getMenuItemDatesForSite().
   * @return string|null
   */
  private function computeItemFingerprint( string $menuItemId, string $siteId ): ?string
  {
    if ( ! $this->menuHashSkipEnabled || $this->forceFullDeploy ) {
      return null;
    }

    if ( ! (bool) apply_filters( 'cbs_deploy_item_hash_skip_enabled', true ) ) {
      return null;
    }

    // The 'components' aux hash is deliberately NOT required here: this
    // fingerprint hashes each component's definition and its referenced
    // pricing-level definitions directly (below), so item-level skips remain
    // available even when the site-level components verdict is unknown.
    $rulesHash   = $this->pendingAuxHashes['rules'] ?? null;
    $servingHash = $this->pendingAuxHashes['serving'] ?? null;
    if ( $rulesHash === null || $servingHash === null ) {
      return null;
    }

    $item = $this->menuItemMap[ $menuItemId ] ?? null;
    if ( $item === null || ! empty( $item->LinkToDataJson ) ) {
      return null;
    }

    // StartDate/EndDate live only in the /menuitems response (menuItemMap comes
    // from the per-menu payload, which never carries them), so a date-only ECM
    // edit would otherwise leave $item byte-identical and this item would keep
    // being Tier-A-skipped even after the menu-level 'dates' gate above lets its
    // menu back in (product-active-date-window / OE-26686).
    $dateEntry = $this->getMenuItemDatesForSite( $siteId )[ $menuItemId ] ?? null;
    $parts     = [ (string) wp_json_encode( $item ), $rulesHash, $servingHash, (string) wp_json_encode( $dateEntry ) ];

    foreach ( $this->ensureArray( $item->Components ?? [] ) as $component ) {
      $componentId   = $component->ComponentId ?? null;
      $categoryId    = $component->ComponentCategory->ComponentCategoryId ?? null;
      $componentNode = $this->componentMap[ $componentId ] ?? null;
      $parts[]       = (string) wp_json_encode( $componentNode );
      $parts[]       = (string) wp_json_encode( $this->componentCategoryMap[ $categoryId ] ?? null );

      // The component node holds only PricingLevelId refs; its Layer/Price
      // definitions live in the per-menu pricingLevelMap. Hash those resolved
      // definitions (null marker when unresolved) so a component price-layer
      // edit — which never alters the component node — still invalidates
      // every item using it. Mirrors the item-level pricing loop below.
      foreach ( $this->ensureArray( $componentNode->PricingLevels->PricingLevel ?? [] ) as $componentLevel ) {
        $componentLevelId = $componentLevel->PricingLevelId ?? null;
        $parts[]          = (string) wp_json_encode( $this->pricingLevelMap[ $componentLevelId ] ?? null );
      }
    }

    foreach ( $this->ensureArray( $item->PricingLevels->PricingLevel ?? [] ) as $level ) {
      $levelId = $level->PricingLevelId ?? null;
      $parts[] = (string) wp_json_encode( $this->pricingLevelMap[ $levelId ] ?? null );
    }

    return md5( implode( '|', $parts ) );
  }

  /**
   * Maintain the state a skipped item still needs, all cache-backed no-ops
   * when nothing changed:
   *   - stock status from /unavailableitems/ (independent of the menu payload,
   *     so it must update even for fingerprint-identical items);
   *   - category placement (append) and the productCategories accumulator, so
   *     the relationship-pruning cleanup sees the same picture a processed
   *     item would produce.
   */
  private function applySkippedItemState( int $postId, string $menuItemId, int $termId, string $siteId, array $unavailableSet ): void
  {
    if ( $this->availabilityFlag( $menuItemId, $unavailableSet ) === 1 ) {
      update_post_meta( $postId, '_stock_status', 'outofstock' );
      update_post_meta( $postId, '_stock', 0 );
    } else {
      update_post_meta( $postId, '_stock_status', 'instock' );
      update_post_meta( $postId, '_stock', 999 );
    }

    // OE-26454: append-only, same as the full-save path. The inline single-category strip was
    // removed (see custombizUpdateSimpleProduct / removeStaleTermProducts) so a skipped item
    // shared by several same-site categories keeps every legitimate category placement.
    $this->appendCategoryForPostKey( $postId, $termId );
    $linkCatResult = wp_set_object_terms( $postId, $termId, 'product_cat', true );
    if ( is_wp_error( $linkCatResult ) ) {
      CBSLogger::products()->warning( 'applySkippedItemState: wp_set_object_terms failed — product may be uncategorized', [
        'post_id' => $postId,
        'item_id' => $menuItemId,
        'term_id' => $termId,
        'site_id' => $siteId,
        'menu_id' => $this->currentMenuId,
        'error'   => $linkCatResult->get_error_message(),
      ] );
    }

    // Re-assert the per-site and per-menu placement, exactly as the full-save path
    // does (see custombizUpdateSimpleProduct). A skipped item is "unchanged since its
    // last clean save", but its _siteid/_menuid rows can have been pruned out-of-band
    // by deleteStaleMenuAssociations()/deleteStaleProductsForSite() after the item was
    // disabled on a prior deploy. Without re-adding them here, a re-enabled item whose
    // payload still matches its stored fingerprint is Tier-A-skipped and never regains
    // its association — so it only reappears after a force-full deploy (OE-26435).
    $existingSiteIds = get_post_meta( $postId, '_siteid' );
    $siteIdAdded     = false;
    if ( empty( $existingSiteIds ) || ! in_array( $siteId, $existingSiteIds ) ) {
      $addResult   = add_post_meta( $postId, '_siteid', $siteId, false );
      $siteIdAdded = false !== $addResult;
      CBSLogger::products()->info( 'applySkippedItemState: re-adding missing _siteid for this site', [
        'post_id'      => $postId,
        'item_id'      => $menuItemId,
        'site_id'      => $siteId,
        'add_succeeded'=> $siteIdAdded,
      ] );
    }
    $existingMenuIds = get_post_meta( $postId, '_menuid' );
    if ( '' !== (string) $this->currentMenuId
      && ( empty( $existingMenuIds ) || ! in_array( $this->currentMenuId, $existingMenuIds ) ) ) {
      $addResult = add_post_meta( $postId, '_menuid', $this->currentMenuId, false );
      CBSLogger::products()->info( 'applySkippedItemState: re-adding missing _menuid for this menu', [
        'post_id'      => $postId,
        'item_id'      => $menuItemId,
        'menu_id'      => $this->currentMenuId,
        'add_succeeded'=> false !== $addResult,
      ] );
    }

    // DIAGNOSTIC (OE — cross-site fingerprint investigation): confirm the state
    // actually persisted right after the write, in this same request — if a
    // later step in this same deploy run strips it back out, the two log
    // lines' _siteid lists will differ even though this one shows success.
    if ( $siteIdAdded ) {
        CBSLogger::products()->debug( 'applySkippedItemState: _siteid state immediately after add', [
            'post_id' => $postId,
            'item_id' => $menuItemId,
            'site_id' => $siteId,
            'site_ids_now' => get_post_meta( $postId, '_siteid' ),
        ] );
    }

    // Mirror handleExistingProduct's pending-ID pruning.
    $pending = $this->productIds ?? [];
    $this->productIds = array_values( array_filter( $pending, fn ( $id ) => $id !== (string) $postId ) );
  }

  /**
   * Re-register a skipped menu's existing WordPress state into the cleanup
   * accumulators, so the destructive end-of-run steps treat the menu exactly
   * as if it had been processed:
   *
   *   1. Its products' item IDs feed deployedItemIdsBySite — otherwise
   *      deleteStaleProductsForSite() would delete every product of the
   *      skipped menu as "no longer in the API".
   *   2. Its category term IDs (matched via the site_id/menu_id term meta
   *      written on every save) and ALL their descendant terms (linked
   *      subcategories are children of their parent category) feed
   *      savedCategoriesPerMenu — otherwise the wp_delete_term() cleanup
   *      would delete the skipped menu's categories.
   *
   * Both are single read-only queries against WP state that is, by
   * definition of the matching hash, identical to the API state from the
   * last successful deploy. Progress is advanced by the menu's item count
   * (the same walk prefetchAllSiteData uses) so the bar still reaches 100%.
   *
   * Sanity guard: when the menu definition expects items/categories but the
   * WP queries find none to protect (e.g. legacy terms saved before the
   * site_id/menu_id meta existed), the method returns FALSE and the caller
   * processes the menu normally — a skip in that state would let the
   * end-of-run cleanup delete data that the still-matching hash would then
   * never recreate.
   *
   * @param string $siteId
   * @param string $menuId
   * @param object $menuDefinition The menu node from MenuDefinitions (for expected counts).
   * @param mixed  $siteMenuItems  The payload's MenuItems->MenuItem list; items with a falsy
   *                               IsActive are NOT re-registered, so ECM-disabled items stay
   *                               eligible for stale cleanup even on hash-skipped menus
   *                               (OE-26435 — covers items disabled before the fix shipped,
   *                               whose menu payload hasn't changed since).
   * @return bool True when the state was registered and the skip is safe.
   */
  private function registerSkippedMenuState( string $siteId, string $menuId, $menuDefinition, $siteMenuItems = null ): bool
  {
    global $wpdb;

    // Expected shape from the (already-fetched) menu definition.
    $expectedCategories = 0;
    $expectedItems      = 0;
    foreach ( $this->ensureArray( $menuDefinition->MenuMenuCategories->MenuMenuCategory ?? [] ) as $mmcat ) {
      $expectedCategories++;
      $items = $mmcat->MenuItemCategory->MenuItemCategoryItems->MenuItemCategoryItem ?? [];
      if ( is_array( $items ) ) {
        $expectedItems += count( $items );
      } elseif ( is_object( $items ) ) {
        $expectedItems += 1;
      }
    }

    // 1. Stale-delete protection: item IDs of this menu's existing products.
    $itemIds = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT pm_item.meta_value
           FROM {$wpdb->postmeta} pm_item
          INNER JOIN {$wpdb->postmeta} pm_site
                  ON pm_site.post_id = pm_item.post_id
                 AND pm_site.meta_key = '_siteid'
                 AND pm_site.meta_value = %s
          INNER JOIN {$wpdb->postmeta} pm_menu
                  ON pm_menu.post_id = pm_item.post_id
                 AND pm_menu.meta_key = '_menuid'
                 AND pm_menu.meta_value = %s
          WHERE pm_item.meta_key = '_itemid'",
        $siteId,
        $menuId
      )
    );

    // ECM-disabled items must not be re-protected from stale cleanup, even on a
    // hash-skipped menu (OE-26435) — otherwise items disabled before the fix
    // shipped would stay shielded for as long as the menu payload is unchanged.
    $inactivePayloadIds = [];
    foreach ( $this->ensureArray( $siteMenuItems ?? [] ) as $payloadItem ) {
      if ( isset( $payloadItem->MenuItemId ) && ! WoapiProductAdapter::isMenuItemActive( $payloadItem ) ) {
        $inactivePayloadIds[ (string) $payloadItem->MenuItemId ] = true;
      }
    }
    if ( ! empty( $inactivePayloadIds ) ) {
      $registeredBefore = count( $itemIds );
      $itemIds = array_values( array_filter(
        $itemIds,
        fn ( $id ) => ! isset( $inactivePayloadIds[ (string) $id ] )
      ) );
      if ( $registeredBefore !== count( $itemIds ) ) {
        CBSLogger::products()->info( 'registerSkippedMenuState: excluded inactive items from re-registration', [
          'site_id' => $siteId,
          'menu_id' => $menuId,
          'count'    => $registeredBefore - count( $itemIds ),
          'itemIds'  => array_keys( $inactivePayloadIds ),
        ] );
      }
    }

    // 2. Category-delete protection: this menu's terms (queried before the
    //    guard so both checks see real data).
    $termIds = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT DISTINCT tm_menu.term_id
           FROM {$wpdb->termmeta} tm_menu
          INNER JOIN {$wpdb->termmeta} tm_site
                  ON tm_site.term_id = tm_menu.term_id
                 AND tm_site.meta_key = 'site_id'
                 AND tm_site.meta_value = %s
          WHERE tm_menu.meta_key = 'menu_id'
            AND tm_menu.meta_value = %s",
        $siteId,
        $menuId
      )
    );

    if ( ( $expectedItems > 0 && empty( $itemIds ) )
      || ( $expectedCategories > 0 && empty( $termIds ) ) ) {
      CBSLogger::products()->warning( 'Menu hash matched but WP state could not be re-registered — processing menu normally', [
        'site_id' => $siteId,
        'menu_id' => $menuId,
        'expected_items'      => $expectedItems,
        'registered_items'    => count( $itemIds ),
        'expected_categories' => $expectedCategories,
        'registered_terms'    => count( $termIds ),
      ] );
      return false;
    }

    if ( ! isset( $this->deployedItemIdsBySite[ $siteId ] ) ) {
      $this->deployedItemIdsBySite[ $siteId ] = [];
    }
    $this->deployedItemIdsBySite[ $siteId ] = array_values( array_unique(
      array_merge( $this->deployedItemIdsBySite[ $siteId ], array_map( 'strval', $itemIds ) )
    ) );

    // Record this menu's active item set for the per-menu _menuid prune as well, so a
    // menu that is skipped (payload unchanged) still has its stale _menuid rows cleaned
    // on the first post-fix deploy — $itemIds is already inactive-filtered above
    // (OE-26435).
    $this->deployedItemIdsBySiteMenu[ $siteId ][ $menuId ] = array_values( array_unique(
      array_map( 'strval', $itemIds )
    ) );

    // Re-register active category term IDs for this skipped menu so the end-of-site
    // deleteStaleMenuCategoryMeta() prune does not treat them as stale.
    //
    // Using the DB-queried $termIds (not the API payload) is intentional and correct.
    // A menu is only skipped when its hash matches the last successful deploy, which
    // guarantees DB state == API state for that menu. Any category removal from the menu
    // would change the hash, forcing normal processing — so stale term meta cannot exist
    // for a legitimately skipped menu. The resulting "DB set compared against itself"
    // in deleteStaleMenuCategoryMeta produces an empty diff, which is the right answer:
    // nothing is stale. This mirrors the same DB-sourced pattern used for $itemIds above.
    $this->deployedCategoryTermIdsBySiteMenu[ $siteId ][ $menuId ] = array_values( array_unique(
      array_map( 'intval', $termIds )
    ) );

    $protectedTerms = 0;
    foreach ( array_map( 'intval', $termIds ) as $termId ) {
      if ( ! array_key_exists( $termId, $this->savedCategoriesPerMenu ) ) {
        $this->savedCategoriesPerMenu[ $termId ] = '(preserved — menu unchanged)';
        $protectedTerms++;
      }
      // Linked subcategories are children of their parent category term.
      $children = get_term_children( $termId, 'product_cat' );
      if ( is_array( $children ) ) {
        foreach ( $children as $childId ) {
          $childId = (int) $childId;
          if ( ! array_key_exists( $childId, $this->savedCategoriesPerMenu ) ) {
            $this->savedCategoriesPerMenu[ $childId ] = '(preserved — menu unchanged)';
            $protectedTerms++;
          }
        }
      }
    }

    // Populate termExpectedItemIds so removeStaleTermProducts() can detect and
    // remove contaminated product-term assignments even when the menu is skipped.
    // Build catId → termId using the already-fetched $termIds, then walk the
    // category structure to record which item IDs belong to each term.
    $catToTerm = [];
    foreach ( array_map( 'intval', $termIds ) as $termId ) {
      $catIds = get_term_meta( $termId, 'menu_item_category_id', false );
      foreach ( $catIds as $catId ) {
        $catToTerm[ (string) $catId ] = $termId;
      }
    }
    foreach ( $this->ensureArray( $menuDefinition->MenuMenuCategories->MenuMenuCategory ?? [] ) as $mmcat ) {
      $catId = (string) ( $mmcat->MenuItemCategory->MenuItemCategoryId ?? '' );
      if ( empty( $catId ) || ! isset( $catToTerm[ $catId ] ) ) {
        continue;
      }
      $tId      = $catToTerm[ $catId ];
      $catItems = $mmcat->MenuItemCategory->MenuItemCategoryItems->MenuItemCategoryItem ?? [];
      foreach ( $this->ensureArray( $catItems ) as $catItem ) {
        $itemKey = (string) ( $catItem->MenuItemId ?? '' );
        if ( ! empty( $itemKey ) ) {
          $this->termExpectedItemIds[ $tId ][ $itemKey ] = true;
        }
      }
    }

    // 3. Progress: count this menu's items as processed.
    $this->progressProcessedProducts += $expectedItems;
    $this->maybeFlushProgress( true );

    CBSLogger::products()->info( 'Menu unchanged since last successful deploy — skipped', [
      'site_id' => $siteId,
      'menu_id' => $menuId,
      'items_registered' => count( $itemIds ),
      'terms_protected'  => $protectedTerms,
      'items_counted'    => $expectedItems,
      'run_id'           => $this->currentRunId,
    ] );

    if ( ! empty( $this->currentRunId ) ) {
      DeployRunRepository::create()->appendEvent(
        $this->currentRunId,
        DeployRunRepository::EVENT_MENU_SKIPPED_UNCHANGED,
        DeployRunRepository::SEVERITY_INFO,
        'Menu payload unchanged since last successful deploy — processing skipped.',
        [
          'site_id' => $siteId,
          'menu_id' => $menuId,
          'itemCount' => $expectedItems,
        ]
      );
    }

    return true;
  }

  /**
   * Fetch the four per-site deploy endpoints for all in-scope Default sites
   * concurrently using the Requests library bundled with WordPress
   * (Requests::request_multiple — parallel curl under the hood).
   *
   * Populates:
   *   - $this->prefetchedRootResponses  (/sites/{id}/menu/)
   *   - $this->prefetchedRules          (/sites/{id}/rules/)
   *   - $this->prefetchedServingOptions (/sites/{id}/servingOptionCategories/)
   *   - $this->unavailableItemsCache    (/sites/{id}/unavailableitems/)
   *
   * Strictly best-effort: any missing/failed/unparseable response simply
   * leaves its slot empty, and the existing sequential or lazy fetch paths
   * cover it. If the Requests classes are unavailable (very old WP) the
   * method is a no-op and the deploy behaves exactly as before.
   *
   * @param array $sites Configured site rows.
   * @return void
   */
  private function prefetchSiteEndpointsParallel( array $sites ): void
  {
    $eligible = [];
    foreach ( $sites as $site ) {
      if ( ( $site->menu_type ?? '' ) === 'Default' && $this->isSiteInScope( $site->siteid ) ) {
        $eligible[] = (string) $site->siteid;
      }
    }

    if ( empty( $eligible ) ) {
      return;
    }

    $keyedUrls = [];
    foreach ( $eligible as $siteId ) {
      $base = $this->instanceOeapiUrl . self::SITES . $siteId;
      $keyedUrls[ 'root|' . $siteId ]           = $base . '/menu/';
      $keyedUrls[ 'rules|' . $siteId ]          = $base . '/rules/';
      $keyedUrls[ 'servingOptions|' . $siteId ] = $base . '/servingOptionCategories/';
      $keyedUrls[ 'unavailable|' . $siteId ]    = $base . '/unavailableitems/';
      // Same host/path Connection::getConfiguration() builds for
      // ProductManager::menuItemDates() ('/menuitems', no trailing slash) — so a
      // prefetch hit and the sequential fallback hit the identical endpoint
      // (product-active-date-window / OE-26686: one parallel call per site
      // instead of a synchronous fetch mid-deploy-loop for every site).
      $keyedUrls[ 'menuitems|' . $siteId ]      = $base . '/menuitems';
    }

    foreach ( $this->parallelGetJson( $keyedUrls ) as $key => $decoded ) {
      [ $kind, $siteId ] = explode( '|', $key, 2 );

      switch ( $kind ) {
        case 'root':
          $this->prefetchedRootResponses[ $siteId ] = $decoded;
          break;
        case 'rules':
          $this->prefetchedRules[ $siteId ] = $decoded;
          break;
        case 'servingOptions':
          $this->prefetchedServingOptions[ $siteId ] = $decoded;
          break;
        case 'unavailable':
          // Mirror ProductManager::unavailableData() — cache the final item
          // list and the unavailable-components set from the same response,
          // so getUnavailableItemsForSite() / getUnavailableComponentsSetForSite()
          // consumers see an identical shape whether prefetched or lazily fetched.
          if ( ! empty( $decoded->Ok ) && is_object( $decoded->Data ?? null ) ) {
            $this->unavailableItemsCache[ $siteId ]      = $this->ensureArray( $decoded->Data->UnavailableMenuItems ?? [] );
            $this->unavailableComponentsCache[ $siteId ] = $this->buildUnavailableSet( $this->ensureArray( $decoded->Data->UnavailableComponents ?? [] ) );
          }
          break;
        case 'menuitems':
          // Mirror getMenuItemDatesForSite()'s success-path transform (raw item
          // list -> [MenuItemId => item] lookup) so a prefetch hit and its
          // sequential-fallback live call agree byte-for-byte. On failure/
          // malformed response, leave the cache unset (same as 'unavailable'
          // above) — getMenuItemDatesForSite()'s own live fallback then retries
          // and records the failure itself (product-active-date-window).
          if ( ! empty( $decoded->Ok ) ) {
            $lookup = [];
            foreach ( $this->ensureArray( $decoded->Data ?? [] ) as $item ) {
              if ( isset( $item->MenuItemId ) ) {
                $lookup[ (string) $item->MenuItemId ] = $item;
              }
            }
            $this->menuItemDatesCache[ $siteId ] = $lookup;
          }
          break;
      }
    }

    CBSLogger::products()->info( 'Parallel prefetch complete', [
      'sites'           => count( $eligible ),
      'root'            => count( $this->prefetchedRootResponses ),
      'rules'           => count( $this->prefetchedRules ),
      'serving_options' => count( $this->prefetchedServingOptions ),
      'unavailable'     => count( $this->unavailableItemsCache ),
      'menuitems'       => count( $this->menuItemDatesCache ),
    ] );
  }

  /**
   * Fetch a set of URLs concurrently and return [key => decoded JSON object].
   *
   * The shared transport for every parallel batch in the deploy (site
   * endpoints, per-menu payloads, ECM media items). Uses the Requests library
   * bundled with WordPress; requests are chunked to bound concurrency.
   * Strictly best-effort: keys whose request failed, returned non-2xx, or
   * produced unparseable JSON are simply absent from the result, and callers
   * fall back to their sequential fetch paths. Returns [] when the Requests
   * classes are unavailable.
   *
   * @param array<string,string> $keyedUrls [resultKey => absolute URL]
   * @param string               $tokenType Authorization scheme — 'Token' for
   *                                        OEAPI endpoints, 'Bearer' for ECM
   *                                        (matching WoapiRequest's defaults
   *                                        for each caller).
   * @return array<string,object> [resultKey => decoded JSON]
   */
  private function parallelGetJson( array $keyedUrls, string $tokenType = 'Token' ): array
  {
    if ( empty( $keyedUrls ) ) {
      return [];
    }

    if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
      $requestsClass = '\WpOrg\Requests\Requests';
    } elseif ( class_exists( '\Requests' ) ) {
      $requestsClass = '\Requests'; // Pre-6.2 WordPress alias.
    } else {
      return []; // No parallel transport available — sequential paths take over.
    }

    // Requests per parallel chunk — bounds simultaneous connections.
    $chunkSize = max( 1, (int) apply_filters( 'cbs_deploy_parallel_requests', 24 ) );

    $headers = [
      'Content-Type'         => 'application/json',
      'Authorization'        => $tokenType . ' ' . $this->siteToken,
      'TransactionReference' => \CBSNorthStar\Helpers\SessionReference::get(),
    ];

    $options = [
      'timeout'         => (int) apply_filters( 'cbs_deploy_prefetch_timeout', 45 ),
      'connect_timeout' => (int) apply_filters( 'cbs_deploy_prefetch_connect_timeout', 10 ),
    ];

    $results = [];

    foreach ( array_chunk( $keyedUrls, $chunkSize, true ) as $chunk ) {
      $requests = [];
      foreach ( $chunk as $key => $url ) {
        $requests[ $key ] = [
          'url'     => $url,
          'headers' => $headers,
          'type'    => $requestsClass::GET,
        ];
      }

      try {
        $responses = $requestsClass::request_multiple( $requests, $options );
      } catch ( \Throwable $e ) {
        CBSLogger::products()->warning( 'Parallel batch failed — sequential fallback will cover it', [
          'error'    => $e->getMessage(),
          'requests' => count( $chunk ),
        ] );
        continue;
      }

      foreach ( $responses as $key => $response ) {
        // Failed transports come back as exception objects in the result array.
        if ( ! is_object( $response ) || ! isset( $response->body ) || empty( $response->success ) ) {
          CBSLogger::products()->debug( 'Parallel request unusable — sequential fallback will cover it', [
            'request' => $key,
          ] );
          continue;
        }

        $decoded = json_decode( $response->body );
        if ( is_object( $decoded ) ) {
          $results[ $key ] = $decoded;
        }
      }
    }

    return $results;
  }

  /**
   * Normalize an OEAPI node into an array of records.
   *
   * The API serializes a collection with one member as a bare object and a
   * collection with many members as an array. Callers that foreach over the
   * raw node break on the single-member case (they iterate properties, not
   * records). Wrapping a lone object in a one-element array fixes that.
   *
   * @param mixed $value
   * @return array
   */
  private function ensureArray( $value ): array
  {
    if ( is_array( $value ) ) {
      return $value;
    }
    if ( $value === null || $value === '' ) {
      return [];
    }
    return [ $value ];
  }

  /**
   * Build the complete set of daypart-menu rows for a site/area.
   *
   * Each row is only emitted when its MenuId actually exists in the site's
   * menu definitions (a daypart pointing at a missing menu is skipped rather
   * than silently dropped after the table was cleared). Rows are de-duplicated
   * on (menuid, daypartid) so a single transactional insert never collides
   * with the UNIQUE (siteid, menuid, daypartid) key.
   *
   * @param string $siteId
   * @param mixed  $areaId
   * @param array  $siteMenuDefinitionsMenu  Normalized menu definitions.
   * @return array[] Rows ready for DaypartMenusRepository::replaceSiteMenus().
   */
  private function buildDaypartRows( string $siteId, $areaId, array $siteMenuDefinitionsMenu ): array
  {
    // Index known MenuIds for an O(1) existence check.
    $knownMenuIds = [];
    foreach ( $siteMenuDefinitionsMenu as $menuDef ) {
      $knownMenuIds[ (string) $menuDef->MenuId ] = true;
    }

    $rows     = [];
    $seenKeys = [];

    foreach ( $this->areaDayPartMenu as $areasDayPartMenu ) {
      if ( $areasDayPartMenu->AreaId !== $areaId ) {
        continue;
      }

      if ( ! isset( $knownMenuIds[ (string) $areasDayPartMenu->MenuId ] ) ) {
        CBSLogger::products()->warning( 'Daypart references a MenuId not present in menu definitions — skipping', [
          'site_id' => $siteId,
          'menu_id' => $areasDayPartMenu->MenuId,
          'daypartId' => $areasDayPartMenu->DayPartId,
        ] );
        continue;
      }

      foreach ( $this->dayparts as $daypart ) {
        if ( $daypart->DayPartId != $areasDayPartMenu->DayPartId ) {
          continue;
        }

        $key = $areasDayPartMenu->MenuId . '|' . $areasDayPartMenu->DayPartId;
        if ( isset( $seenKeys[ $key ] ) ) {
          continue;
        }
        $seenKeys[ $key ] = true;

        // DaysOfWeek->DayOfWeek is also single-object-prone (one day = a bare
        // string, which would make implode() fatal under PHP 8).
        $daysOfWeek = $this->ensureArray( $daypart->DaysOfWeek->DayOfWeek ?? [] );

        $rows[] = [
          'menuid'       => $areasDayPartMenu->MenuId,
          'daypartid'    => $areasDayPartMenu->DayPartId,
          'starttime'    => $daypart->StartTime,
          'endtime'      => $daypart->EndTime,
          'days'         => implode( ', ', $daysOfWeek ),
          'displayorder' => (int) $areasDayPartMenu->MenuDisplayOrder,
          'siteid'       => $siteId,
        ];
      }
    }

    return $rows;
  }

  /**
   * Fetch all existing products for the given item IDs in a single query.
   * Returns [itemId => postId].
   *
   * @param string[] $itemIds
   * @return array<string, int>
   */
  private function prefetchProductMap( array $itemIds ): array
  {
    if ( empty( $itemIds ) ) {
      return [];
    }

    global $wpdb;
    $placeholders = implode( ',', array_fill( 0, count( $itemIds ), '%s' ) );

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT p.ID, pm.meta_value AS item_id
           FROM {$wpdb->posts} p
           INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
          WHERE p.post_type   = 'product'
            AND p.post_status IN ('publish', 'trash')
            AND pm.meta_key   = '_itemid'
            AND pm.meta_value IN ($placeholders)",
        ...$itemIds
      )
    );

    $map = [];
    foreach ( $rows as $row ) {
      $map[ $row->item_id ] = (int) $row->ID;
    }
    return $map;
  }

  /**
   * Get menu data by site id and menu id
   *
   * @param string $siteId site id.
   * @param string $menuId menu id.
   * @return array
   */
  protected function retrieveMenuData($siteId, $menuId)
  {
    $url = $this->instanceOeapiUrl . '/sites/' . $siteId . '/menu/' . $menuId;
    CBSLogger::products()->debug('Retrieving menu data', ['url' => $url]);
    $token = $this->siteToken;
    $tokenType = 'Token';


    return $this->getApiData($url, $token, $tokenType);
  }

  /**
   * Reverse-validation pass: for every term tracked during this deploy, query
   * all products currently assigned to it and remove any product whose _itemid
   * does not appear in the expected item set for that term.
   *
   * This repairs pre-existing contaminated assignments (e.g. products from
   * Category A that ended up on the Category B term when both shared a single
   * WP term via a corrupted menu_item_category_id meta row).
   *
   * Only OLO-managed products (those with a non-empty _itemid meta) are
   * candidates for removal; manually created products are always preserved.
   *
   * OE-26454: this is now the ONLY cross-term reconciliation pass. The inline
   * removeStaleSiteCategoryTerms() helper (which stripped a product from every
   * other same-site term on each assignment) was removed because it enforced
   * one-category-per-product-per-site and so dropped legitimately shared items
   * from all but the last-processed category (e.g. two categories holding the
   * same items, or a LinkToDataJson sub-category). This pass is correct for
   * that case: it runs once after every category is known, checks each term
   * against its own expected item set, and therefore keeps an item on EVERY
   * term it legitimately belongs to while still removing genuine contamination.
   */
  private function removeStaleTermProducts(): void {
    if ( empty( $this->termExpectedItemIds ) ) {
      return;
    }

    foreach ( $this->termExpectedItemIds as $termId => $expectedItemIds ) {
      $productIds = get_objects_in_term( (int) $termId, 'product_cat' );
      if ( is_wp_error( $productIds ) || empty( $productIds ) ) {
        continue;
      }

      foreach ( $productIds as $productId ) {
        $itemId = get_post_meta( (int) $productId, '_itemid', true );
        if ( empty( $itemId ) ) {
          // Not an OLO-managed product — do not touch.
          continue;
        }
        if ( isset( $expectedItemIds[ (string) $itemId ] ) ) {
          // This product legitimately belongs to this term.
          continue;
        }
        // Product is on this term but its _itemid is not in the expected set —
        // it was contaminated from another category in a previous deploy.
        wp_remove_object_terms( (int) $productId, (int) $termId, 'product_cat' );
        CBSLogger::products()->info( 'Removed contaminated product from category term', array(
          'post_id' => $productId,
          'term_id' => $termId,
          'item_id' => $itemId,
        ) );
      }
    }
  }

  /**
   * Enforce exactly one menu_item_category_id row on a term.
   *
   * A term must hold exactly one menu_item_category_id value — the one the
   * current API sync considers correct ($catId). Any other rows (wrong values
   * or same-value duplicates) are deleted and replaced with a single correct row.
   *
   * Called from every fallback path that writes this meta; the primary ID-match
   * path enforces the same invariant inline before calling this method.
   *
   * @param int    $termId  WC product_cat term_id.
   * @param string $catId   Authoritative menu_item_category_id from the API.
   * @param string $siteId  Current site ID (for logging only).
   * @param string $menuId  Current menu ID (for logging only).
   */
  private function safeAddCategoryIdMeta( int $termId, string $catId, string $siteId, string $menuId ): void {
    $existing = get_term_meta( $termId, 'menu_item_category_id', false );

    if ( count( $existing ) === 1 && (string) $existing[0] === (string) $catId ) {
      return; // Already exactly correct — nothing to do.
    }

    if ( ! empty( $existing ) ) {
      CBSLogger::products()->warning( 'Replacing incorrect/duplicate menu_item_category_id on term', array(
        'term_id'    => $termId,
        'old_ids'    => $existing,
        'correct_id' => $catId,
        'site_id'    => $siteId,
        'menu_id'    => $menuId,
      ) );
      delete_term_meta( $termId, 'menu_item_category_id' );
    }

    add_term_meta( $termId, 'menu_item_category_id', $catId, false );
  }
}
