<?php

namespace framework\core\exception;

use framework\core\Response;

class HttpResponseException extends \RuntimeException {

    /**
     * @var Response
     */
    protected $response;

    public function __construct(Response $response) {
        $this->response = $response;
    }

    public function getResponse() {
        return $this->response;
    }

}
