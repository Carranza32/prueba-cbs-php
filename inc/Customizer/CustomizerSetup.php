<?php

namespace CBSNorthStar\Customizer;

use WP_Customize_Manager;
use Wpcot_Helper;

class CustomizerSetup{
    public static function register(WP_Customize_Manager $wp_customize) {
        
        $wp_customize->add_section('mail_login_cbs', array(
            'title' => 'CBS Mail Login',
            'priority' => 31,
        ));

        $wp_customize->add_setting('mail_login_cbs_mail_name', array(
            'default' => '',
            'type'    => 'option',
            'capability' => 'edit_theme_options',
        ));

        $wp_customize->add_control('mail_login_cbs_mail_control', array(
            'label' => 'Add mail separated by comma',
            'section' => 'mail_login_cbs',
            'settings' => 'mail_login_cbs_mail_name',
            'type' => 'textarea',
        ));

        
        $wp_customize->add_section('custom_label_cbs', array(
            'title' => 'Custom Site Labels',
            'priority' => 31,
        ));

        $wp_customize->add_setting('location_label_cbs', array(
            'default' => '',
            'type'    => 'option',
            'capability' => 'edit_theme_options',
        ));

        $wp_customize->add_control('location_label_control_cbs', array(
            'label' => 'Add custom label for location',
            'section' => 'custom_label_cbs',
            'settings' => 'location_label_cbs',
            'type' => 'textarea',
        ));

        $wp_customize->add_section('cbs_product_section', array(
            'title' => 'CBS Product',
            'priority' => 30,
        ));

        // Add Default Tipping Amount Field
        $wp_customize->add_setting('cbs_product_quantity', array(
            'default' => 1,
            'sanitize_callback' => 'absint',
            'type'       => 'option',
            'capability' => 'edit_theme_options',
        ));

        $wp_customize->add_control('cbs_product_option', array(
            'label' => 'Default amount',
            'section' => 'cbs_product_section',
            'settings' => 'cbs_product_quantity',
            'type' => 'number',
            'input_attrs' => array(
                'min' => 0,
                'max' => 100,
            ),
        ));

        $wp_customize->add_setting('outstock_product', array(
            'default' => '',
            'type'       => 'option',
            'capability' => 'edit_theme_options',
        ));

        $wp_customize->add_control('cbs_product_outstock', array(
            'label' => 'Out of stock product shortcode',
            'section' => 'cbs_product_section',
            'settings' => 'outstock_product',
            'type' => 'text',
        ));

        // Checkout  membership section

        $wp_customize->add_setting('cbs_membership_setting', array(
            'default' => false,
            'type'       => 'option',
            'capability' => 'edit_theme_options',
        ));
        
                
        $wp_customize->add_control('cbs_membership_option', array(
            'label' => 'Membership Button',
            'section' => 'cbs_product_section',
            'settings' => 'cbs_membership_setting',
            'type' => 'checkbox',
        ));

        // Add option to activate login into cart page
        $wp_customize->add_section('cbs_my_accout_section', array(
            'title' => 'My Account',
            'priority' => 20,
        ));

        $wp_customize->add_setting('cbs_login_cart', array(
            'default' => false,
            'type'    => 'option',
            'capability' => 'edit_theme_options',
        ));

        $wp_customize->add_control('cbs_login_cart_option', array(
            'label' => 'Enable login in cart page',
            'section' => 'cbs_my_accout_section',
            'settings' => 'cbs_login_cart',
            'type' => 'checkbox',
        ));

        $sectionName = 'Kiosk device settings';
        $wp_customize->add_section($sectionName, array(
            'title' => $sectionName,
            'priority' => 32,
        ));

        $wp_customize->add_setting('disable_kiosk_device_pin', array(
            'default' => false,
            'type'    => 'option',
            'capability' => 'edit_theme_options',
        ));

        $wp_customize->add_control('kiosk_device_pin_option', array(
            'label' => 'Disable Kiosk device pin',
            'section' => $sectionName,
            'settings' => 'disable_kiosk_device_pin',
            'type' => 'checkbox',
        ));

        $wp_customize->add_setting('disable_phone_field', array(
            'default' => false,
            'type'    => 'option',
            'capability' => 'edit_theme_options',
        ));

        $wp_customize->add_control('disable_phone_field_option', array(
            'label' => 'Disable Phone Field',
            'section' => $sectionName,
            'settings' => 'disable_phone_field',
            'type' => 'checkbox',
        ));

        $wp_customize->add_setting('disable_phone_prompt', array(
            'default' => false,
            'type'    => 'option',
            'capability' => 'edit_theme_options',
        ));

        $wp_customize->add_control('kiosk_phone_prompt', array(
            'label' => 'Disable Phone Prompt ',
            'section' => $sectionName,
            'settings' => 'disable_phone_prompt',
            'type' => 'checkbox',
        ));

        $wp_customize->add_setting('disable_promo_code', array(
            'default' => false,
            'type'    => 'option',
            'capability' => 'edit_theme_options',
        ));

        $wp_customize->add_control('kiosk_promo_code', array(
            'label' => 'Disable Promo Code',
            'section' => $sectionName,
            'settings' => 'disable_promo_code',
            'type' => 'checkbox',
        ));

        $wp_customize->add_setting('disable_gift_card', array(
            'default' => false,
            'type'    => 'option',
            'capability' => 'edit_theme_options',
        ));

        $wp_customize->add_control('kiosk_gift_card', array(
            'label' => 'Disable Gift Card',
            'section' => $sectionName,
            'settings' => 'disable_gift_card',
            'type' => 'checkbox',
        ));

        $wp_customize->add_setting('payment_button_label', array(
            'default' => false,
            'type'    => 'option',
            'capability' => 'edit_theme_options',
        ));

        $wp_customize->add_control('kiosk_payment_button_label', array(
            'label' => 'Payment Button Label',
            'section' => $sectionName,
            'settings' => 'payment_button_label',
            'type' => 'text',
        ));



        $sectionName = 'Thank You Page Message';
        $wp_customize->add_section($sectionName, array(
            'title' => $sectionName,
            'priority' => 33,
        ));

        $wp_customize->add_setting('thank_you_page_message', array(
            'default' => '',
            'type'    => 'option',
            'capability' => 'edit_theme_options',
        ));
        
        $wp_customize->add_control('thank_you_page_message_text', array(
            'label' => 'Thank You Page Message (Max 80 Characters)',
            'section' => $sectionName,
            'settings' => 'thank_you_page_message',
            'type' => 'text',
            'input_attrs' => array(
                'maxlength' => 80,
            ),
        ));

        $wp_customize->add_setting('siteMode', array(
            'default' => 'olo',
            'type'    => 'option',
            'capability' => 'edit_theme_options',
        ));
        
        $wp_customize->add_section('time_slot_section', array(
            'title' => 'Time Slot',
            'priority' => 20,
        ));

        $wp_customize->add_setting('time_slot_setting', array(
            'default' => false,
            'type'    => 'option',
            'capability' => 'edit_theme_options',
        ));

        $wp_customize->add_control('time_slot_setting_option', array(
            'label' => 'Enable Time Slot single option',
            'section' => 'time_slot_section',
            'settings' => 'time_slot_setting',
            'type' => 'checkbox',
        ));

        $wp_customize->add_section('ordertype_section', array(
            'title' => 'CBS Order Type ',
            'priority' => 19,
        ));

        $wp_customize->add_setting('ordertype_setting', array(
            'default'           => '0',
            'type'              => 'option'
        ));

        $wp_customize->add_control('ordertype_option', array(
            'label'    => __( 'Order Type', 'cbs' ),
            'section'  => 'ordertype_section',
            'settings' => 'ordertype_setting',
            'type'     => 'select',
            'choices'  => [
                '0' => __( 'Dine-in',  'cbs' ),
                '1' => __( 'Take Out', 'cbs' ),
                '2' => __( 'Delivery', 'cbs' ),
                '3' => __( 'Default', 'cbs' ),
            ],
        ));

        self::addAditionalSettings($wp_customize);
    }

