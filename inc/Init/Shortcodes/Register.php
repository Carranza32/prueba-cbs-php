<?php

namespace CBSNorthStar\Init\Shortcodes;

use CBSNorthStar\Views\Shortcodes\LocationPopUp;
use CBSNorthStar\Views\Shortcodes\OrderTypeToggle;
use CBSNorthStar\Views\Shortcodes\LocationAddress;
use CBSNorthStar\Views\Shortcodes\ViewAllShortcode;
use CBSNorthStar\Views\Shortcodes\LocationShortcode;
use CBSNorthStar\Views\Shortcodes\TimeslotInfoShortcode;

class Register
{

  private static $instance = null;

  public static function create(): ?Register
  {
    if (self::$instance === null) {
      self::$instance = new Register();
    }

    return self::$instance;
  }
  public function registerScripts()
  {
    $locationPopUp = new LocationPopUp();
    $orderTypeToggle = new OrderTypeToggle();
    $locationAddress = new LocationAddress();
    $viewAllShortcode = new ViewAllShortcode();
    $locationShortcode = new LocationShortcode();
    add_shortcode('siteid', [ $locationPopUp, 'render' ]);
    add_shortcode('order_type_toogle', [ $orderTypeToggle, 'render' ]);
    add_shortcode('location_address', [ $locationAddress, 'render' ]);
    add_shortcode('view_all_category', [ $viewAllShortcode, 'render' ]);
    add_shortcode('locations', [$locationShortcode, 'render']);
    add_shortcode('timeslot_info', [new TimeslotInfoShortcode(), 'render']);
  }
}
