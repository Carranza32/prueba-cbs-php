<?php
namespace CBSNorthStar\Controllers;

use CBSNorthStar\Repositories\ConfigurationRepository;
use CBSNorthStar\Woapi\Connection;
use CBSNorthStar\Dto\CartOrderReviewDto;
use CBSNorthStar\Services\LoyaltyService;
use CBSNorthStar\Logger\CBSLogger;
use WP_Error;

class TransactionController {

    public function registerRoutes() {
        $baseUrl = 'northstaronlineordering/v1';
        $loyaltyUrl = $baseUrl . '/loyalty';
        register_rest_route($baseUrl, '/giftcard', array(
            'methods' => 'POST',
            'callback' => [$this, 'validateGifCard'],
        ));
        register_rest_route($baseUrl, '/giftcard', array(
            'methods' => 'DELETE',
            'callback' => [$this, 'removeGiftCard'],
        ));
        register_rest_route($baseUrl, '/rewards', array(
            'methods' => 'GET',
            'callback' => [$this, 'getRewards'],
        ));
        register_rest_route($baseUrl, '/rewards', array(
            'methods' => 'POST',
            'callback' => [$this, 'redeemRewards'],
        ));
        register_rest_route($loyaltyUrl, '/availablePrograms', array(
            'methods' => 'POST',
            'callback' => [$this, 'checkAvailablePrograms'],
            
            'permission_callback' => '__return_true',
        ));
        register_rest_route($loyaltyUrl, '/redeem', array(
            'methods' => 'POST',
            'callback' => [$this, 'redeemLoyalty'],
            'permission_callback' => '__return_true',
        ));
        register_rest_route($loyaltyUrl, '/undoRedeem', array(
            'methods' => 'POST',
            'callback' => [$this, 'undoRedeem'],
            'permission_callback' => '__return_true',
        ));
        
    }

