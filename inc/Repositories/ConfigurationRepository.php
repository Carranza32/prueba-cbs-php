<?php

namespace CBSNorthStar\Repositories;

class ConfigurationRepository
{
    protected $db;
    private static $instance = null;
    private function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;

        return $this;
    }

    public static function create(): ?ConfigurationRepository
    {
        if (self::$instance === null) {
            self::$instance = new ConfigurationRepository();
        }

        return self::$instance;
    }

    public function getDetails()
    {
        $token = $this->db->get_results( "
            SELECT
                id,
                token,
                instance,
                created_at,
                updated_at
            FROM cbs_configure_details order by id desc limit 1
        " );

        return empty($token) ? [] : $token[0];
    }

    public function getSiteDetails($tokenId)
    {
      $query = $this->db->prepare("
        SELECT
          site.id,
          site.siteid,
          site.site_name,
          site.menu_type,
          site.areaid,
          site.area_name,
          site.address1,
          site.address2,
          site.state,
          site.city,
          site.zipcode,
          configuration.id configuration_id,
          configuration.token,
          configuration.instance
        FROM cbs_site_details as site
        inner join cbs_configure_details as configuration on site.config_id = configuration.id
            where site.menu_type!= %s AND site.config_id= %s
      ", ['Disabled', (string) $tokenId]);

      return $this->db->get_results($query);
    }
    public function getSitesDetails($tokenId){
        $query = $this->db->prepare("
            SELECT *
            FROM cbs_site_details as cbs_site_details
            inner join cbs_configure_details as cbs_configure_details on cbs_site_details.config_id = cbs_configure_details.id where cbs_site_details.config_id= %s",
            [$tokenId]);
        
        return $this->db->get_results($query);
    }

    public function getInstance($instance)
    {
        $instance = $this->db->get_results(
            "SELECT
                  id,
                  instance_name,
                  instance_ecmurl,
                  instance_oeapiurl,
                  created_at
              FROM cbs_instances
              where instance_name='$instance'"
        );

        return empty($instance) ? [] : $instance[0];
    }
    public function getInstances() : array
    {
        $instances = $this->db->get_results("SELECT * FROM cbs_instances");

        return empty($instances) ? [] : $instances;
    }

    public function getWebhook($siteId , $type)
    {
        $webhook = $this->db->get_results(
            "SELECT * FROM cbs_webhook_registration 
             where siteid='" . $siteId . "'
            and webhooktype='".$type."'"
        );

        return empty($webhook) ? [] : $webhook[0];
    }
    public function deleteWebhook()
    {
        $result = $this->db->query(
            "DELETE FROM cbs_webhook_registration"
        );
        return $result !== false;
    }

    public function upsertWebhook( string $siteId, int $tokenId, string $webhookId, string $secret, string $webhookUrl, string $webhookType ): bool
    {
        $existing = $this->getWebhook( $siteId, $webhookType );

        if ( ! empty( $existing ) ) {
            $result = $this->db->update(
                'cbs_webhook_registration',
                [
                    'tokenid'    => $tokenId ?: $existing->tokenid,
                    'webhookid'  => $webhookId,
                    'secret'     => $secret ?: $existing->secret,
                    'webhookurl' => $webhookUrl,
                ],
                [
                    'siteid'      => $siteId,
                    'webhooktype' => $webhookType,
                ]
            );
            return $result !== false;
        }

        $result = $this->db->insert(
            'cbs_webhook_registration',
            [
                'siteid'      => $siteId,
                'tokenid'     => $tokenId,
                'webhookid'   => $webhookId,
                'secret'      => $secret,
                'webhookurl'  => $webhookUrl,
                'webhooktype' => $webhookType,
                'callbackdata' => '',
            ]
        );
        return $result !== false;
    }

    public function deleteWebhookBySiteAndType( string $siteId, string $webhookType ): bool
    {
        $result = $this->db->delete(
            'cbs_webhook_registration',
            [
                'siteid'      => $siteId,
                'webhooktype' => $webhookType,
            ]
        );
        return $result !== false;
    }

    public function getAreaId($siteId)
    {
        $areaId = $this->db->get_results(
            "SELECT areaid FROM cbs_site_details WHERE siteid = '" . esc_sql($siteId) . "'",
            ARRAY_A
        );

        return empty($areaId) ? [] : $areaId[0];
    }
}