    // Get Tipping options from external plugin
    public static function getTippingOptions(){
        $values = array();
        $options = array();
        $tips = array();

        if(class_exists('Wpcot_Helper')){
            $tips =  Wpcot_Helper::get_tips();
        }
        foreach ($tips as $value) {
            if ($value["name"] === "tip") {
                $values = $value["values"];
    
                foreach ($values as $tip_values) {
                    $options[$tip_values["value"]] = $tip_values["label"];
                }
            }
        }
        return $options;
    }

    public static function init(WP_Customize_Manager $wp_customize) {
        // Call the register method to add customizer fields
        self::register($wp_customize);
    }

    private static function addAditionalSettings(WP_Customize_Manager $wp_customize) {

        $wp_customize->add_section('olo_checkout_configuration', array(
            'title' => 'OLO Checkout Configuration',
            'priority' => 32,
        ));
        
        $wp_customize->add_setting('olo_enable_location_field', array(
            'default' => false,
            'type'    => 'option',
            'capability' => 'edit_theme_options',
        ));
        
        $wp_customize->add_control('olo_enable_location_field_control', array(
            'label' => 'Add Location Field on Checkout',
            'section' => 'olo_checkout_configuration',
            'settings' => 'olo_enable_location_field',
            'type' => 'checkbox',
        ));
        
        $wp_customize->add_setting('olo_location_field_label', array(
            'default' => '',
            'type'    => 'option',
            'capability' => 'edit_theme_options',
        ));
        
        $wp_customize->add_control('olo_location_field_label_control', array(
            'label' => 'Location Field Label',
            'section' => 'olo_checkout_configuration',
            'settings' => 'olo_location_field_label',
            'type' => 'text',
        ));

        //for disabling the location field
        $wp_customize->add_setting('olo_disable_location_field', array(
            'default' => false,
            'type'    => 'option',
            'capability' => 'edit_theme_options',
        ));
        $wp_customize->add_control('olo_disable_location_field_control', array(
            'label' => 'Disable Location Field when auto-filled',
            'section' => 'olo_checkout_configuration',
            'settings' => 'olo_disable_location_field',
            'type' => 'checkbox',
        ));
    }
}
