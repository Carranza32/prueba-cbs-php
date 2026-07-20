<?php

namespace CBSNorthStar\Repositories;

use CBSNorthStar\Logger\CBSLogger;

class CartRecordRepository
{
    protected $db;
    private static $instance = null;
    private function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;

        return $this;
    }

    public static function create(): ?CartRecordRepository
    {
        if (self::$instance === null) {
            self::$instance = new CartRecordRepository();
        }

        return self::$instance;
    }

    public function getCartData($locationId,$areaId)
    {
        $queryString = "SELECT * FROM  wp_woocommerce_cart_record WHERE location_number = %d AND area_id = %s";

        $query   = $this->db->prepare($queryString, $locationId, (string) $areaId );
        $results = $this->db->get_results($query);
        CBSLogger::cart()->debug('cart_record_query', ['results' => $results]);

        return $results;
    }

    public function deleteCartData($locationId, $areaId)
    {
        $deleteQuery = "DELETE FROM wp_woocommerce_cart_record WHERE location_number = %d AND area_id = %s";
        $deletedRows = $this->db->query($this->db->prepare($deleteQuery, $locationId, (string) $areaId));
        return $deletedRows !== false ? true : false;
   }
}
