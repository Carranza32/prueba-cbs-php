<?php
require_once dirname(__FILE__).'/class_sites.php' ;

class SettingField {
    public $uid; 
    public $label; 
    public $section;
    public $type;
    public $options; 
    public $placeholder;
    public $helper; 
    public $suplemental; 
    public $default;

    public function __construct( $uid , $label, $section, $type , $options , $placeholder , $helper, $suplemental , $default ) {
        $this->uid = $uid;
        $this->label = $label;
        $this->section = $section;
        $this->type = $type;
        $this->options = $options;
        $this->placeholder = $placeholder ;
        $this->helper = $helper ;
        $this->suplemental = $suplemental;
        $this->default = $default;
    }
}
class FieldManager {
    public $key; 

    public function __construct( $id , $type) {
        if($type === 'beyond'){
            $this->key = new SettingField( $id.'_key' , 'Public Key', $id,  'text' , false, 'Add Key' , '', '', '' );
            $this->pKey = new SettingField( $id.'_private_key' , 'Private Key', $id,  'password' , false, 'Add Key' , false, false, '' );
            $this->username = new SettingField( $id.'_userName' , 'User Name', $id,  'text' , false, 'Add User Name' , false, false, '' );
            $this->password = new SettingField( $id.'_password' , 'Password', $id,  'password' , false, '*****' , false, false, '' );
            $this->merchantCode = new SettingField( $id.'_merchantCode' , 'Merchant Code', $id,  'text' , false, '' , false, false, '' );
            $this->merchantAccountCode = new SettingField( $id.'_merchantaccountcode' , 'Merchant Account Code', $id,  'text' , false, '' , false, false, '' );
        }else  if($type === 'stripe'){
            $this->key = new SettingField( $id.'_key' , 'Public Key', $id,  'text' , false, 'Add Key' , '', '', '' );
            $this->pKey = new SettingField( $id.'_private_key' , 'Private Key', $id,  'password' , false, 'Add Key' , false, false, '' );
            $this->wsecret = new SettingField( $id.'_webhook_scret' , 'WebHook Secret', $id,  'password' , false, '' , false, false, '' );
        }else if ($type === 'clover'){
            $this->merchantCode = new SettingField( $id.'_merchantId' , 'Merchant Id', $id,  'text' , false, '' , false, false, '' );
            $this->key = new SettingField( $id.'_key' , 'Public Key', $id,  'text' , false, 'Add Key' , '', '', '' );
            $this->pKey = new SettingField( $id.'_private_key' , 'Private Key', $id,  'password' , false, 'Add Key' , false, false, '' );
        }else if ($type === 'authorize'){
            $this->merchantCode = new SettingField( $id.'_login_Id' , 'Login Id', $id,  'text' , false, 'Add Login Id' , false, false, '' );
            $this->key = new SettingField( $id.'_key' , 'Public Key', $id,  'text' , false, 'Add Key' , '', '', '' );
            $this->pKey = new SettingField( $id.'_transaction_key' , 'Transaction Key', $id,  'text' , false, 'Add Key' , false, false, '' );
        }
    }

}

class PaymentSettings { 
    public $token;
    public $fields;
    public $data;

    //set sections 
    public function setup_sections() {
        global $wpdb;
        
        $tokendata = $wpdb->get_results("SELECT * FROM cbs_configure_details ORDER BY id DESC LIMIT 1");
        $token = $tokendata[0]->token;
        $this->token = $token;
        $site_manager = new SiteManager($token);
        $sitesBeyond = $site_manager-> getBeyondSites();
        foreach ($sitesBeyond as $site) {
            add_settings_section( $site->id , $site->name . ' - Beyond', false , 'payment-settings' );
        }
        $sitesStripe = $site_manager-> getStripeSites();
        foreach ($sitesStripe as $site) {
            add_settings_section( $site->id , $site->name . ' - Stripe' , false , 'payment-settings' );
        }
        $sitesClover = $site_manager-> getCloverSites();
        foreach ($sitesClover as $site) {
            add_settings_section( $site->id , $site->name . ' - Clover' , false , 'payment-settings' );
        }
        $sitesAuthorize = $site_manager-> getAuthorizeSites();
        foreach ($sitesAuthorize as $site) {
            add_settings_section( $site->id , $site->name . ' - Authorize' , false , 'payment-settings' );
        }


    }

