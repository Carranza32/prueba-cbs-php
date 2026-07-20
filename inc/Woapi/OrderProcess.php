<?php
/**
 * Class OrderProcess
 *
 * OrderProcess class for handdling orders.
 *
 * @package CBSNorthStar\Woapi
 */
namespace CBSNorthStar\Woapi;

use WC_Order_Item_Product;
use CBSNorthStar\Dto\OrderDto;
use CBSNorthStar\Dto\OrderWithPaymentDto;
use CBSNorthStar\Dto\PaymentDTO;
use CBSNorthStar\Dto\PaymentsDTO;
use CBSNorthStar\Helpers\EmailService;
use CBSNorthStar\Helpers\MenuItemActiveWindow;
use CBSNorthStar\Services\LoyaltyService;
use CBSNorthStar\Dto\GratuitiesDto;
use CBSNorthStar\Logger\CBSLogger;

use CBSNorthStar\Helpers\OrderMeta;


class OrderProcess {

    const CHECK_ID = "00000000-0000-0000-0000-000000000000";
    const DATE_FORMAT = "Y-m-d H:i:s" ;
    const CASH_ON_DELIVERY = "cod";
    const CREDIT_CARD = "authorize_net_cim_credit_card" ;
    const ERROR_SUBMIT = "Error Submitting Order to WOAPI";
    const ERROR_VALIDATE = "Error Validating Order to WOAPI";
    const ERROR_FINALIZE = "Error Finalizing Order to WOAPI";
    const ERROR_PAYMENT_TYPE = "fail-send-payment";
    const ERROR_SUBMIT_TYPE = "fail-submit-order";
    const ERROR_VALIDATE_TYPE = "fail-validate-order";


    /**
     * @var string $orderId This is the id of any order
     */
    private $siteId;

    /**
     * * @var Order $order This is the order object.
     * */
    public $order;

    /**
     * @var string $orderId This is the id of any order
     */
    private $orderId;

    /**
     * @var string $orderType This is the type of any order
     */
    private $orderType;

    /**
     * @var string $lastFour This is the last four digits of any card
     */

    private $lastFour;

    /**
     * @var string $expDate This is the last four digits of any card
     */

    private $expDate;

    /**
     * @var string $transactionNumber This is the response from the payment method.
     */
    protected $transactionNumber;

    /**
     * @var string $approval code This is the response from the payment method.
     */
    protected $approvalCode;

    /**
     * @var string $total This is the total of the order
     */

    private $total;

    /**
     * @var string $feeAmount This is the last four digits of any card
     */

    private $feeAmount;

    /**
     * @var string $tax This is the tax amount of the order
     */
    private $tax;

    /**
     * @var string $reward This is the reward amount of the order
     */
    private $reward;

    /**
     * @var array $paymentData This is the response from the payment method.
     */
    protected $paymentData;

    /**
     * @var string $payload This is the payload to send to woapi.
     */

    private $payload;

    /**
     * @var string $pickUpDate this is the date of the order
     */

    private $pickUpDate;

    /**
     * @var string $checkId This is the id of any check
     */

    public $checkId;

    /**
     * @var string $checkNumber this is the number of the check
     */

    public $checkNumber;

    /**
     * @var string $tokenType This is the type of token for authentication
     */

    private $tokenType;

    /**
     * @var string $MessageString This is the message to send to the email
     */

    private $messageString;

    /**
     * @var string $view error that will be sent to the email
     */

    private $view;

    /**
     * @var array $giftCards This is the gift card data
     */
    private $giftCards;

    /**
     * @var array $loyalty This is the loyalty data
     */
    private $loyalty;


    /**
     * @var string $areaId This is the area id of the order
     */
    private $areaId;

    /**
     * @var string $gift This is the gift card total
     */

    private $gift;
    

    /**
     * @var string $shippingFee This is the shipping fee amount
     */
    private $shippingFee;

