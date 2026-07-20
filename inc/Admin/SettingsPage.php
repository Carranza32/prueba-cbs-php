<?php

namespace CBSNorthStar\Admin;

use Carbon_Fields\Container;
use Carbon_Fields\Field;

class SettingsPage {

    public static function register() {

        Container::make( 'theme_options', __( 'Northstar Ordering Settings', 'olo' ) )
            ->set_page_file( 'olo-general-settings' )
            ->set_icon( 'dashicons-admin-generic' )
            ->set_layout( 'tabbed-horizontal' )
            ->add_tab( __( 'General', 'olo' ), [
                Field::make( 'checkbox', 'olo_enable_rsm', 'Enable RSM Options' ),
                Field::make( 'checkbox', 'olo_enable_infinity_scrolling', 'Enable Infinity Scrolling Options'),
                Field::make( 'separator', 'olo_checkout_separator_page', __( 'Checkout Page' ) ),
                Field::make( 'checkbox', 'olo_make_address_field_1_required', 'Make Address Line 1 Required' ),
                Field::make( 'checkbox', 'olo_make_address_field_2_required', 'Make Address Line 2 Required' ),
                Field::make( 'checkbox', 'olo_enable_coupons', 'Enable Coupons' ),
                Field::make( 'select', 'olo_coupons_position', 'Coupons location' )
                    ->set_options( [
                        '0'   => 'In Shipping Section',
                        '1'   => 'Before Order Notes',
                        '2'   => 'After Order Notes',
                        '3'   => 'Between your order and payment',
                        '4'   => 'Before Payment',
                    ] )
                    ->set_default_value( '0' )
                    ->set_conditional_logic([
                        [
                            'field' => 'olo_enable_coupons',
                            'value' => true,
                        ]
                    ]),
                Field::make( 'separator', 'olo_deploy_separator', __( 'Deploy Settings' ) ),
                Field::make( 'checkbox', 'olo_skip_deploy_images', __( 'Skip images on deploy', 'olo' ) ),
                Field::make( 'checkbox', 'olo_force_version_check', __( 'Force component version check (testing only — disable on production)', 'olo' ) ),
                Field::make( 'checkbox', 'olo_keep_daypart_transient', __( 'Keep daypart snapshot transient after deploy (debug only — disable on production)', 'olo' ) ),
                Field::make( 'checkbox', 'olo_disable_webhook_signature', __( 'Disable webhook signature verification (temporary — re-enable once CBS header is identified)', 'olo' ) ),
                Field::make( 'checkbox', 'olo_log_webhook_headers', __( 'Log incoming webhook headers for diagnostics (disable after identifying CBS signature header)', 'olo' ) ),
                Field::make( 'checkbox', 'olo_simulate_deploy_failure', __( 'Simulate deploy failure — next deploy returns failure immediately and self-clears (debug only — disable on production)', 'olo' ) ),
                Field::make( 'separator', 'cbs_menu_page_separator', __( 'CBS Menu page Configuration' ) ),
                Field::make( 'checkbox', 'cbs_display_quantity', 'Display Quantity Control' )
            ])
            ->add_tab( __( 'RSM', 'olo' ), [
                Field::make( 'separator', 'olo_locations_page', __( 'Locations Page' ) ),
                Field::make( 'text', 'olo_next_page', 'Order Online next page Slug' )
                    ->set_conditional_logic([
                        [
                            'field' => 'olo_enable_rsm',
                            'value' => true,
                        ]
                        ]),
                Field::make('separator', 'olo_taxable_tag_settings', __( 'Checkout Settings' ) )
                    ->set_conditional_logic([
                        [
                            'field' => 'olo_enable_rsm',
                            'value' => true,
                        ]
                    ]),
                Field::make('checkbox', 'olo_show_taxable_tag', 'Display Taxable Tag on Menu Items')
                    ->set_conditional_logic([
                        [
                            'field' => 'olo_enable_rsm',
                            'value' => true,
                        ]
                    ]),
            ])
            ->add_tab( __( 'OLO', 'olo' ), [
                Field::make( 'separator', 'olo_checkout_separator', __( 'Checkout Settings' ) ),
                Field::make( 'checkbox', 'olo_disable_olo_giftcard', 'Disable OLO Giftcard' ),
                Field::make( 'checkbox', 'olo_disable_olo_rewards', 'Disable OLO Rewards' ),
                Field::make( 'text', 'olo_delivery_external_code', 'Delivery External Code' )
            ])
            ->add_tab( __( 'Tipping', 'olo' ), [
                Field::make( 'separator', 'olo_tipping_separator', __( 'OLO Tipping' ) ),
                Field::make( 'select', 'olo_default_tipping_amount', 'Default Amount' )
                    ->set_options( \CBSNorthStar\Customizer\CustomizerSetup::getTippingOptions() ),
                Field::make( 'checkbox', 'hide_no_thanks_olo_tipping', 'Hide "No, Thanks" Option' )
                    ->set_conditional_logic( [
                        [
                            'field'   => 'olo_no_thanks_tip',
                            'value'   => true,
                            'compare' => '!=',
                        ],
                    ] ),
                Field::make( 'checkbox', 'olo_no_thanks_tip', 'Default to "No Thanks" Option' )
                    ->set_conditional_logic( [
                        [
                            'field'   => 'hide_no_thanks_olo_tipping',
                            'value'   => true,
                            'compare' => '!=',
                        ],
                    ] ),
                Field::make( 'checkbox', 'olo_tip_over_payment', 'Display Tip Over Payment Section' ),
            ])
            ->add_tab( __( 'Kiosk', 'olo' ), [
                Field::make( 'separator', 'olo_kiosk_settings', __( 'Kiosk Settings' ) ),
            ])
            ->add_tab( __( 'Time Slots', 'olo' ), [
                Field::make( 'separator', 'olo_time_slots_settings', __( 'Time Slots Settings' ) ),
                Field::make( 'checkbox', 'olo_enable_time_slots', 'Enable Time Slots' ),
                Field::make( 'select', 'olo_time_slot_position', 'Timeslot location' )
                    ->set_options( [
                        '0'   => 'In Shipping Section',
                        '1'   => 'Before Order Notes',
                        '2'   => 'After Order Notes',
                        '3'   => 'Between your order and payment',
                        '4'   => 'After your order',
                    ] )
                    ->set_default_value( '0' )
                    ->set_conditional_logic([
                        [
                            'field' => 'olo_enable_time_slots',
                            'value' => true,
                        ]
                    ]),
                Field::make( 'text', 'olo_time_slot_max_days_ahead', 'Max Days Ahead for Time Slots' )
                    ->set_default_value( 4 )
                    ->set_attribute( 'type', 'number' )
                    ->set_attribute( 'min', '0' )
                    ->set_attribute( 'step', '1' )
                    ->set_conditional_logic([
                        [
                            'field' => 'olo_enable_time_slots',
                            'value' => true,
                        ]
                    ]),
                Field::make( 'separator', 'olo_time_slot_debug_separator', __( 'Time Slot Debug' ) )
                    ->set_conditional_logic([
                        [
                            'field' => 'olo_enable_time_slots',
                            'value' => true,
                        ]
                    ]),
                Field::make( 'checkbox', 'olo_time_slot_debug_capacity', 'Show capacity debug (available/total) on timeslot labels' )
                    ->set_conditional_logic([
                        [
                            'field' => 'olo_enable_time_slots',
                            'value' => true,
                        ]
                    ]),
            ]);
    }
}
