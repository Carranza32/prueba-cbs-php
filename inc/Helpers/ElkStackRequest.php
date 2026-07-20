<?php

namespace CBSNorthStar\Helpers;

use CBSNorthStar\Logger\CBSLogger;

class ElkStackRequest extends RemoteRequestAbstract
{

  public static function create(): ElkStackRequest
  {
    return new self();
  }

  protected function prepareGet(array $data): ElkStackRequest
  {
    return $this;
  }

  protected function preparePost(array $data): ElkStackRequest
  {
    $this->body = json_encode($data);

    return $this;
  }

  protected function prepareDelete(array $data): ElkStackRequest
  {
    return $this;
  }

  protected function processRequest(array $args, bool $logErrorToUI = true)
  {
      $result = wp_remote_request($this->url, $args);


      $response = $this->getResponse($result);

      if($this->haveWordpressErrors($result)){
        CBSLogger::api()->error('WordPress HTTP error sending to ELK Stack');
      }
      CBSLogger::api()->debug('ELK Stack response', ['response' => $response]);

      return $response;
  }
}