    /**
     * @var string $coupons This is the coupons applied to the order
     */
    private $coupons;

    /**
    * @var string $couponsAmount This is the coupons amount applied to the order
    */
    private $couponsAmount;

    /**
     * Order Process constructor.
     *
     * @param int $orderId The initial value for $orderId.
     * @param string $siteId The initial value for $siteId.
     * @param object $processorData The initial value for $processorData.
     */
    public function __construct(string $orderId, string $siteId, ?int $orderType = null, ?object $processorData = null, ?string $areaId = null)
    {
        $orderType = isset($_COOKIE['orderType']) ? $_COOKIE['orderType'] : 1;
        $this->orderId = $orderId;
        $this->siteId = $siteId;
        $this->order = wc_get_order($orderId);
        $this->orderType = is_null($orderType) ? 1 : $orderType;
        $this->paymentData = $processorData;
        $this->tokenType = 'Token';
        $this->giftCards = WC()->session->get('giftCardData');
        $this->loyalty = WC()->session->get('loyaltyData');
        $this->coupons = isset($_COOKIE['olo_coupon_codes']) ? explode(',', sanitize_text_field( $_COOKIE['olo_coupon_codes'] ) ) : [];
        $this->areaId = $areaId;

    }

    /**
     * Process the payload that will be sent to WOAPI.
     *
     * @return string The JSON value of $payload.
     */
    public function processOrderData()
    {
        $this->setPaymentData();

        $deliveryExternalCode = carbon_get_theme_option('olo_delivery_external_code') ? carbon_get_theme_option('olo_delivery_external_code') : 0;
        $gratuities = new GratuitiesDto($deliveryExternalCode, $this->shippingFee);

        $orderData = [
            'order' => $this->order,
            'orderType' => $this->orderType,
            'deliveryDate' => $this->getDeliveryDate(),
            'areaId' => $this->areaId,
            'customerId' => $this->loyalty['customer']['id'] ?? ($this->loyalty['customerId'] ?? null),
            'coupons' => $this->coupons
        ];

        if ($this->getPaymentMethod() == self::CASH_ON_DELIVERY || empty($this->paymentData)) {
            if($this->giftCards){
                $this->payload = (new OrderWithPaymentDto($orderData, new PaymentsDTO(
                    paymentType: 3,
                    total: $this->total,
                    tip: $this->feeAmount,
                    giftCards: $this->giftCards
                ), $gratuities))->toJson();
            }
            else{
                $this->payload = (new OrderDto($orderData, $gratuities))->toJson();
            }

        } else {
            $this->payload = (new OrderWithPaymentDto($orderData, new PaymentsDTO(
                5,
                $this->lastFour,
                $this->transactionNumber,
                $this->approvalCode,
                $this->total,
                $this->feeAmount,
                $this->giftCards
            ), $gratuities))->toJson();
        }
        return $this->payload;
    }

    /**
     * Get the type of payment.
     *
     * @return string The type of payment method.
     */
    public function getPaymentMethod()
    {
        return $this->order->get_payment_method();
    }

