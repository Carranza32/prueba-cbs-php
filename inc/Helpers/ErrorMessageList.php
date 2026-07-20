<?php

namespace CBSNorthStar\Helpers;


class ErrorMessageList
{
    protected $errors = [
        'adjustments_null_or_empty' =>
            'An adjustment was submitted, but the ID is invalid',
        'cannot_finalize_with_deposit_on_check' =>
            'A payment exists on the check that has not been applied to the balance',
        'check_cannot_be_modified' =>
            'Check is locked or an online credit card payment has already been applied to the balance',
        'check_over_payment' =>
            'Total payments exceed the amount due',
        'component_unavailable' =>
            'Component is unavailable (Out of stock)',
        'data_not_available' =>
            'Configuration data is not available',
        'data_not_found' =>
            'No data found',
        'default_area_not_set' =>
            'The default area is not set up in ECM',
        'default_payment_option_not_set' =>
            'The default payment option is not set up in ECM (This setting is for credit card payments taken online)',
        'existing_balance' =>
            'Check cannot be finalized if there is a balance due',
        'existing_order_item_not_sent_to_kitchen' =>
            'Check cannot be finalized if there are items that have not been sent to the kitchen ',
        'gift_card_processing_error' =>
            'General gift card processing error',
        'gratuity_not_active' =>
            'The gratuity submitted is not enabled',
        'internal_error' =>
            'An unknown error has occurred',
        'invalid_adjustment_amount' =>
            'Adjustment amount is 0 or negative',
        'invalid_adjustment_order_item' =>
            'Order item associated with the adjustment is null or not found',
        'invalid_adjustment_reason' =>
            'Adjustment reason is null or not found',
        'invalid_area' =>
            'The area is invalid for web ordering',
        'invalid_check' =>
            'Check ID is null or not found (If an order has been submitted it is no longer available online)',
        'invalid_check_adjustment' =>
            'Adjustment cannot be applied to the entire check',
        'invalid_check_id' =>
            'Submitted Check ID is invalid',
        'invalid_combo' =>
            'The combo is invalid for web ordering',
        'invalid_combo_qualifiers' =>
            'No qualifying menuitems are set up for the associated combo ',
        'invalid_combo_qualifying_menuitem' =>
            'MenuItem does not qualify for the associated combo',
        'invalid_component' =>
            'Component was not found or is not available for the associated menu item',
        'invalid_card_number' =>
            'Card number is null',
        'invalid_credit_card_amount' =>
            'Credit card amount is 0 or negative',
        'invalid_credit_card_approval_code' =>
            'Credit card approval code is null',
        'invalid_credit_card_number' =>
            'Credit card number is null',
        'invalid_credit_card_reference_code' =>
            'Credit card reference code is null',
        'invalid_customerid' =>
            'Customer ID is null or not found',
        'invalid_date_format' =>
            'Date format is incorrect (Use International date format yyyy-mm-dd)',
        'invalid_email' =>
            'Email is null',
        'invalid_gift_card_amount' =>
            'Gift card amount is 0 or negative',
        'invalid_gift_card_number' =>
            'Gift card number is null',
        'invalid_gratuity' =>
            'Gratuity submitted is not valid for web ordering',
        'invalid_gratuity_amount' =>
            'Gratuity amount is null or not allowed for the gratuity type',
        'invalid_loyalty_adjustment' =>
            'Customer ID is null or adjustment cannot be applied.
            (Loyalty adjustments can only be used to redeem loyalty rewards)',
        'invalid_location' =>
            'Location does not exist for the site or the location is not
            configured to support the order type being submitted',
        'invalid_location_for_area' =>
            'Location does not belong to the area submitted or the location
            is not configured to support the order type being submitted',
        'invalid_loyalty_program' =>
            'Loyalty program is null or not found',
        'invalid_loyalty_redemption' =>
            'A loyalty redemption was submitted but was invalid or incomplete',
        'invalid_menu' =>
            'Menu is null or not found',
        'invalid_menu_item' =>
            'Menu item is null or not found',
        'invalid_order_item' =>
            'Order item is null or not found',
        'invalid_order_item_adjustment' =>
            'Order item is null or not found or cannot be used with an adjustment',
        'invalid_order_type' =>
            'Unsupported order type (valid order types are DineIn= 0, TakeOut = 1, Delivery = 2)',
        'invalid_password' =>
            'Password is null',
        'invalid_payment' =>
            'Payment is null or not found',
        'invalid_payment_amount' =>
            'Payment amount is 0 or negative',
        'invalid_payment_approval_code' =>
            'Payment approval code is null',
        'invalid_payment_card_number' =>
            'Payment card number is null',
        'invalid_payment_option' =>
            'Payment option is null, not found or cannot be used for web ordering',
        'invalid_payment_reference_code' =>
            'Payment reference code is null',
        'invalid_payment_type' =>
            'Unsupported payment type (Valid payment types are CreditCard = 1, GiftCard = 3, Alternate = 5)',
        'invalid_phone_number' =>
            'Phone number was null',
        'invalid_pickup_time' =>
            'Pickup time is null or does not include the kitchen volume time.
            (Kitchen volume time is a setting in ECM used to forecast the time it takes to prepare an order.
            You can retrieve the volume time using the site\'s get end point.)',
        'invalid_serving_option' =>
            'Serving option was not found or is not available for the associated menu item',
        'menuitem_unavailable' =>
            'Menu item is unavailable (out of stock)',
        'no_locations_available_for_area' =>
            'All locations for an area are currently assigned or the locations
            in the area are not configured to support the order type being submitted',
        'no_qualifying_order_item' =>
            'Order item cannot be used with an adjustment nullable object must
            have a value Typically, seen when Order Types are not configured on the assigned location within the area',
        'orderitems_null_or_empty' =>
            'Multiple order items were submitted but are invalid',
        'ordertype_not_supported_by_location' =>
            'Location is not configured to support the order type being submitted',
        'payments_null_or_empty' =>
            'Multiple payments were submitted but are invalid',
        'submit_order_error' =>
            'Submitted order could not be scheduled',
    ];
    private static $instance = null;

    public static function create(): ?ErrorMessageList
    {
        if (self::$instance === null) {
            self::$instance = new ErrorMessageList();
        }

        return self::$instance;
    }

    public function getError($key)
    {
        return $this->errors[$key] ?? 'Unknown Error';
    }
}
