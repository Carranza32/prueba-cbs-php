<?php

namespace CBSNorthStar\Init\Loaders;

use CBSNorthStar\Models\ServingOption;

class ServingOptionLoader implements JavaScriptLoaderContract
{
    private static $instance = null;

    public static function create(): ?ServingOptionLoader
    {
        if (self::$instance === null) {
            self::$instance = new ServingOptionLoader();
        }

        return self::$instance;
    }
    
    public function registerScripts()
    {
        add_action('wp_ajax_load_serving_options_action_cbs', array($this, 'ajaxHandler'));
        add_action('wp_ajax_nopriv_load_serving_options_action_cbs', array($this, 'ajaxHandler'));
    }

    public function ajaxHandler(): void
    {
        if (isset($_POST['action']) && $_POST['action'] === 'load_serving_options_action_cbs') {
            $productId = filter_input(INPUT_POST, 'product_id', FILTER_SANITIZE_NUMBER_INT);
            $response = (array) (new ServingOption())->load($productId);
            wp_send_json_success($response);
        }
    }
}
