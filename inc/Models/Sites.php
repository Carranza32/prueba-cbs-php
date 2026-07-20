<?php

namespace CBSNorthStar\Models;

use CBSNorthStar\Repositories\ConfigurationRepository;
use CBSNorthStar\Woapi\Connection;
use CBSNorthStar\Helpers\WoapiRequest;


class Sites{
    protected $instance;
    protected $siteId;
    protected $token;
    protected $status;

    public function load(): array
    {
        $response=array();

        $this->token =  $_POST['token'] ?? '';
        $this->instance = ConfigurationRepository::create()->getDetails()->instance;
        $this->siteId = ConfigurationRepository::create()->getDetails()->siteId;
        $this->tokenId = ConfigurationRepository::create()->getDetails()->id;
        $this->siteDetails = ConfigurationRepository::create()->getSitesDetails($this->tokenId);

        $response['instance'] = $this->instance;
        $response['siteId'] = $this->siteId;
        $response['token'] = $this->token;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $sites = $this->tokenRequest();
            $response['sites'] = $this->getActiveSites($this->siteDetails,$sites['response']);
        }else{
            $response['sites'] = $this->siteDetails;
        }
        return $response;
    }

    public function tokenRequest():array{
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['token'] ?? '';
            $instance = $_POST['instance'] ?? '';
            
            if($token && $instance){
                $data = $this->requestSiteData($token , $instance);
            }
            return ['token' => $token, 'instance' => $instance , 'response' => $data];

        }
        return [];
    }

    public function requestSiteData($token , $instance){
        $instanceUrl = ConfigurationRepository::create()->getInstance($instance)->instance_ecmurl;
        $urlEndpoint = $instanceUrl . '/ecm/api/v1/site/';
        $response = WoapiRequest::create()->get($urlEndpoint, [
            'token' => $token
        ]);
        return  $this->siteAdapter($response->Data , $instance);
    }
    public function requestSiteAreaDayParts($siteId , $token , $instance){
        $instanceUrl = $instance;
        $urlEndpoint = $instanceUrl . '/sites/' . $siteId ;
        $tokenType = 'Token';

        $response = WoapiRequest::create()->get($urlEndpoint, [
            'token' => $token,
            'tokenType' => $tokenType
        ]);
        return $this->getAreaNames($response->Data->WebOrderingAreas , $siteId , $token , $instance);
    }
    public function getAreaNames($webOrderingAreas , $siteId , $token , $instance){
        $urlEndpoint = $instance . '/sites/' . $siteId . '/areas';
        $tokenType = 'Token';

        $response = WoapiRequest::create()->get($urlEndpoint, [
            'token' => $token,
            'tokenType' => $tokenType
        ]);
       return $this->findNameById($webOrderingAreas , $response->Data);
    }
    public function findNameById($ids, $data) {
        $result = [];
        foreach ($ids as $id) {
            foreach ($data as $entry) {
                if ($entry->AreaId === $id) {
                    $result[$id] = $entry->Name;
                    break;
                }
            }
        }
        return $result;
    }

    
    public function siteAdapter($data , $instance ){
        $sites = [];
        foreach ($data as $site) {
            $siteObj =  new \stdClass();
            $siteObj->siteid = $site->SiteId;
            $siteObj->site_name = $site->Name;
            $siteObj->menu_type = 'Disabled';
            $siteObj->areaid = '';
            $siteObj->area_name = '';
            $siteObj->address1 = $site->Address1;
            $siteObj->address2 = $site->Address2;
            $siteObj->state = $site->State;
            $siteObj->city = $site->City;
            $siteObj->zipcode = $site->Zip;
            $siteObj->countrycode = $site->CountryCode;
            $siteObj->phone = $site->Phone;
            $siteObj->latitude = $site->Latitude;
            $siteObj->longitude = $site->Longitude;
            $siteObj->startofbusinesstime = $site->StartOfBusinessTime;
            $siteObj->startofbusinessweek = $site->StartOfBusinessWeek;
            $siteObj->kitchenopentime = $site->KitchenOpenTime;
            $siteObj->kitchenclosetime = $site->KitchenCloseTime;
            $siteObj->payperiodstartdate = $site->PayPeriodStartDate;
            $siteObj->payperiodstartime = $site->PayPeriodStartTime;
            $siteObj->isactive = $site->IsActive;
            $siteObj->enableseatNumber = $site->EnableSeatNumber;
            $siteObj->config_id = 0;
            $siteObj->pay_later_control = 'Disabled';
            $siteObj->payment_control = 'None';
            $siteObj->instance = $instance;
            
            $sites[] = $siteObj;
        }
        return $sites;
    }
    public function getActiveSites(array $oldData, array $newData): array {
        // Create a map for old data using siteid as key for quick lookup
        $oldDataMap = [];
        foreach ($oldData as $oldSite) {
            $oldDataMap[$oldSite->siteid] = $oldSite;
        }

        // Loop through new data and compare with old data
        foreach ($newData as $key => $newSite) {
            if (isset($oldDataMap[$newSite->siteid])) {
                $oldSite = $oldDataMap[$newSite->siteid];

                // Check if menu_type of the old site is not "Disabled"
                if ($oldSite->menu_type !== 'Disabled') {
                    // Modify menu_type of the new site to match the old site
                    $newSite->menu_type = $oldSite->menu_type;
                }

                // Update the newData array with the modified newSite object
                $newData[$key] = $newSite;
            }
        }

        return $newData;
    }
}