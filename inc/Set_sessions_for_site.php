<?php
namespace CBSNorthStar;


class Set_sessions_for_site {
    public $site_id; 
    public $site_name;
    public $location;
    public $area_id;
    public $token; 
    public $instance ;
    public $instance_url ;
    public $site_details ;
    public $paylater ;

    public function set_sessions (){
        global $wpdb;
        global $woocommerce;
    }
    public function getSiteName($siteId){
        global $wpdb;
        global $woocommerce;

        $getsitename = $wpdb->get_results( "SELECT site_name FROM cbs_site_details as cbs_site_details
        inner join cbs_configure_details as cbs_configure_details on cbs_site_details.config_id = cbs_configure_details.id where cbs_site_details.siteid='".$siteId ."'" );

        if(!empty($getsitename))
        {
        $this->site_name = $getsitename[0]->site_name;
        return $this->site_name;
        }
    }
    public function set_site_id (){
        if (!empty($_GET['site_id'])) {
            $this->site_id = sanitize_text_field( $_GET['site_id'] );
        } elseif (!empty($_COOKIE['siteid'])) {
            $this->site_id = sanitize_text_field( $_COOKIE['siteid'] );
        }
    }
    public function set_location (){
        $this->location = $_GET['location'];
    }
    public function set_area (){
        $this->area_id = $_GET['area'];
    }

    public function get_site_id (){
        return $this->site_id ;
    }
    public function get_location (){
        return $this->location ;
    }
    public function get_area (){
        return $this->area_id;
    }
    public function get_token (){
        global $wpdb;
        global $woocommerce;

        $token_res = $wpdb->get_results( "SELECT token,instance FROM cbs_configure_details order by id desc limit 1" );
        $this->instance = $token_res[0]->instance;
        $this->token= $token_res[0]->token;
        return $this->token;  
    }
    public function get_instance_url () {
        global $wpdb;
        global $woocommerce;

        $get_instance_url = $wpdb->get_results("SELECT instance_ecmurl,instance_oeapiurl FROM cbs_instances where instance_name='".$this->instance."'");
        $this->instance_url = $get_instance_url[0]->instance_oeapiurl;
        return $this->instance_url ; 
    }

    public function get_site_details() {
        global $wpdb;
        global $woocommerce;
        $this->site_details = $wpdb->get_results( "SELECT * FROM cbs_site_details as cbs_site_details inner join cbs_configure_details as cbs_configure_details on cbs_site_details.config_id = cbs_configure_details.id where cbs_site_details.siteid='".$this->site_id."' AND cbs_site_details.menu_type!='Disabled'" );
        return $this-> site_details; 

    }

    public function get_paylater(){
        global $wpdb;
        global $woocommerce;

        $this->paylater =  $wpdb->get_results("SELECT pay_later_control FROM cbs_site_details where siteid='".$_COOKIE['siteid']."'");
        return $this->paylater; 
    }


}

?>
