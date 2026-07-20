<?php
/**
 * Cart Edit Loader
 *
 * Handles registration of cart edit functionality scripts and handlers.
 *
 * @package NorthstarOnlineOrdering
 */

namespace CBSNorthStar\Init\Loaders;

use CBSNorthStar\Controllers\CartEditController;

defined( 'ABSPATH' ) || exit;

/**
 * Class CartEditLoader
 *
 * Registers cart edit functionality following the plugin's loader pattern.
 */
class CartEditLoader {

    /**
     * Singleton instance
     *
     * @var CartEditLoader|null
     */
    private static ?CartEditLoader $instance = null;

    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {}

    /**
     * Create singleton instance
     *
     * @return CartEditLoader
     */
    public static function create(): CartEditLoader {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register scripts and handlers
     *
     * @return void
     */
    public function registerScripts(): void {
        CartEditController::init();
    }

}