    public function field_callback( $arguments ) {
        $value = get_option( $arguments['uid'] ); // Get the current value, if there is one
        if( ! $value ) { // If no value exists
            $value = $arguments['default']; // Set to our default
        }
    
        // Check which type of field we want
        switch( $arguments['type'] ){
            case 'text': // If it is a text field
                printf( '<input name="%1$s" id="%1$s" type="%2$s" placeholder="%3$s" value="%4$s" />', $arguments['uid'], $arguments['type'], $arguments['placeholder'], $value );
                break;
            case 'password': 
                printf( '<input name="%1$s" id="%1$s" type="%2$s" placeholder="%3$s" value="%4$s" type="password" />', $arguments['uid'], $arguments['type'], $arguments['placeholder'], $value );
                break;
        }
    
        // If there is help text
        if( $helper = $arguments['helper'] ){
            printf( '<span class="helper"> %s</span>', $helper ); // Show it
        }
    
        // If there is supplemental text
        if( $supplimental = $arguments['supplemental'] ){
            printf( '<p class="description">%s</p>', $supplimental ); // Show it
        }
    }

    public function setup_fields(){
        global $wpdb;
        
        $tokendata = $wpdb->get_results("SELECT * FROM cbs_configure_details ORDER BY id DESC LIMIT 1");
        $token = $tokendata[0]->token;
        $this->token = $token;
        //for loop call method field managerv and call a method inbside depdngin if is beyond or stripe 
        $site_manager = new SiteManager($token);
        $sitesBeyond = $site_manager-> getBeyondSites();
        $beyond = array();
        $beyond_fields = array();

        foreach ($sitesBeyond as $site) {

           $beyond[] = new FieldManager($site->id , 'beyond');
           foreach($beyond as $key => $fields){
            foreach($fields as $field){
                add_settings_field( $field->uid, $field->label , array( $this, 'field_callback' ) , 'payment-settings', $field->section , (array) $field  );
                register_setting( 'payment-settings', $field->uid );
            }
           }
        }
        /* return $beyond_fields; */
        $sitesStripe = $site_manager-> getStripeSites();
        foreach ($sitesStripe as $site) {
            $stripe[] = new FieldManager($site->id , 'stripe');
            foreach($stripe as $key => $fields){
             foreach($fields as $field){
                 add_settings_field( $field->uid, $field->label , array( $this, 'field_callback' ) , 'payment-settings', $field->section , (array) $field  );
                 register_setting( 'payment-settings', $field->uid );
             }
            }
        }

        /* Resturns Clover fields */
        $sitesClover = $site_manager-> getCloverSites();
        foreach ($sitesClover as $site) {
            $clover[] = new FieldManager($site->id , 'clover');
            foreach($clover as $key => $fields){
             foreach($fields as $field){
                 add_settings_field( $field->uid, $field->label , array( $this, 'field_callback' ) , 'payment-settings', $field->section , (array) $field  );
                 register_setting( 'payment-settings', $field->uid );
             }
            }
        }

        /* Resturns Clover fields */
        $sitesAuthorize = $site_manager-> getAuthorizeSites();
        foreach ($sitesAuthorize as $site) {
            $authorize[] = new FieldManager($site->id , 'authorize');
            foreach($authorize as $key => $fields){
             foreach($fields as $field){
                 add_settings_field( $field->uid, $field->label , array( $this, 'field_callback' ) , 'payment-settings', $field->section , (array) $field  );
                 register_setting( 'payment-settings', $field->uid );
             }
            }
        }
    }


}
