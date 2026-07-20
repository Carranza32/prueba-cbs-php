<?php
class Site {
    public $id; 
    public $name;
    public $status;
    public $payment;
    public $token;

    public function __construct( $id, $name, $status , $token , $payment) {
        $this->id = $id;
        $this->name = $name;
        $this->status = $status;
        $this->token = $token;
        $this->payment = $payment;
    }
}

class SiteManager {
    private $sites;
    private $token; 

    public function __construct($token) {
        global $wpdb;

        $this->token = $token;
        $siteData =  $wpdb->get_results("SELECT * FROM cbs_site_details as cbs_site_details inner join cbs_configure_details as cbs_configure_details on cbs_site_details.config_id =cbs_configure_details.id"); 
        
        foreach ($siteData as $key => $cbsdata ) {
            $this->sites[] = new Site( $cbsdata->siteid , $cbsdata->site_name, $cbsdata->menu_type, $cbsdata->token , $cbsdata->payment_control );
        }
    }

    public function getSites() {
        return $this->sites;
    }

    public function getAvailableSites() {
        $availableSites = array();
        foreach ($this->sites as $site) {
            if ($site->status == 'Default' && $site->token == $this->token) {
                $availableSites[] = $site;
            }
        }
        return $availableSites;
    }

    public function getSiteNames() {
        $siteNames = array();
        foreach ($this->sites as $site) {
            $siteNames[] = $site->name;
        }
        return $siteNames;
    }

    public function getPaymentSites(){
        $availableSites = array();
        $availableForpayment = array();

        $availableSites = $this->getAvailableSites(); 
        foreach($availableSites as $site){
            if( $site->payment !== 'None' ){
                $availableForpayment[] = $site; 
            }
        }
        return $availableForpayment;
    }

    public function getStripeSites(){
        $paymentSites = array();
        $stripeSites = array();

        $paymentSites = $this->getPaymentSites();
        foreach($paymentSites as $site){
            if($site->payment == 'stripe'){
                $stripeSites[] = $site;
            }
        }
        return $stripeSites; 
    }

    public function getBeyondSites(){
        $paymentSites = array();
        $beyondSites = array();

        $paymentSites = $this->getPaymentSites();
        foreach($paymentSites as $site){
            if($site->payment == 'beyond'){
                $beyondSites[] = $site;
            }
        }
        return $beyondSites; 
    }
    
    public function getCloverSites(){
        $paymentSites = array();
        $cloverSites = array();

        $paymentSites = $this->getPaymentSites();
        foreach($paymentSites as $site){
            if($site->payment == 'clover'){
                $cloverSites[] = $site;
            }
        }
        return $cloverSites; 
    }

    public function getAuthorizeSites(){
        $paymentSites = array();
        $authorizeSites = array();

        $paymentSites = $this->getPaymentSites();
        foreach($paymentSites as $site){
            if($site->payment == 'authorize'){
                $authorizeSites[] = $site;
            }
        }
        return $authorizeSites; 
    }
}