<?php

namespace Hhxsv5\LaravelS\Swoole;

use Illuminate\Http\Request as IlluminateRequest;
use Symfony\Component\HttpFoundation\ParameterBag;

class Request
{
    protected $swooleRequest;

    public function __construct(\swoole_http_request $request)
    {
        $this->swooleRequest = $request;
    }

    /**
     * @param array $rawServer
     * @param array $rawEnv
     * @return IlluminateRequest
     */
    public function toIlluminateRequest(array $rawServer = [], array $rawEnv = [])
    {
        $_GET = isset($this->swooleRequest->get) ? $this->swooleRequest->get : [];
        $_POST = isset($this->swooleRequest->post) ? $this->swooleRequest->post : [];
        $_COOKIE = isset($this->swooleRequest->cookie) ? $this->swooleRequest->cookie : [];
        $server = isset($this->swooleRequest->server) ? $this->swooleRequest->server : [];
        $headers = isset($this->swooleRequest->header) ? $this->swooleRequest->header : [];
        $_FILES = isset($this->swooleRequest->files) ? $this->swooleRequest->files : [];
        $_REQUEST = [];

        foreach ($headers as $key => $value) {
            $key = str_replace('-', '_', $key);
            $server['http_' . $key] = $value;
        }
        $_SERVER = array_merge($rawServer, array_change_key_case($server, CASE_UPPER));
        $_ENV = $rawEnv;

        // Fix client real-ip
        if (isset($headers['x-real-ip'])) {
            $_SERVER['REMOTE_ADDR'] = (string)$headers['x-real-ip'];
        }
        // Fix client real-port
        if (isset($headers['x-real-port'])) {
            $_SERVER['REMOTE_PORT'] = (int)$headers['x-real-port'];
        }

        // Fix argv & argc
        if (!isset($_SERVER['argv'])) {
            $_SERVER['argv'] = isset($GLOBALS['argv']) ? $GLOBALS['argv'] : [];
            $_SERVER['argc'] = isset($GLOBALS['argc']) ? $GLOBALS['argc'] : 0;
        }

        $requests = ['C' => $_COOKIE, 'G' => $_GET, 'P' => $_POST];
        $requestOrder = ini_get('request_order') ?: ini_get('variables_order');
        $requestOrder = preg_replace('#[^CGP]#', '', strtoupper($requestOrder)) ?: 'GP';
        foreach (str_split($requestOrder) as $order) {
            $_REQUEST = array_merge($_REQUEST, $requests[$order]);
        }

        $request = IlluminateRequest::capture();

        /**
         * Fix missed rawContent & parse JSON into $_POST
         * @see \Illuminate\Http\Request::createFromBase()
         */
        $reflection = new \ReflectionObject($request);
        $content = $reflection->getProperty('content');
        $content->setAccessible(true);
        $content->setValue($request, $this->swooleRequest->rawContent());
        $json = $reflection->getProperty('json');
        $json->setAccessible(true);
        $json->setValue($request, null);
        $getInputSource = $reflection->getMethod('getInputSource');
        $getInputSource->setAccessible(true);
        $request->request = $getInputSource->invoke($request);

        if (0 === strpos($request->headers->get('CONTENT_TYPE'), 'application/x-www-form-urlencoded')
            && in_array(strtoupper($request->server->get('REQUEST_METHOD', 'GET')), ['PUT', 'DELETE', 'PATCH'])
        ) {
            parse_str($request->getContent(), $data);
            $request->request = new ParameterBag($data);
        }

        return $request;
    }

}
