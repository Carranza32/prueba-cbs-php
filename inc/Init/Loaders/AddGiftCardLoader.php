<?php

namespace CBSNorthStar\Init\Loaders;

use CBSNorthStar\Woapi\Connection;
use CBSNorthStar\Repositories\ConfigurationRepository;
use CBSNorthStar\Logger\CBSLogger;
use WP;
use WP_Error;


class AddGiftCardLoader implements JavaScriptLoaderContract
{
    private static $instance = null;

    public static function create(): ?AddGiftCardLoader
    {
        if (self::$instance === null) {
            self::$instance = new AddGiftCardLoader();
        }

        return self::$instance;
    }

    public function registerScripts()
    {
        add_action('wp_ajax_add_gift_card_action', array($this, 'ajaxHandler'));
        add_action('wp_ajax_nopriv_add_gift_card_action', array($this, 'ajaxHandler'));
        add_action('wp_ajax_delete_gift_card_action', array($this, 'ajaxDeleteHandler'));
        add_action('wp_ajax_nopriv_delete_gift_card_action', array($this, 'ajaxDeleteHandler'));
    }

    public function ajaxDeleteHandler(): void
    {
        if (isset($_POST['action']) && $_POST['action'] === 'delete_gift_card_action') {
            $giftCard = $_POST['giftcard'];
            $this->deleteGiftCard($giftCard);
        }
    }

    public function ajaxHandler(): void
    {
        if (isset($_POST['action']) && $_POST['action'] === 'add_gift_card_action') {
            $giftCard = $_POST['giftcard'];
            $this->validateGiftCard($giftCard);
        }
    }

    private function deleteGiftCard($giftCard): void
    {
        $this->initializeSession();
        $giftCardArray = WC()->session->get('giftCardData');

        $newGiftCardArray = array();
        
        foreach ($giftCardArray as $giftCardData) {
            if ($giftCardData['giftCardNumber'] !== $giftCard) {
                $newGiftCardArray[] = $giftCardData;
            }
        }

        WC()->session->set('giftCardData', $newGiftCardArray);
        wp_send_json_success('Gift card deleted successfully');
    }

    private function validateGiftCard($giftCard): void
    {
        $configuration = $this->getConfiguration();
        $siteId = $this->getSiteId($configuration);
        $response = $this->getApiResponse($siteId, $giftCard);
        $error = null;

        if ($this->isInvalidResponse($response)) {
            $error = $this->createErrorResponse($response);
        }
         else {
            $valid = $this->verifyGiftCardStatus($response->Data);
            if ($valid instanceof WP_Error) {
                $error = $valid;
            } else {
                $this->initializeSession();
    
                if ($this->isGiftCardAlreadyAdded($giftCard)) {
                    $error = new WP_Error('error', 'Gift card already added', array('status' => 400));
                } else {
                    $this->initializeCart();
                        if ($this->isCartTotalZero()) {
                            $error = new WP_Error('error', 'Cart total is already 0', array('status' => 400));
                        } else {
                            $this->applyGiftCardToCart($giftCard, $response->Data);
                        }
                }
            
            }
        }

        if ($error) {
            CBSLogger::transactions()->error('Gift card validation error', ['message' => $error->get_error_message()]);
            $data = array(
                'error' => true,
                'message' => $error->get_error_message()
            );
            wp_send_json_error($data);
            return;
        }

        $response = array(
            'error' => false,
            'message' => 'Gift card added successfully'
        );
    
        wp_send_json_success($response);
    }

    private function getConfiguration()
    {
        return ConfigurationRepository::create();
    }

    private function getSiteId($configuration)
    {
        $site = $configuration->getDetails();
        $siteDetails = $configuration->getSiteDetails($site->id);
        return $siteDetails[0]->siteid;
    }

    private function getApiResponse($siteId, $giftCard):mixed
    {
        $url = '/giftcards/'.$giftCard;
        $connection = new Connection();

        try {
            return $connection->getData($siteId, $url, 'Token');
        } catch(\Exception $e) {
            CBSLogger::transactions()->error('Gift card API error', ['exception' => $e->getMessage()]);
            return null;
        }
    }

    private function isInvalidResponse($response): bool
    {
        return is_null($response) || $response && !$response->Ok;
    }

    private function createErrorResponse($response): WP_Error
    {
        $errorMessage = $response->ErrorMessage ?? 'no answer from API';
        $statusCode = 400;
        return new WP_Error('error', $errorMessage, array('status' => $statusCode));
    }

    private function initializeSession(): void
    {
        if (!WC()->session) {
            WC()->session = new \WC_Session_Handler();
            WC()->session->init();
        }
    }

    private function isGiftCardAlreadyAdded($giftCard): bool
    {
        $giftCardArray = WC()->session->get('giftCardData');
        if ($giftCardArray) {
            foreach ($giftCardArray as $giftCardData) {
                if ($giftCardData['giftCardNumber'] === $giftCard) {
                    return true;
                }
            }
        }

        return false;
    }

    private function initializeCart(): void
    {
        if (is_null(WC()->cart)) {
            WC()->frontend_includes();
            wc_load_cart();
        }
    }

    private function isCartTotalZero(): bool
    {
        $cart = WC()->cart;
        $cartTotal = $cart->get_total('edit');
        return $cartTotal <= 0;
    }

    private function applyGiftCardToCart($giftCard, $data): void
    {
        CBSLogger::transactions()->info('Applying gift card', ['last_four' => substr($giftCard, -4)]);
        $giftCardArray = WC()->session->get('giftCardData');

        $giftCardBalance = $data->Balance;
        $giftCardEnding = substr($giftCard, -4);

        $giftCardArray[] = array(
            'giftCardNumber' => $giftCard,
            'giftCardBalance' => $giftCardBalance,
            'giftCardLastFour' => $giftCardEnding,
        );

        WC()->session->set('giftCardData', $giftCardArray);
    }

    private function verifyGiftCardStatus($data): WP_Error|bool
    {
        if ($data->CardStatus !== 1) {
            $errorMessage = 'Gift card is inactive';
        } elseif ($data->Balance <= 0) {
            $errorMessage = 'Gift card balance is 0';
        }

        if (isset($errorMessage)) {
            return new WP_Error('error', $errorMessage, array('status' => 400));
        }
        return true;
    }
}