    /**
     * Get the value of date.
     *
     * @return string The value of delivery date
     */
    public function getDeliveryDate()
    {
        $this->pickUpDate = current_time(self::DATE_FORMAT);
        CBSLogger::orders()->info('Order sent at', ['pickUpDate' => $this->pickUpDate]);
        if (is_plugin_active('order-delivery-date-for-woocommerce/order_delivery_date.php')) {
            $this->pickUpDate = date(self::DATE_FORMAT,
                get_post_meta($this->orderId, '_orddd_lite_timeslot_timestamp', true));

        } elseif (is_plugin_active('order-delivery-date/order_delivery_date.php')) {
            $pickUpDate = get_post_meta($this->orderId, '_orddd_timeslot_timestamp', true);
            if (!empty($pickUpDate)) {
                $this->pickUpDate = date(self::DATE_FORMAT, $pickUpDate) ? date("Y-m-d H:i:s", $pickUpDate) : '';
            }

        } else {
            // Use our own time slot meta (nav popup flow)
            $slotTime     = get_post_meta($this->orderId, '_olo_time_slot_time', true);
            $businessDate = get_post_meta($this->orderId, '_olo_time_slot_business_date', true);

            if ($slotTime && $businessDate) {
                // Use $businessDate as context when $slotTime has no date component
                // (CBS API can return a bare time like "13:45:00").
                $input = preg_match('/^\d{4}-\d{2}-\d{2}/', $slotTime) ? $slotTime : $businessDate . ' ' . $slotTime;

                // Use the literal wall-clock written in slotTime (the time CBS returned and
                // the customer saw), not the UTC instant. strtotime() + date() would resolve
                // the offset to a UTC instant and — because WordPress forces PHP's timezone to
                // UTC — shift the value; date_parse() reads the literal Y-m-d H:i:s and ignores
                // the offset, matching the slot picker's display.
                $parsed = date_parse($input);
                if ($parsed && $parsed['error_count'] === 0 && false !== $parsed['year'] && false !== $parsed['hour']) {
                    $this->pickUpDate = sprintf(
                        '%04d-%02d-%02d %02d:%02d:%02d',
                        $parsed['year'], $parsed['month'], $parsed['day'],
                        $parsed['hour'], (int) $parsed['minute'], (int) $parsed['second']
                    );
                    CBSLogger::orders()->debug('Parsed slot time literal wall-clock', ['pickUpDate' => $this->pickUpDate]);
                } else {
                    $parsed = date_parse($slotTime);
                    if ($parsed && $parsed['error_count'] === 0 && false !== $parsed['hour']) {
                        $timeStr = sprintf('%02d:%02d:%02d', $parsed['hour'], (int) $parsed['minute'], (int) $parsed['second']);
                        $this->pickUpDate = $businessDate . ' ' . $timeStr;
                        CBSLogger::orders()->debug('Parsed slot time using date_parse fallback', ['pickUpDate' => $this->pickUpDate]);
                    }
                }
            }
        }

        CBSLogger::orders()->debug('pickUpDate resolved', ['pickUpDate' => $this->pickUpDate]);
        return $this->pickUpDate;
    }

