<?php

namespace CBSNorthStar\Woapi;

use CBSNorthStar\Dto\PaymentDTO;
use CBSNorthStar\Helpers\SessionReference;
use CBSNorthStar\Logger\CBSLogger;
use CBSNorthStar\Repositories\SessionEventRepository;

class Payment {

    public function makePayment($pan, $checkId, $siteId , $transactionNumber, $total, $tip)
    {
        $siteRequestUrl = "/checks/$checkId/Payment";
        $paymentType = 5;

        $paymentJson =  (new PaymentDTO(
            $paymentType,
            $pan,
            $transactionNumber,
            $checkId,
            $total,
            $tip
        ))
        ->toJson();

        $connection = new Connection();
        $responseValidatePayment = $connection->postData($siteId, $siteRequestUrl, 'Token' , $paymentJson);

        if($responseValidatePayment->ErrorMessage!=""){
            $errMsg = $responseValidatePayment->ErrorMessage ;
            $_SESSION['error'] = $errMsg;
            CBSLogger::payments()->error('Payment failed', ['message' => $errMsg]);
            try {
                SessionEventRepository::create()->logEvent(
                    SessionReference::get(),
                    SessionEventRepository::EVENT_PAYMENT_FAILED,
                    SessionEventRepository::STATUS_FAILED,
                    ['message' => $errMsg, 'check_id' => $checkId],
                    null,
                    $siteId
                );
            } catch (\Exception $e) {
                error_log('[Payment] Failed to log EVENT_PAYMENT_FAILED: ' . $e->getMessage());
            }
            return $responseValidatePayment->ErrorMessage;
        }else {
            try {
                SessionEventRepository::create()->logEvent(
                    SessionReference::get(),
                    SessionEventRepository::EVENT_PAYMENT_PROCESSED,
                    SessionEventRepository::STATUS_SUCCESS,
                    ['check_id' => $checkId, 'total' => $total],
                    null,
                    $siteId
                );
            } catch (\Exception $e) {
                error_log('[Payment] Failed to log EVENT_PAYMENT_PROCESSED: ' . $e->getMessage());
            }
            return $responseValidatePayment;
        }
        
    }
}
