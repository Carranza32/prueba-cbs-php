<?php

namespace CBSNorthStar\Woapi;

use CBSNorthStar\Helpers\WoapiRequest;
use CBSNorthStar\Repositories\ConfigurationRepository;

$root = dirname(__FILE__) . "/../../../../../";

if (file_exists($root . '/wp-load.php')) {
// WP 2.6
require_once $root . 'wp-load.php' ;
} else {
// Before 2.6
require_once $root . 'wp-config.php';
}

class Connection {

    protected ?ConfigurationRepository $configuration;
    public function __construct()
    {
        $this->configuration = ConfigurationRepository::create();
    }

    /**
     * Get request for WOAPI
     * @param $siteId
     * @param $url
     * @param $tokenType
     * @return mixed
     */
    public function getData($siteId, $url, $tokenType){

        list($token, $url) = $this->getConfiguration($siteId, $url);

        return WoapiRequest::create()->get($url,[
          'token'     => $token,
          'tokenType' => $tokenType
        ]);
    }
    /**
     * @param int|null $timeout Request timeout in seconds; null keeps the
     *                          transport default (45s, see RemoteRequestAbstract).
     */
    public function postData($siteId, $url, $tokenType, $northJson, $timeout = null ){
        list($token, $url) = $this->getConfiguration($siteId, $url);

        return WoapiRequest::create()->post($url,[
          'body'      => $northJson,
          'token'     => $token,
          'tokenType' => $tokenType,
          'timeout'   => $timeout
        ]);
    }

  public function especialPostData($siteId, $url, $tokenType, $northJson , $timeout = null ){
        list($token, $url) = $this->getConfiguration($siteId, $url);
 
        return WoapiRequest::create()->especialPost($url,[
          'body'      => $northJson,
          'token'     => $token,
          'tokenType' => $tokenType,
          'timeout'   => $timeout
        ]);
    }
 
    /**
     * * DELETE request for WOAPI
     * *
     * * @param mixed $siteId
     * @param mixed $url
     * * @param mixed $tokenType
 
     * @param string|null $northJson Optional JSON body for the DELETE request.
 
     * @return mixed
     * */
 
   public function deleteData($siteId, $url, $tokenType)
   {
       list($token, $url) = $this->getConfiguration($siteId, $url);
       return WoapiRequest::create()->delete($url, [
           'token'     => $token,
           'tokenType' => $tokenType
         ]);
  }
  
    /**
     * @param $siteId
     * @param $url
     * @return array
     */
    protected function getConfiguration($siteId, $url): array
    {
        $configuration = $this->configuration->getDetails();
        $instanceName = $configuration->instance;
        $token = $configuration->token;
        $instance = $this->configuration->getInstance($instanceName);
        $instanceOEAPIUrl = $instance->instance_oeapiurl;
      $encodedSiteId = rawurlencode((string) $siteId);
      $url = "$instanceOEAPIUrl/sites/$encodedSiteId" . $url;

        return array($token, $url);
    }
}