    public function checkAvailablePrograms(\WP_REST_Request $request) {
        $siteId =  sanitize_text_field($request['siteId']) ?? null;
        $payload = "";
        $phoneNumberRaw =  sanitize_text_field($request['phoneNumber']) ?? null;
        $phoneNumber = preg_replace('/\D+/', '', $phoneNumberRaw);

        if (!$phoneNumber || !$siteId) {
            return new \WP_Error(
                'missing_parameters',
                __( 'Phone number and Site are required', 'northstaronlineordering' ),
                [ 'status' => 400 ]
            );
        }
        if (is_null(WC()->cart)) { WC()->frontend_includes(); wc_load_cart();}

        if (WC()->session) {
        $snap = WC()->session->get('cbsValidateSnapshot');
        CBSLogger::transactions()->debug('Snapshot data', ['snapshot' => $snap]);
       
        if ($snap && isset($snap['hash'])  ) {
            
            $payload = json_decode($snap['data']);

            $loyaltyData = [];

            if (!empty($payload)) {
                $checkAvailablePrograms = (new LoyaltyService($siteId, $phoneNumber))->getAvailableLoyaltyPrograms($payload);
                $loyaltyRewards = $this->getNormalizedLoyaltyData(WC()->session->get('loyaltyData'));

                if (!empty($checkAvailablePrograms)) {
                    $userEnrollments = (new LoyaltyService($siteId, $phoneNumber))->getUserProgramsByPhone();
                    if(!empty($userEnrollments)) {
                        $loyaltyData = (new LoyaltyService($siteId, $phoneNumber))->mapCustomerLoyaltyPrograms($checkAvailablePrograms, $userEnrollments, $payload , $loyaltyRewards);

                        // Recompute canRedeem now so it reflects current gift-card and
                        // adjustment state, not the stale session flag from a prior request.
                        $this->updateRedeemAvailability($loyaltyRewards);
                        $loyaltyData['canRedeem'] = $loyaltyRewards['flags']['canRedeem'];

                        $customerId = $loyaltyData['CustomerId'] ?? null;
                        $sessionLoyalty = $this->getNormalizedLoyaltyData(WC()->session->get('loyaltyData'));
                        $sessionLoyalty['customer']['id'] = $customerId;
                        $sessionLoyalty['flags']['canRedeem'] = $loyaltyRewards['flags']['canRedeem'];
                        WC()->session->set('loyaltyData', $sessionLoyalty);
                    }
                }
            }

            if(empty($loyaltyData['AvailablePrograms'])) {
                return new \WP_Error(
                    'no_data_available',
                    __( 'No available programs were found for this phone number.', 'northstaronlineordering' ),
                    [ 'status' => 404 ]
                );
            }

            return rest_ensure_response($loyaltyData ? $loyaltyData : []);

        }
        }
    }
    public function redeemLoyalty($request) {
        $siteId =  sanitize_text_field($request['siteId']) ?? null;
        $loyaltyData = $request['loyalty'] ?? null;
        $program =  $request['program'] ?? null;
        $orderItems = $loyaltyData['OrderItems'] ?? null;
        $customerId = $loyaltyData['CustomerId'] ?? null;
        $phoneRaw = sanitize_text_field($loyaltyData['Phone']) ?? null;
        $phone = preg_replace('/\D+/', '', $phoneRaw);
        $rewardPoints = $program['points'] ?? null;

        $programId = $program['ProgramId'] ?? null;
        $qualifyingOrderItemIds = $program['QualifyingOrderItemIds'] ?? null;

        if (!$qualifyingOrderItemIds || !$programId) {
            return new \WP_Error(
                'missing_parameters',
                __( 'No programs or Qualifying Order Item IDs found', 'northstaronlineordering' ),
                [ 'status' => 400 ]
            );
        }

        $payload = [
            'ProgramId' => $programId,
            'OrderItemIds' => $qualifyingOrderItemIds,
            'Amount' => $rewardPoints,
            'Check' => [
                'OrderItems' => $orderItems ?? [],
                'CustomerId' => $customerId,
                'GuestPhoneNumber' => $phone
            ]
            ];

        $url = '/loyalty/validate';
        $validateLoyalty = (new Connection())->postData($siteId, $url, 'Token' , json_encode($payload));
        CBSLogger::transactions()->debug('Validating loyalty redemption', ['payload' => $payload]);
        if (!$validateLoyalty->Ok) {
            return new \WP_Error(
                'no_data_available',
                __( 'Not able to redeem reward', 'northstaronlineordering' ),
                [ 'status' => 400 ]
            );
        }
        $validateLoyalty->Data->qualifyingOrderItemIds = $qualifyingOrderItemIds;
        CBSLogger::transactions()->debug('Loyalty redemption validation response', ['data' => $validateLoyalty->Data]);
        $this->initializeSession();
        $points = $program['points'] ?? null;
        $programData = [
            'name' => $program['name'] ?? '',
            'points' => $points,
        ];
        $loyaltyProgram = $this->getNormalizedLoyaltyData(WC()->session->get('loyaltyData'));

        $adjustmentReasonId = $program['AdjustmentReasonId'] ?? '';
        $nameForKey = $program['name'] ?? ($programData['name'] ?? '');
        $uniqueKey = md5($programId . '|' . $adjustmentReasonId . '|' . $nameForKey);

        if (!isset($loyaltyProgram['programs'][$programId]) || !is_array($loyaltyProgram['programs'][$programId])) {
            $loyaltyProgram['programs'][$programId] = [];
        }

        $loyaltyProgram['programs'][$programId][$uniqueKey] = [
            'programId' => $programId,
            'uniqueKey' => $uniqueKey,
            'name' => $program['name'] ?? '',
            'points' => $points,
            'adjustmentReasonId' => $adjustmentReasonId,
            'qualifyingOrderItemIds' => $qualifyingOrderItemIds ?? [],
            'data'  => $programData ?? [],
            'check' => $validateLoyalty->Data ?? null,
        ];
        $loyaltyProgram['customer']['id'] = $customerId;

        $balance = $this->updateRedeemAvailability($loyaltyProgram);
        WC()->session->set('loyaltyData', $loyaltyProgram);
        $validateLoyalty->Data->Balance = $balance;

        return rest_ensure_response($validateLoyalty->Data ?? []);

    }