    /**
     * Set the data of $payment.
     *
     */
    public function setPaymentData()
    {

        //TO-DO: Add the rest of the payment methods if needed
        if ($this->getPaymentMethod() === 'authorize_net_cim_credit_card') {
            if ($this->order->meta_exists('_wc_authorize_net_cim_credit_card_trans_id')) {
                $this->transactionNumber = $this->order->get_meta('_wc_authorize_net_cim_credit_card_trans_id', true);
            }
            if ($this->order->meta_exists('_wc_authorize_net_cim_credit_card_authorization_code')) {
                $this->approvalCode = $this->order->get_meta(
                    '_wc_authorize_net_cim_credit_card_authorization_code',
                    true);
            }
            $this->lastFour = ( ! empty( $this->paymentData?->payment?->last_four ) )
                ? $this->paymentData->payment->last_four
                : $this->order->get_meta( '_wc_authorize_net_cim_credit_card_account_four', true );
            $this->expDate = ( ! empty( $this->paymentData?->payment?->exp_date ) )
                ? $this->paymentData->payment->exp_date
                : $this->order->get_meta( '_wc_authorize_net_cim_credit_card_exp_date', true );
        }
        $subtotal = $this->order->get_subtotal();
        $this->shippingFee = 0;
        $this->couponsAmount = 0;


        foreach ($this->order->get_fees() as $fee) {
            $name = strtolower(trim($fee->get_name()));

            if ($name === 'tax') {
                $this->tax = $fee->get_total();
            }

            if ($name === 'rewards') {
                $this->reward = $fee->get_total();
            }
            if ($name === 'gift card total') {
                $this->gift = $fee->get_total();
            }
            if ($name === 'coupons discount'){
                $this->couponsAmount = $fee->get_total();
            }
        }
        $this->shippingFee = (float) $this->order->get_shipping_total();


        $this->total = $this->order->get_total();
        CBSLogger::transactions()->info('Gift value', ['gift' => $this->gift]);

        // Progressive calculation of adjusted total
        $adjustedTotal = $this->total;

        if (abs($this->gift) > 0) {
            $adjustedTotal = bcadd($adjustedTotal, abs($this->gift), 2);
            CBSLogger::transactions()->info('Total after gift card adjustment', ['adjustedTotal' => $adjustedTotal]);
        }

        if (abs($this->reward) > 0) {
            $adjustedTotal = bcadd($adjustedTotal, abs($this->reward), 2);
            CBSLogger::transactions()->info('Total after reward adjustment', ['adjustedTotal' => $adjustedTotal]);
            //calculate tip amount
        }
        //restar lo de  coupons 
        // calculate fee amount in one place
        $this->feeAmount = bcsub(bcsub($adjustedTotal, bcadd($subtotal, bcadd($this->tax, $this->shippingFee, 2), 2), 2), $this->couponsAmount, 2);

        CBSLogger::transactions()->info('Fee amount calculated', ['feeAmount' => $this->feeAmount]);

        if(!class_exists('Wpcot_Helper')){
            CBSLogger::transactions()->warning('Tip plugin not found or deactivated. Skipping Tip calculation.');
            $this->feeAmount = 0;
        }

        if (abs($this->reward) > 0) {
            CBSLogger::transactions()->info('Fee amount (excluding reward)', ['feeAmount' => $this->feeAmount]);
        } else {
            CBSLogger::transactions()->info('Payment data breakdown', [
                'totalAmount'  => $this->total,
                'subtotal'     => $subtotal,
                'tax'          => $this->tax,
                'feeAmount'    => $this->feeAmount,
                'shippingFee'  => $this->shippingFee,
            ]);
        }
    }

    /**
     * Validate the order info.
     *
     * @return object The value of validate process.
     */
    public function validateOrder()
    {
        $windowCheck = $this->checkActiveDateWindow();
        if (null !== $windowCheck) {
            return $windowCheck;
        }

        $url = '/checks/validate/';
        return (new Connection())->postData($this->siteId, $url, $this->tokenType, $this->payload);
    }

    /**
     * Submit the order info.
     *
     * @return object The response from woapi.
     */
    public function submitOrder(?string $checkId = null)
    {
        $windowCheck = $this->checkActiveDateWindow();
        if (null !== $windowCheck) {
            return $windowCheck;
        }

        CBSLogger::orders()->debug('Submitting order', ['payload' => json_decode($this->payload)]);

        $url = '/checks/' . ($checkId ? $checkId : self::CHECK_ID) . '/submit';
        
        // --- Task 3: Bounded retry loop (max 3 attempts with 500ms backoff) ---
        $attempt = 1;
        $maxAttempts = 3;
        while (true) {
            $response = (new Connection())->postData($this->siteId, $url, $this->tokenType, $this->payload);
            
            // If successful, or if we've reached the 3rd attempt, or if the error is NOT transient (e.g. validation) -> exit
            if ((!empty($response->Ok) && $response->Ok === true) || $attempt >= $maxAttempts || !self::isTransientError($response)) {
                break;
            }
            
            CBSLogger::orders()->warning("Transient submit failure (attempt $attempt/$maxAttempts). Retrying...", ['response' => $response]);
            usleep(500000); // 500ms pause before retrying
            $attempt++;
        }
        // --- End Retry ---

        CBSLogger::orders()->debug('Submit order response', ['response' => $response]);
        if ($response->Ok) {
            $this->checkId = $response->Data->CheckId;
            $this->checkNumber = $response->Data->CheckNumber;
            
            // --- Task 2: HPOS-compatible OrderMeta CRUD operations ---
            OrderMeta::set($this->order, 'cbs_orderid', esc_attr(htmlspecialchars($response->Data->CheckId)), false);
            OrderMeta::set($this->order, 'cbs_siteid', esc_attr(htmlspecialchars($this->siteId)), false);
            OrderMeta::set($this->order, 'cbs_checknumber', esc_attr(htmlspecialchars($response->Data->CheckNumber)), true);
        } else {
            $this->sendMailError(self::ERROR_SUBMIT_TYPE);
        }
        return $response;
    }

