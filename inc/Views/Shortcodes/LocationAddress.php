<?php
namespace CBSNorthStar\Views\Shortcodes;

use CBSNorthStar\Set_sessions_for_site;
use DateTime;

class LocationAddress
{
    public function render($atts)
    {

        $siteId = null;
        if (isset($_COOKIE['siteid'])) {
            $siteId = $_COOKIE['siteid'];
        }
        elseif (isset($_GET['site_id'])) {
            $siteId = $_GET['site_id'];
        }
        else {
            $siteId = $atts['siteid'];
        }
        
        if (empty($siteId)) {
            return '';
        }

        // OE-25933: server always emits the header (data is cookie-independent).
        // Visibility is gated client-side via the locationSelected session cookie
        // so bfcache restores and any page caching stay consistent with cookie state.
        $hiddenClass = (empty($_COOKIE['locationSelected']) || $_COOKIE['locationSelected'] !== '1')
            ? ' location-info--hidden'
            : '';

        $siteName = (new Set_sessions_for_site())->getSiteName($siteId);
        $siteAddress = $this->getSiteAddress($siteId);
        ob_start();

        $ot=new DateTime($siteAddress->kitchenopentime);
        $opentime= $ot->format("h:i A");
        $ct= new DateTime($siteAddress->kitchenclosetime);
        $closetime= $ct->format("h:i A");
        ?>
    
    <div id="location-info" class="location-info<?php echo $hiddenClass; ?>" data-gate="location-selected">
        <div class="location-address-place">
            <div class="location-address__icon">
                <i class="fa fa-map-marker-alt" aria-hidden="true"></i>
            </div>
            <div>
                <p class="location-address">
                    <span class="location-address_site-name"><?php echo esc_html($siteName); ?></span>
                    <span class="location-address_address1"><?php echo esc_html($siteAddress->address1); ?>,</span>
                    <span class="location-address_city"><?php echo esc_html($siteAddress->city); ?>,</span>
                    <span class="location-address_state"><?php echo esc_html($siteAddress->state); ?></span>
                </p>
            </div>
        </div>
        <div class="location-address__time">
            <div class="location-time__icon">
                <i class="fa fa-clock" aria-hidden="true"></i>
            </div>
            <div>
                <p class="location-time">
                    <span class="location-time_open">Open: </span><?php echo $opentime . ' -  ' . $closetime; ?>
                </p>
            </div>
        </div>
    </div>
        <?php
        return ob_get_clean();
    }

    private function getSiteAddress($siteId)
    {
        global $wpdb;
        global $woocommerce;

        $siteAddress = $wpdb->get_results("SELECT address1, city, state, zipcode, countrycode, kitchenopentime, kitchenclosetime FROM cbs_site_details where siteid='" . $siteId . "'");
        if (!empty($siteAddress)) {
            $siteAddress = $siteAddress[0];
            return $siteAddress;
        }
        return $siteAddress;
    }
}