    public function undoRedeem($request) {

        $programId =  sanitize_text_field($request['programId']) ?? null;
        $uniqueKey =  sanitize_text_field($request['uniqueKey']) ?? null;

        if (!$programId || !$uniqueKey) {
            return rest_ensure_response(['ErrorMessage' => 'No program ID or unique key provided']);
        }

        $this->initializeSession();
        if(WC()->session->get('loyaltyData')) {
            $loyaltyProgram = $this->getNormalizedLoyaltyData(WC()->session->get('loyaltyData'));

            if(isset($loyaltyProgram['programs'][$programId][$uniqueKey])) {
                if(count($loyaltyProgram['programs'][$programId]) <= 1) {
                    unset($loyaltyProgram['programs'][$programId]);
                } else {
                    unset($loyaltyProgram['programs'][$programId][$uniqueKey]);
                }
            }

            $balanceAfterUndo = $this->updateRedeemAvailability($loyaltyProgram);
            WC()->session->set('loyaltyData', $loyaltyProgram);
            CBSLogger::transactions()->info('Undo redeem', ['programId' => $programId, 'uniqueKey' => $uniqueKey, 'loyaltyData' => $loyaltyProgram]);
        }

        return rest_ensure_response(['Message' => 'Loyalty redemption undone successfully', 'BalanceAfterUndo' => $balanceAfterUndo]);
    }

    /** 
     * Deprecated loyalty functions - to be removed with old loyalty integration */
    public function redeemRewards($request) {
        $siteId = $this->getSiteId();
        $url = '/loyalty/redeem';
        $connection = new Connection();

        $bodyData = $request->get_body();
        $data = json_decode($bodyData);
        $this->initializeSession();
        $customerData = WC()->session->get('customerData');
        $data->CustomerId = $customerData[0]->CustomerId;
        
        try {
            $response = $connection->postData($siteId, $url, 'Token', json_encode($data));
        } catch(\Exception $e) {
            CBSLogger::transactions()->error('Redeem rewards exception', ['message' => $e->getMessage()]);
            return new WP_Error('error', 'Exception occurred while redeeming rewards', array('status' => 400));
        }
        if($response->Ok) {
            $loyaltyData = WC()->session->get('loyaltyData');
            $loyaltyData['newCheck'] = null;
            WC()->session->set('loyaltyData', $loyaltyData);
            return rest_ensure_response($response->Data);
        }
        return rest_ensure_response($response->ErrorMessage);
    }
//deprecated function, new loyalty integration
    public function getRewards($request) {
        $configuration = ConfigurationRepository::create();
        $site = $configuration->getDetails();
        $siteDetails = $configuration->getSiteDetails($site->id);
        $siteId = $siteDetails[0]->siteid;
        $phoneNumber = $request->get_param('phoneNumber');
        //change variable to areaExternalCode
        $areaExternalCode = $request->get_param('areaId');
        $url = '/membership/phonenumber/'.$phoneNumber;
        $connection = new Connection();

        try {
            $response   = $connection->getData($siteId, $url, 'Token');
        } catch(\Exception $e) {
            CBSLogger::transactions()->error('Get rewards exception', ['message' => $e->getMessage()]);
            return rest_ensure_response($e->getMessage());
        }

        $dataResponse = [];
        if($response->Ok) {
            $customerData = $response->Data;
            if($customerData[0]->LoyaltyAccount->Active){
                $loyaltyData = $this->getRewardsData($siteId, $customerData, $areaExternalCode);
                $this->initializeSession();
                WC()->session->set('customerData', $customerData);

                if($loyaltyData) {
                    $dataResponse = $loyaltyData;
                }
                else{
                    $dataResponse["ErrorMessage"] = 'No rewards available for this check';
                }
            }
            else{
                $dataResponse["ErrorMessage"] = 'No Active Loyalty Account';
            }
        }
        else{
            $dataResponse["ErrorMessage"] = $response->ErrorMessage;
        }
        return rest_ensure_response($dataResponse);
    }

    private function getRewardsData($siteId, $customerData, $areaExternalCode) {
        $checkNumber = $this->submitCheck($siteId, $customerData[0]->CustomerId, $areaExternalCode);
        $this->initializeSession();
        $checkTotal = 0;
        if(WC()->session->get('loyaltyData')) {
            $checkTotal = WC()->session->get('loyaltyData')['totalCheckAmount'];
        }

        if(!$checkNumber) {
            return null;
        }

        $url = '/loyalty/'.$checkNumber;
        $connection = new Connection();

        try {
            $response   = $connection->getData($siteId, $url, 'Token');
        } catch(\Exception $e) {
            CBSLogger::transactions()->error('Get loyalty data exception', ['message' => $e->getMessage()]);
            return null;
        }

        if($response->Ok) {
            $data = $response->Data;
            $data->CheckTotal = $checkTotal;
        }
        else{
            $data = null;
        }

        return $data;
    }

