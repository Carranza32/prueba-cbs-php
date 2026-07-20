<?php

namespace CBSNorthStar\Init\Loaders;

use Carbon_Fields\Carbon_Fields;
use CBSNorthStar\Admin\SettingsPage;
use CBSNorthStar\CatalogCacheInvalidator;


class CarbonFieldsLoader
{
    private static $instance = null;

    public static function create(): ?CarbonFieldsLoader
    {
        if (self::$instance === null) {
            self::$instance = new CarbonFieldsLoader();
        }

        return self::$instance;
    }
    
    public function registerScripts()
    {
         add_action('after_setup_theme', [self::class, 'boot_carbon']);
         add_action('carbon_fields_register_fields', [SettingsPage::class, 'register']);
         add_action( 'admin_enqueue_scripts', [ self::class, 'enqueueAdminScripts' ] );
         // Invalidate the menu caches when a render-affecting setting changes (OE-26569).
         CatalogCacheInvalidator::register();
    }

    public static function boot_carbon() {
        Carbon_Fields::boot();
    }
    public static function enqueueAdminScripts( $hook )
    {
        if ( empty($_GET['page']) || $_GET['page'] !== 'olo-general-settings' ) {
            return;
        }

        wp_enqueue_script(
            'olo-carbon-tabs',
            plugin_dir_url( dirname( __FILE__ ) ) . '../../js/olo-carbon-tabs.js',
            [ 'jquery' ],
            '1.0',
            true
        );
    }
}