    /**
     * Submit only payment for QR.
     *
     * @return object The response from woapi.
     */
    public function sendPaymentOnly($checkId=null)
    {
        $this->setPaymentData();
        $responsePayment = (new Payment())->makePayment(
            $this->lastFour,
            $this->checkId ?? $checkId,
            $this->siteId,
            $this->transactionNumber,
            $this->total,
            $this->feeAmount
        );
        if ($responsePayment->Ok) {
            $this->checkId = $responsePayment->Data->CheckId;
            $this->checkNumber = $responsePayment->Data->CheckNumber;
            
            // --- Task 2: HPOS-compatible OrderMeta CRUD operations ---
            OrderMeta::set($this->order, 'cbs_orderid', esc_attr(htmlspecialchars($responsePayment->Data->CheckId)), false);
            OrderMeta::set($this->order, 'cbs_siteid', esc_attr(htmlspecialchars($this->siteId)), false);
            OrderMeta::set($this->order, 'cbs_checknumber', esc_attr(htmlspecialchars($responsePayment->Data->CheckNumber)), true);
        } else {
            $this->sendMailError(self::ERROR_PAYMENT_TYPE);
        }
        return $responsePayment;

    }

    /**
     * Finalizes the order by sending a POST request to the API endpoint.
     *
     * @return object The response from the API.
     */
    public function finalizeOrder( $checkNumber = null)
    {
        $url = '/checks/' . ($checkNumber ?? $this->checkNumber) . '/finalizebynumber';
        $response = (new Connection())->postData($this->siteId, $url, $this->tokenType, "{}");
        if ($response->Ok) {
            // --- Task 2: HPOS-compatible OrderMeta CRUD operations ---
            OrderMeta::set($this->order, 'cbs_orderFinalized', esc_attr(htmlspecialchars($response->Data->CheckId)));
        }
        return $response;
    }

    /**
     * Local backstop: reject if any line item is outside its per-site active
     * date window (cart-item-active-date-enforcement capability, design.md
     * Decision 4) — independent of WOAPI's `/checks/validate/` response, which
     * does not check dates today, and independent of whether the cart-page
     * `woocommerce_check_cart_items` check ran (a stale client-side state or a
     * direct submission path could reach here without it).
     *
     * Uses the SAME MenuItemActiveWindow logic as ProductScope's read-path
     * filter and the cart-page check, so all three can never disagree about
     * whether an item is currently within its window.
     *
     * @return object|null A synthetic {Ok: false, ErrorMessage} response —
     *                      matching validateOrder()/submitOrder()'s normal
     *                      WOAPI response shape so existing callers (which only
     *                      ever read ->Ok / ->ErrorMessage / ->Data) need no
     *                      changes — when blocked; null when every line item
     *                      is within its window (including when there is no
     *                      order to check, so this never blocks a call made
     *                      before the order object is available).
     */
    private function checkActiveDateWindow(): ?object
    {
        if ( ! $this->order ) {
            return null;
        }

        foreach ( $this->order->get_items() as $item ) {
            $productId = $item->get_product_id();
            if ( ! MenuItemActiveWindow::isWithinWindow( $productId, $this->siteId ) ) {
                CBSLogger::orders()->warning( 'Order rejected — item outside its active date window', [
                    'order_id'   => $this->orderId,
                    'site_id'    => $this->siteId,
                    'product_id' => $productId,
                    'item_name'  => $item->get_name(),
                ] );

                return (object) [
                    'Ok'           => false,
                    'ErrorMessage' => sprintf(
                        /* translators: %s: order item name */
                        __( 'Item "%s" is no longer available and cannot be submitted.', 'olo' ),
                        $item->get_name()
                    ),
                ];
            }
        }

        return null;
    }