    private function submitCheck($siteId,$customerId, $areaExternalCode) {
        if ( is_null( WC()->cart ) ) {
            WC()->frontend_includes();
            wc_load_cart();
        }
        $this->initializeSession();

        if(WC()->session->get('loyaltyData')) {
            $loyaltyData = WC()->session->get('loyaltyData');
            return $loyaltyData['checkNumber'];
        }

        WC()->cart->get_cart();
        $cart = WC()->cart;

        $items = $cart->get_cart();

        $deliveryDate = current_time('Y-m-d H:i:s');
        $check = (new CartOrderReviewDto([
            'order'         => $cart,
            'orderType'     => 0,
            'deliveryDate'  => $deliveryDate,
            'orderItems'    => $items,
            'tableNumber'   => "",
            'areaExternalCode' => $areaExternalCode,
            'customerId'    => $customerId,
        ]))->toJson();

        $path = '/checks/00000000-0000-0000-0000-000000000000/submit';
        $connection = new Connection();
        try {
            $response = $connection->postData($siteId, $path, 'Token', $check);
        } catch (\Throwable $th) {
            CBSLogger::transactions()->error('Submit check exception', ['message' => $th->getMessage()]);
            return null;
        }
        $checkNumber = $response->Data->CheckNumber;
        $checkId = $response->Data->CheckId;
        $balance = $response->Data->Balance;

        $loyaltyData= [];
        $loyaltyData['checkNumber'] = $checkNumber;
        $loyaltyData['checkId'] = $checkId;
        $loyaltyData['totalCheckAmount'] = $balance;
        $loyaltyData['customerId'] = $customerId;

        WC()->session->set('loyaltyData', $loyaltyData);

        return $checkNumber;
    }

    public function removeGiftCard($request) {
        $giftCard = $request->get_param('gc');
        if ( ! WC()->session ) {
            WC()->session = new \WC_Session_Handler();
            WC()->session->init();
        }
        $giftCardArray = WC()->session->get('giftCardData');
        $newGiftCardArray = [];
        foreach($giftCardArray as $giftCardData) {
            if($giftCardData['giftCardNumber'] !== $giftCard) {
                $newGiftCardArray[] = $giftCardData;
            }
        }
        WC()->session->set('giftCardData', $newGiftCardArray);
        return rest_ensure_response('Gift card removed successfully');
    }

    public function validateGifCard($request) {
        $giftCard = $request->get_param('giftCardNumber');
        $configuration = ConfigurationRepository::create();
        $site = $configuration->getDetails();
        $siteDetails = $configuration->getSiteDetails($site->id);
        $siteId = $siteDetails[0]->siteid;
        $url = '/giftcards/'.$giftCard;
        $connection = new Connection();

        try {
            $response = $connection->getData($siteId, $url, 'Token');
        } catch(\Exception $e) {
            CBSLogger::transactions()->error('Gift card validation exception', ['message' => $e->getMessage()]);
            return new WP_Error('error', 'Exception occurred while fetching gift card data', array('status' => 400));
        }

        $validate = $this->isValidGiftCard($response, $giftCard);
        
        if($validate instanceof WP_Error) {
            return $validate;
        }

        if ( ! WC()->session ) {
            WC()->session = new \WC_Session_Handler();
            WC()->session->init();
        }

        if ( is_null( WC()->cart ) ) {
            WC()->frontend_includes();
            wc_load_cart();
        }
        
        WC()->cart->get_cart();
        $cart = WC()->cart;
        $cartTotal = $cart->get_total('edit');
        $giftCardArray = WC()->session->get('giftCardData');
        $data = $response->Data;
        $giftCardBalance = $data->Balance;
        $giftCardEnding = substr($giftCard, -4);
        $giftCardReduce = $giftCardBalance >= $cartTotal ? -$cartTotal : -$giftCardBalance;

        $giftCardArray[] = array(
            'giftCardNumber' => $giftCard,
            'giftCardBalance' => $giftCardBalance,
            'giftCardLastFour' => $giftCardEnding,
            'giftcardReduce' => $giftCardReduce,
        );

        WC()->session->set('giftCardData', $giftCardArray);

        return rest_ensure_response($data);
    }

    private function isValidGiftCard($response, $giftCard) {
        $errorMessage = $this->validateResponse($response);
        if (!$errorMessage) {
            $errorMessage = $this->validateGiftCardStatus($response);
        }
        if (!$errorMessage) {
            $errorMessage = $this->validateGiftCardBalance($response);
        }
        if (!$errorMessage) {
            $this->initializeSession();
            $errorMessage = $this->validateGiftCardNotPresent($giftCard);
        }
        if (!$errorMessage) {
            $this->initializeCart();
            $errorMessage = $this->validateCartTotal();
        }

        if ($errorMessage) {
            return new WP_Error('error', $errorMessage, array('status' => 400));
        }

        return true;
    }

