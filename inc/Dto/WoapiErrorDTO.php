<?php

namespace CBSNorthStar\Dto;

class WoapiErrorDTO extends BaseDto
{

  protected $url;
  protected $siteId;
  protected $payload;
  protected $response;
  protected $result;
  protected $method;
  protected $instance;

  public function __construct(array $data)
  {
      $this->url = $data['url'];
      $this->siteId = $data['siteId'];
      $this->instance = $data['instance'];
      $this->payload = $data['payload'];
      $this->response = $data['response'];
      $this->result = $data['result'];
      $this->method = $data['method'];
  }
  
  public function toArray(): array
  {
      return [
          'fields' => [
              'source'    => 'woocommerce',
              'site'    => $this->siteId,
              'instance' => strtolower($this->instance),
          ],
          'message' => 'Woocommerce error',
          'woocommerceDetail' => [
              'WOAPIError' => [
                  'request' => [
                      'url' => $this->url,
                      'method' => $this->method,
                      'content' => $this->payload['body'],
                      'arguments' => $this->payload['headers'],
                  ],
                  'response' => [
                      'statusCode' => $this->result['response']['code'],
                      'content' => [
                          'headers' => $this->result['headers'],
                          'body' => $this->result['body']
                      ],
                  ]
              ]
          ]
      ];
  }
}
