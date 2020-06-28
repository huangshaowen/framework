<?php

namespace framework\core;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use framework\core\log\handler\Handler;
use framework\core\log\handler\FileHandler;

/**
 * 日志类
 */
class Log extends AbstractLogger {

    private $_messages = []; // 日志数据
    private $_levels = [];
    private $_handler;

    public function __construct(Handler $handler = null) {
        $refl = new \ReflectionClass('Psr\Log\LogLevel');
        $this->_levels = array_values($refl->getConstants());
        $this->_handler = $handler ? $handler : new FileHandler();
    }

    private function _validLevel($level = 0) {
        if (!in_array($level, $this->_levels)) {
            throw new \Psr\Log\InvalidArgumentException("Unkonw log level given: {$level}");
        }
    }

    public function setHandler(Handler $handler) {
        $this->_handler = $handler;
        return $this;
    }

    public function getHandler() {
        return $this->_handler;
    }

    public static function getInstance() {
        static $obj;
        if (!$obj) {
            $obj = new self();
        }
        return $obj;
    }

    /**
     * 严重错误
     * @param type $message
     * @param array $context
     * @return $this
     */
    public function emergency($message, array $context = array()) {
        $this->log(LogLevel::EMERGENCY, $message, $context);
        return $this;
    }

    /**
     * **必须**立刻采取行动
     * @param type $message
     * @param array $context
     * @return $this
     */
    public function alert($message, array $context = array()) {
        $this->log(LogLevel::ALERT, $message, $context);
        return $this;
    }

    /**
     * 紧急情况
     * @param type $message
     * @param array $context
     * @return $this
     */
    public function critical($message, array $context = array()) {
        $this->log(LogLevel::CRITICAL, $message, $context);
        return $this;
    }

    /**
     * 运行时出现的错误，不需要立刻采取行动，但必须记录下来以备检测。
     * @param type $message
     */
    public function error($message, array $context = array()) {
        $this->log(LogLevel::ERROR, $message, $context);
        return $this;
    }

    /**
     * 出现非错误性的异常
     * @param type $message
     * @param array $context
     * @return $this
     */
    public function warning($message, array $context = array()) {
        $this->log(LogLevel::WARNING, $message, $context);
        return $this;
    }

    /**
     * 一般性重要的事件
     * @param type $message
     * @param array $context
     * @return $this
     */
    public function notice($message, array $context = array()) {
        $this->log(LogLevel::NOTICE, $message, $context);
        return $this;
    }

    /**
     *  信息: 程序输出信息
     * @param type $message
     * @param array $context
     * @return $this
     */
    public function info($message, array $context = array()) {
        $this->log(LogLevel::INFO, $message, $context);
        return $this;
    }

    /**
     * 调试: 调试信息
     * @param type $message
     * @param array $context
     * @return $this
     */
    public function debug($message, array $context = array()) {
        $this->log(LogLevel::DEBUG, $message, $context);
        return $this;
    }

    /**
     * 记录 sql
     * @param type $message
     */
    public function sql($message, array $context = array()) {
        $this->record(LogLevel::ALERT, $message, $context);
    }

    public function log($level, $message, array $context = []) {
        $this->_validLevel($level);
        $context['level'] = $level;
        $this->_messages[$level][] = $this->format($message, $context);
        return $this;
    }

    public function format($message, array $context = []) {
        $replace = [];

        if (empty($message)) {
            return false;
        } else {
            $message = is_array($message) ? serialize($message) : $message;
        }

        $now = date('Y-m-d H:i:s');

        if (php_sapi_name() == "cli") {
            $messageWrapped = "{$now}  {$message}\r\n---------------------------------------------------------------cli\r\n";
        } else {
            $uri = Request::getInstance()->get_full_url();
            $source_url = Request::getInstance()->get_url_source();
            $ip = Request::getInstance()->ip(0, true);
            $ua = Request::getInstance()->get_user_agent();
            $method = Request::getInstance()->method();
            $messageWrapped = "[{$now}] {$ip} {$method} {$uri}\r\n{$source_url}\r\n{$ua}\r\n{$message}\r\n---------------------------------------------------------------\r\n";
        }

        foreach ($context as $key => $val) {
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }

        return strtr($messageWrapped, $replace);
    }

    public function flush() {
        if (empty($this->_messages)) {
            return;
        }
        $this->_handler->write($this->_messages);
        $this->_messages = [];
    }

    public function __destruct() {
        $this->flush();
    }

}