    private function validateResponse($response) {
        if (is_null($response) || $response && !$response->Ok) {
            return $response->ErrorMessage ?? 'no answer from API';
        }
        return null;
    }

    private function validateGiftCardStatus($response) {
        if ($response->Data->CardStatus !== 1) {
            return 'Gift card is inactive';
        }
        return null;
    }

    private function validateGiftCardBalance($response) {
        if ($response->Data->Balance <= 0) {
            return 'Gift card balance is 0';
        }
        return null;
    }

    private function initializeSession() {
        if (!WC()->session) {
            WC()->session = new \WC_Session_Handler();
            WC()->session->init();
        }
    }

    private function initializeCart() {
        if (is_null(WC()->cart)) {
            WC()->frontend_includes();
            wc_load_cart();
        }
        WC()->cart->get_cart();
    }

    private function getNormalizedLoyaltyData($loyaltyData): array {
        $normalizedData = $this->getEmptyLoyaltyData();

        if (!is_array($loyaltyData)) {
            return $normalizedData;
        }

        if (isset($loyaltyData['programs']) && is_array($loyaltyData['programs'])) {
            $normalizedData['programs'] = $loyaltyData['programs'];
        }

        $normalizedData['customer']['id'] = $loyaltyData['customer']['id']
            ?? $loyaltyData['customerId']
            ?? null;

        $normalizedData['flags']['canRedeem'] = (bool) (
            $loyaltyData['flags']['canRedeem']
            ?? $loyaltyData['canRedeem']
            ?? WC()->session->get('canRedeem')
            ?? true
        );

        return $normalizedData;
    }

    private function getEmptyLoyaltyData(): array {
        return [
            'customer' => [
                'id' => null,
            ],
            'programs' => [],
            'flags' => [
                'canRedeem' => true,
            ],
        ];
    }

    private function updateRedeemAvailability(array &$loyaltyProgram): float {
        $allPrograms = !empty($loyaltyProgram['programs'])
            ? array_merge(...array_values($loyaltyProgram['programs']))
            : [];

        $adjustmentTotal = array_sum(
            array_map(fn($program) => isset($program['check']->AdjustmentTotal) ? abs((float) $program['check']->AdjustmentTotal) : 0, $allPrograms)
        );

        // Gift cards already reduce the order total in WC fees, so a fully
        // gift-card-covered order would still appear redeemable based on
        // subtotal alone. Subtract gift-card amounts so canRedeem reflects
        // the real remaining balance the customer must pay.
        $giftCardTotal = 0.0;
        $giftCardArray = WC()->session->get('giftCardData');
        if (is_array($giftCardArray)) {
            foreach ($giftCardArray as $giftCardData) {
                $giftCardTotal += abs((float) ($giftCardData['giftcardReduce'] ?? 0));
            }
        }

        $this->initializeCart();
        $cart = WC()->cart;
        $payableTotal = (float) $cart->get_subtotal()
            + (float) $cart->get_subtotal_tax()
            + (float) $cart->get_shipping_total()
            + (float) $cart->get_shipping_tax();
        $balance = $payableTotal - $adjustmentTotal - $giftCardTotal;

        $loyaltyProgram['flags']['canRedeem'] = $balance > 0;
        WC()->session->set('canRedeem', $balance > 0);

        return $balance;
    }

    private function validateGiftCardNotPresent($giftCard) {
        $giftCardArray = WC()->session->get('giftCardData');
        if ($giftCardArray) {
            foreach ($giftCardArray as $giftCardData) {
                if ($giftCardData['giftCardNumber'] === $giftCard) {
                    return 'Gift card already added';
                }
            }
        }
        return null;
    }

    private function validateCartTotal() {
        $cart = WC()->cart;
        $cartTotal = $cart->get_total('edit');
        if ($cartTotal <= 0) {
            return 'Cart total is already 0';
        }
        return null;
    }

    private function getSiteId() {
        $configuration = ConfigurationRepository::create();
        $site = $configuration->getDetails();
        $siteDetails = $configuration->getSiteDetails($site->id);
        return $siteDetails[0]->siteid;
    }
}
