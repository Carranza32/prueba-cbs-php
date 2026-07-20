<?php

namespace CBSNorthStar\Init\Loaders;

use CBSNorthStar\Woapi\Connection;
use CBSNorthStar\Repositories\ConfigurationRepository;
use CBSNorthStar\Dto\CartOrderReviewDto;
use CBSNorthStar\Logger\CBSLogger;
use WP_Error;


class LoyaltyLoader implements JavaScriptLoaderContract
{
    private static $instance = null;

    public static function create(): ?LoyaltyLoader
    {
        if (self::$instance === null) {
            self::$instance = new LoyaltyLoader();
        }

        return self::$instance;
    }

    public function registerScripts()
    {
        add_action('wp_ajax_loyalty_action', array($this, 'ajaxHandler'));
        add_action('wp_ajax_nopriv_loyalty_action', array($this, 'ajaxHandler'));
        add_action('wp_ajax_redeem_loyalty_action', array($this, 'redeemRewards'));
        add_action('wp_ajax_nopriv_redeem_loyalty_action', array($this, 'redeemRewards'));
    }

    public function ajaxHandler(): void {
        if (isset($_POST['action']) && $_POST['action'] === 'loyalty_action') {
            $phoneNumber = $_POST['phoneNumber'] ?? '';
            $phoneNumber = preg_replace('/\D/', '', $phoneNumber);
            
            $siteId = $this->getSiteId();
            $areaId = $this->getAreaIdFromDb($siteId);

            $url = '/membership/phonenumber/'.$phoneNumber;
            $connection = new Connection();

            try {
                $response   = $connection->getData($siteId, $url, 'Token');
            } catch(\Exception $e) {
                CBSLogger::transactions()->error('Rewards exception', ['exception' => $e->getMessage()]);
                wp_send_json_error($e->getMessage());
            }

            $this->processLoyaltyRequest($response, $areaId, $siteId);
        }
    }

    private function getAreaIdFromDb($siteId) : string {
        global $wpdb;
        $table_name = 'cbs_site_details';
        return $wpdb->get_var( $wpdb->prepare("SELECT `areaid` FROM $table_name WHERE `siteid` = %s", $siteId ));
    }

    private function processLoyaltyRequest($response, $areaExternalCode, $siteId): void {
        $dataResponse = [];
        if($response->Ok) {
            $customerData = $response->Data;
            if($customerData[0]->LoyaltyAccount->Active){
                $loyaltyData = $this->getRewardsData($siteId, $customerData, $areaExternalCode);
                $this->initializeSession();
                WC()->session->set('customerData', $customerData);

                if($loyaltyData) {
                    $dataResponse = $loyaltyData;
                }
                else{
                    $dataResponse["ErrorMessage"] = 'No rewards available for this check';
                }
            }
            else{
                $dataResponse["ErrorMessage"] = 'No Active Loyalty Account';
            }
        }
        else{
            $dataResponse["ErrorMessage"] = $response->ErrorMessage ?? 'No response from API';
        }
        wp_send_json_success($dataResponse);
    }

    private function getRewardsData($siteId, $customerData, $areaExternalCode) {
        $checkNumber = $this->submitCheck($siteId, $customerData[0]->CustomerId, $areaExternalCode);
        $this->initializeSession();
        $checkTotal = 0;
        if(WC()->session->get('loyaltyData')) {
            $checkTotal = WC()->session->get('loyaltyData')['totalCheckAmount'];
        }

        if(!$checkNumber) {
            return null;
        }

        $url = '/loyalty/'.$checkNumber;
        $connection = new Connection();

        try {
            $response   = $connection->getData($siteId, $url, 'Token');
        } catch(\Exception $e) {
            CBSLogger::transactions()->error('Loyalty exception', ['exception' => $e->getMessage()]);
            return null;
        }

        if($response->Ok) {
            $data = $response->Data;
            $data->CheckTotal = $checkTotal;
        }
        else{
            $data = null;
        }

        return $data;
    }

    private function submitCheck($siteId,$customerId, $areaExternalCode) {
        if ( is_null( WC()->cart ) ) {
            WC()->frontend_includes();
            wc_load_cart();
        }
        $this->initializeSession();

        if(WC()->session->get('loyaltyData')) {
            $loyaltyData = WC()->session->get('loyaltyData');
            return $loyaltyData['checkNumber'];
        }

        WC()->cart->get_cart();
        $cart = WC()->cart;

        $items = $cart->get_cart();

        $deliveryDate = current_time('Y-m-d H:i:s');
        $check = (new CartOrderReviewDto([
            'order'         => $cart,
            'orderType'     => 0,
            'deliveryDate'  => $deliveryDate,
            'orderItems'    => $items,
            'tableNumber'   => "",
            'areaExternalCode'=> $areaExternalCode ?? null,
            'customerId'    => $customerId,
        ]))->toJson();

        $path = '/checks/00000000-0000-0000-0000-000000000000/submit';
        $connection = new Connection();
        try {
            $response = $connection->postData($siteId, $path, 'Token', $check);
        } catch (\Throwable $th) {
            CBSLogger::transactions()->error('Submit Check exception', ['exception' => $th->getMessage()]);
            return null;
        }
        $checkNumber = $response->Data->CheckNumber;
        $checkId = $response->Data->CheckId;
        $balance = $response->Data->Balance;

        $loyaltyData= [];
        $loyaltyData['checkNumber'] = $checkNumber;
        $loyaltyData['checkId'] = $checkId;
        $loyaltyData['totalCheckAmount'] = $balance;
        $loyaltyData['customerId'] = $customerId;

        WC()->session->set('loyaltyData', $loyaltyData);

        return $checkNumber;
    }

    public function redeemRewards() {
        if (isset($_POST['action']) && $_POST['action'] === 'redeem_loyalty_action') {
            $siteId = $this->getSiteId();
            $url = '/loyalty/redeem';
            $connection = new Connection();

            array_shift($_POST);
            $data = json_decode(json_encode($_POST), false);

            $this->initializeSession();
            $customerData = WC()->session->get('customerData');
            $data->CustomerId = $customerData[0]->CustomerId;
            
            try {
                $response = $connection->postData($siteId, $url, 'Token', json_encode($data));
            } catch(\Exception $e) {
                CBSLogger::transactions()->error('Redeem Rewards exception', ['exception' => $e->getMessage()]);
                wp_send_json_error($e->getMessage());
            }
            if($response->Ok) {
                $loyaltyData = WC()->session->get('loyaltyData');
                $loyaltyData['newCheck'] = null;
                WC()->session->set('loyaltyData', $loyaltyData);
                wp_send_json_success($response->Data);
            }
            wp_send_json_success($response->ErrorMessage);
        }
    }

    private function initializeSession(): void {
        if (!WC()->session) {
            WC()->session = new \WC_Session_Handler();
            WC()->session->init();
        }
    }

    private function getSiteId() {
        $configuration = ConfigurationRepository::create();
        $site = $configuration->getDetails();
        $siteDetails = $configuration->getSiteDetails($site->id);
        return $siteDetails[0]->siteid;
    }

}
