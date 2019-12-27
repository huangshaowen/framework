<?php

namespace framework\core\exception;

class ValidateException extends \RuntimeException {

    protected $error;

    public function __construct($error, $code = 0) {
        $this->error = $error;
        $this->message = is_array($error) ? implode(PHP_EOL, $error) : $error;
        $this->code = $code;
    }

    /**
     * 获取验证错误信息
     * @access public
     * @return array|string
     */
    public function getError() {
        return $this->error;
    }

}
