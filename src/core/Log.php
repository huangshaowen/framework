<?php

namespace framework\core;

/**
 * 日志类
 */
class Log {

    // 日志级别 从上到下，由低到高
    const EMERG = 'EMERG';  // 严重错误: 导致系统崩溃无法使用
    const ALERT = 'ALERT';  // 警戒性错误: 必须被立即修改的错误
    const CRIT = 'CRIT';  // 临界值错误: 超过临界值的错误，例如一天24小时，而输入的是25小时这样
    const ERR = 'ERR';  // 一般错误: 一般性错误
    const WARN = 'WARN';  // 警告性错误: 需要发出警告的错误
    const NOTICE = 'NOTIC';  // 通知: 程序可以运行但是还不够完美的错误
    const INFO = 'INFO';  // 信息: 程序输出信息
    const DEBUG = 'DEBUG';  // 调试: 调试信息
    const SQL = 'SQL';  // SQL：SQL语句

    public function __construct() {
        
    }

    public static function getInstance() {
        static $obj;
        if (!$obj) {
            $obj = new self();
        }
        return $obj;
    }

    /**
     * 调试: 调试信息
     * @param type $message
     */
    public function debug($message) {
        $this->write($message, self::DEBUG);
    }

    /**
     * 信息: 程序输出信息
     * @param type $message
     */
    public function info($message) {
        $this->write($message, self::INFO);
    }

    /**
     * 一般性重要的事件
     * @param type $message
     */
    public function notice($message) {
        $this->write($message, self::NOTICE);
    }

    /**
     * 出现非错误性的异常
     * @param type $message
     * @param type $array
     */
    public function warning($message) {
        $this->write($message, self::WARN);
    }

    /**
     * 运行时出现的错误，不需要立刻采取行动，但必须记录下来以备检测。
     * @param type $message
     */
    public function error($message) {
        $this->write($message, self::ERR);
    }

    /**
     * 紧急情况
     * @param type $message
     */
    public function critical($message) {
        $this->write($message, self::CRIT);
    }

    /**
     * **必须**立刻采取行动
     * @param type $message
     */
    public function alert($message) {
        $this->write($message, self::ALERT);
    }

    /**
     * 严重错误
     * @param type $message
     */
    public function emerg($message) {
        $this->write($message, self::EMERG);
    }

    /**
     * 记录 sql
     * @param type $message
     */
    public function sql($message) {
        $this->write($message, self::SQL);
    }

    /**
     * 日志直接写入
     * @param type $message  日志信息
     * @param type $level    日志级别
     * @return boolean
     */
    public function write($message, $level = self::ERR, $destination = '') {
        if (empty($message)) {
            return false;
        } else {
            $message = is_array($message) ? serialize($message) : $message;
        }

        if (empty($destination)) {
            $destination = ROOT_PATH . "cache/logs/{$level}_" . date('Y_m_d') . '.log';
        }

        // 自动创建日志目录
        $log_dir = dirname($destination);
        if (is_dir($log_dir) == false) {
            mkdir($log_dir, 0755);
        }

        if (is_file($destination) && filesize($destination) >= 20971520) {
            /* 20Mb 重命名 */
            rename($destination, $log_dir . '/' . time() . '-' . basename($destination));
        }

        $now = date('Y-m-d H:i:s');

        if (php_sapi_name() == "cli") {
            $debug = debug_backtrace();
            $content = '';
            foreach ($debug as $key => $val) {
                if (isset($val["file"])) {
                    $content .= $val["file"] . "  line  " . $val["line"] . "\r\n";
                }
            }
            $content .= "{$now} : {$message}\r\n---------------------------------------------------------------cli\r\n\r\n";
        } else {
            $uri = Request::getInstance()->get_full_url();
            $source_url = Request::getInstance()->get_url_source();
            $ip = Request::getInstance()->ip(0, true);
            $ua = Request::getInstance()->get_user_agent();
            $method = Request::getInstance()->method();
            $content = "[{$now}] {$ip} {$method} {$uri}\r\n{$source_url}\r\n{$ua}\r\n{$message}\r\n---------------------------------------------------------------\r\n\r\n\r\n";
        }

        error_log($content, 3, $destination);
    }

}
