<?php

namespace CBSNorthStar\Init\Loaders;

use CBSNorthStar\Models\Component;

class ComponentLoader implements JavaScriptLoaderContract
{

  private static $instance = null;

  public static function create(): ?ComponentLoader
  {
    if (self::$instance === null) {
      self::$instance = new ComponentLoader();
    }

    return self::$instance;
  }
  public function registerScripts()
  {
      add_action('wp_ajax_load_component_action_cbs', array($this, 'ajaxHandler'));
      add_action('wp_ajax_nopriv_load_component_action_cbs', array($this, 'ajaxHandler'));
  }

  public function ajaxHandler(): void
  {
    if (isset($_POST['action']) && $_POST['action'] === 'load_component_action_cbs') {
      $response = (new Component())->load($_POST['product_id']);
      wp_send_json_success($response);
    }
  }
}
