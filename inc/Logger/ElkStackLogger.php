<?php

namespace CBSNorthStar\Logger;


use CBSNorthStar\Dto\WoapiErrorDTO;
use CBSNorthStar\Helpers\ElkStackRequest;

class ElkStackLogger
{
    protected string $url = 'https://logs.cbsnorthstar.com/log';

    public static function create(): ElkStackLogger
    {
      return new self();
    }


    public function log(WoapiErrorDTO $responseDTO)
    {
      return ElkStackRequest::create()->put($this->url, $responseDTO->toArray());
    }

}
