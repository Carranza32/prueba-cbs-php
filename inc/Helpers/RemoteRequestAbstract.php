<?php

namespace CBSNorthStar\Helpers;

use CBSNorthStar\Logger\CBSLogger;

abstract class RemoteRequestAbstract
{
    protected $result;
    protected string $url = '';
    protected array $args = [];
    protected $body;
    const CONTENT_TYPE = 'application/json';

    protected bool $logErrorToUI = true;
    protected array $payload;

    abstract public static  function create();

    abstract protected function prepareGet(array $data);
    abstract protected function preparePost(array $data);
    abstract protected function prepareDelete(array $data);

    public function prepareRequest($method, $args): RemoteRequestAbstract
    {
        $this->args['headers'] = $args['headers'] ?? [];
        $this->args['method'] = $method;
        $this->args['timeout'] = $args['timeout'] ?? 45;

        if(in_array($method,['POST', 'PUT'])) {
            $this->args['body'] = $this->body;
        }

        $this->payload = $this->args;

        return $this;
    }

    public function get(string $url, array $data)
    {
        $this->url = $url;
        return $this->prepareGet($data)
            ->request($this->url, $this->args);
    }

    public function post($url, array $data)
    {
        $this->url = $url;
        return $this->preparePost($data)
            ->request($this->url, $this->args, 'POST');
    }

    public function especialPost($url, $data)
    {
        $this->logErrorToUI = false;

        return $this->post($url, $data);
    }

    public function put($url,$data)
    {
        return $this->preparePost($data)
            ->request($url, $this->args, 'PUT');
    }

    public function especialPut($url, $data)
    {
        $this->logErrorToUI = false;
        return $this->put($url, $data);
    }

    public function delete($url, $data)
    {
        return $this->prepareDelete($data)
            ->request($url, $this->args, 'DELETE');
    }

    public function request($url, $args= [], $method = 'GET')
    {
        $this->url = $url;

        return $this->prepareRequest($method, $args)
            ->processRequest($this->payload, $this->logErrorToUI);
    }

    /**
     *
     * @param array $args
     * @param bool $logErrorToUI
     * @return mixed
     */
    abstract protected function processRequest(array $args, bool $logErrorToUI = true);

    protected function haveWordpressErrors($result): bool
    {
        if (is_wp_error($result)) {
            CBSLogger::api()->error('WordPress HTTP error', ['message' => $result->get_error_message()]);
            return true;
        }

        return false;
    }

    protected function responseFailed($result): bool
    {
        if($result['response']['code'] >= 300) {
            return true;
        }

        return false;
    }


    /**
     * @param $result
     * @return mixed
     */
    public function getResponse($result)
    {
        $body = wp_remote_retrieve_body($result);

        return json_decode($body);
    }

}