    /**
     * Sends an error email based on the given type.
     *
     * @param string $type The type of error.
     * @return string The HTTP origin.
     */
    public function sendMailError($type)
    {
        $mailoption = get_option('mail_login_cbs_mail_name', '');

        switch ($type) {
            case self::ERROR_SUBMIT_TYPE:
                $this->messageString = "Couldn't submit order to Woapi order : " . $this->orderId;
                $this->view = self::ERROR_SUBMIT_TYPE;
                break;
            case self::ERROR_PAYMENT_TYPE:
                $this->messageString = "Couldn't send payment to Woapi order : " . $this->orderId;
                $this->view = self::ERROR_PAYMENT_TYPE;
                break;
            default:
                $this->messageString = "Couldn't validate order to Woapi order : " . $this->orderId;
                $this->view = self::ERROR_VALIDATE_TYPE;
                break;
        }

        EmailService::send(
            $mailoption,
            'Woocommerce-Error',
            $this->view,
            [
                'origin' => $_SERVER['HTTP_ORIGIN'],
                'host' => $_SERVER['HTTP_HOST'],
                'server_name' => $_SERVER['SERVER_NAME'],
                'message' => $this->messageString,
            ]
        );

        return $_SERVER['HTTP_ORIGIN'];
    }

    /**
     * Validates loyalty-related data for the current order flow.
     *
     * Performs checks on loyalty identifiers and eligibility (e.g., membership ID,
     * redemption constraints), and may normalize the payload for downstream use.
     * If no payload is provided, will return an empty array.
     *
     * @param mixed|null $payload Optional loyalty payload to validate; pass null to use context-derived data.
     * @param LoyaltyService $loyaltyService Injected loyalty service for program validation.
     * @return array loyalty data per program or an empty array.
     * @deprecated This method is deprecated and may be removed in future versions.
     */
    public function validateLoyalty(LoyaltyService $loyaltyService, $payload = null) : array
    {
        if(!$this->loyalty || !$payload || !$loyaltyService){
            return [];
        }

        $customerId = $this->loyalty['customer']['id'] ?? ($this->loyalty['customerId'] ?? null);

        if(!isset($payload->OrderItems) || !is_array($payload->OrderItems)){
            CBSLogger::transactions()->warning('validateLoyalty: Invalid payload structure - OrderItems missing or not an array');
            return [];
        }

        $loyaltyPrograms = $this->loyalty['programs'];
        $availablePrograms = $loyaltyService->getAvailableLoyaltyPrograms($payload);
        $checkByProgram = [];

        foreach ($loyaltyPrograms as $programId => $checkData) {
            $validationPayload = [
                    'ProgramId' => $programId,
                    'OrderItemIds' => $loyaltyService->getQualifyingOrderItemIds($availablePrograms, $programId) ?? [],
                    'Check' => [
                        'OrderItems' => $payload->OrderItems,
                        'CustomerId' => $customerId
                    ]
            ];
            $url = '/loyalty/validate';
            $validateLoyalty = (new Connection())->postData($this->siteId, $url, $this->tokenType, json_encode($validationPayload));
            if($validateLoyalty->Ok){
                CBSLogger::transactions()->debug('Loyalty validated for program', ['programId' => $programId, 'response' => $validateLoyalty]);
                $checkByProgram[$programId] = $validateLoyalty->Data;
            } else {
                CBSLogger::transactions()->error('Loyalty validation failed for program', ['programId' => $programId, 'response' => $validateLoyalty]);
            }
        }
        return $checkByProgram;
    }

