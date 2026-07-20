<?php

namespace CBSNorthStar\Helpers;

use CBSNorthStar\Dto\WoapiErrorDTO;
use CBSNorthStar\Helpers\SessionReference;
use CBSNorthStar\Logger\CBSLogger;
use CBSNorthStar\Logger\ElkStackLogger;
use CBSNorthStar\Repositories\ConfigurationRepository;

class WoapiRequest extends RemoteRequestAbstract
{

    public static function create(): WoapiRequest
    {
        return new self();
    }

    /**
     * @param $args
     * @param bool $logErrorToUI
     * @return mixed
     */
    protected function processRequest($args, bool $logErrorToUI = true)
    {
        $result = wp_remote_request($this->url, $args);

        $response = $this->getResponse($result);

        if($this->haveWordpressErrors($result)){
            CBSLogger::api()->error('WordPress HTTP error', ['response' => $response]);

            return $response;
        }

        if($this->responseFailed($result)){
            CBSLogger::api()->error('API request failed', [
                'url'      => $this->url,
                'payload'  => $this->payload,
                'result'   => $result,
                'response' => $response,
                'method'   => $args['method'],
            ]);

            ElkStackLogger::create()
                ->log(new WoapiErrorDTO([
                  'url'       => $this->url,
                  'siteId'    => $_COOKIE['siteid'],
                  'instance'  => strtolower(ConfigurationRepository::create()->getDetails()->instance),
                  'payload'   => $this->payload,
                  'response'  => $response,
                  'result'    => $result,
                  'method'    => $args['method']
                ]));

            if($logErrorToUI && !(defined('REST_REQUEST') && REST_REQUEST)){
                CBSLogger::api()->info('Logging error to UI');
                if (function_exists('wc_add_notice')) {
                    // A single cart action re-runs calculate_totals() several times,
                    // so a persistently failing API call would queue the same error
                    // notice on each pass and stack identical copies. Add it only
                    // once (OE-26589).
                    $apiErrorNotice = ErrorMessageList::create()->getError($response->ErrorMessage);
                    if ( ! wc_has_notice( $apiErrorNotice, 'error' ) ) {
                        wc_add_notice( $apiErrorNotice, 'error' );
                    }
                } else {
                    CBSLogger::api()->error('Payment error message', ['message' => $response->ErrorMessage]);
                }
            }

            return $response;
        }

        if (empty($response->Data)) {
            CBSLogger::api()->debug('Response data is empty', ['response' => $response]);
        }

        return $response;
    }

    protected function prepareGet(array $data): WoapiRequest
    {
        $token = $data['token'];
        $tokenType = $data['tokenType'] ?? 'Bearer';

        $this->args = [
          'headers' => [
            'Content-Type'         => self::CONTENT_TYPE,
            'Authorization'        => "$tokenType $token",
            'TransactionReference' => SessionReference::get(),
          ]
        ];
        return $this;
    }

    protected function preparePost(array $data): WoapiRequest
    {
        $token = $data['token'];
        $tokenType = $data['tokenType'] ?? 'Token';
        $timeout = $data['timeout'] ?? null;
 
        $this->args = [
            'headers' => [
                'Authorization'        => "$tokenType $token",
                'Content-Type'         => self::CONTENT_TYPE,
                'TransactionReference' => SessionReference::get(),
            ],
            'timeout' => $timeout,
        ];
 
        $this->body = $data['body'] ?? [];
 
        return $this;
    }

    protected function prepareDelete(array $data): WoapiRequest
    {
        $token = $data['token'];
        $tokenType = $data['tokenType'] ?? 'Token';

        $this->args = [
            'headers' => [
                'Authorization'        => "$tokenType $token",
                'Content-Type'         => self::CONTENT_TYPE,
                'TransactionReference' => SessionReference::get(),
            ],
        ];

        return $this;
    }
}