    /**
     * Updates the order check with loyalty program discounts grouped by program.
     *
     * Applies one or more loyalty discounts to the current check, merging discount
     * definitions per program into the in-progress totals and/or eligible line items.
     * Intended for scenarios where a guest has multiple loyalty programs that may
     * contribute percentage- or amount-based discounts with optional caps or qualifiers.
     *
     * @param array $checkByProgram
     *     Check data grouped by loyalty program identifier; typically includes per-program
     *     items, totals, and context used to compute applicable discounts.
     *
     * @return void
     */
    public function updateCheckWithLoyaltyDiscounts($checkByProgram = [])
    {
        if(empty($checkByProgram) || !is_array($checkByProgram)){
            return;
        }
        
        CBSLogger::transactions()->debug('Check data by program', ['checkByProgram' => $checkByProgram]);
        $payload = json_decode($this->payload);
        $checkLevelAdjustment = $this->getCheckLevelAdjustment($checkByProgram);
        $customerId = $this->loyalty['customer']['id'] ?? ($this->loyalty['customerId'] ?? null);

        $payload->Adjustments = $checkLevelAdjustment;
        $payload->customerId = $customerId;

        CBSLogger::transactions()->debug('Updated payload with loyalty adjustments', ['payload' => $payload]);
        $this->payload = json_encode($payload);
        return;
    }

    /**
     * Retrieves check-level adjustments from the provided check data grouped by program.
     *
     * @param array $checkByProgram The check data grouped by program identifier.
     * @return array An array of adjustments applicable at the check level, or an empty array if not found.
     */

    public function getCheckLevelAdjustment($checkByProgram) {
        if(empty($checkByProgram) || !is_array($checkByProgram)){
            CBSLogger::transactions()->warning('getCheckLevelAdjustment: Invalid checkByProgram structure');
            return [];
        }

        $consolidated = [];

        foreach ($checkByProgram as $programId => $enrrollments) {
            foreach ($enrrollments as $uniqueKey => $entry) {
                $check = $entry['check'] ?? null;
                $adjustments = $check->Adjustments ?? [];
                foreach ($adjustments as $adj) {
                    $code = $adj->AdjustmentReasonExternalCode ?? null;
                    if ($code === null || $code === '') {
                        continue;
                    }
                    $amount = isset($adj->AppliedAmount) ? (float) $adj->AppliedAmount : 0.0;
                    $name = $adj->AdjustmentReasonName ?? null;
                    $adjustmentReasonId = $adj->AdjustmentReasonId ?? null;

                    if (!isset($consolidated[$code])) {
                        $consolidated[$code] = [
                            'AdjustmentReasonId' => $adjustmentReasonId,
                            'AdjustmentReasonExternalCode' => $code,
                            'AdjustmentAmount'             => 0.0,
                            'AdjustmentReasonName'         => $name,
                        ];
                    }
                    $consolidated[$code]['AdjustmentAmount'] = round(
                        $consolidated[$code]['AdjustmentAmount'] + $amount, 2
                    );
                }
            }
        }

        $allAdjustments = array_values($consolidated);

        CBSLogger::transactions()->debug('Adjustments array created', ['adjustments' => $allAdjustments]);
        return $allAdjustments;
    }

    /**
     * Determines whether a WOAPI submission error is transient and safe to retry.
     * Transport errors (timeouts, network drops) and 5xx/429 HTTP statuses are retryable.
     * Business validation errors (400, 422, etc.) must fail fast.
     *
     * @param mixed $response
     * @return bool
     */
    public static function isTransientError($response): bool
    {
        if (is_wp_error($response)) {
            return true;
        }

        if (!is_object($response)) {
            return false;
        }

        $code = (int) ($response->StatusCode ?? $response->status_code ?? 0);
        return in_array($code, [408, 429, 500, 502, 503, 504], true);
    }
}