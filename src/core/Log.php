<?php

namespace framework\core;

/**
 * 日志类
 */
class Log {

    public $monolog;
    public $extra;

    /**
     * 日志默认保存路径
     * @var string
     */
    private $fileName = '/dev/null';

    /**
     * 日志留存时间
     * @var int
     */
    private $maxFiles = 31;

    /**
     * 日志等级
     * @var int
     */
    private $level = \Monolog\Logger::DEBUG;

    /**
     * 文件读写权限分配
     * @var int
     */
    private $filePermission = 0777;

    public function __construct() {
        $this->monolog = new \Monolog\Logger('log');

        $this->fileName = ROOT_PATH . "cache/logs/log.txt";

        // 日志文件相关操作
        $handler = new \Monolog\Handler\RotatingFileHandler($this->fileName, $this->maxFiles, $this->level, true, $this->filePermission);


        $this->monolog->pushHandler($handler);

        $this->extra = [
            'url' => Request::getInstance()->get_full_url(),
            'source_url' => Request::getInstance()->get_url_source(),
            'ip' => Request::getInstance()->ip(0, true),
            'ua' => Request::getInstance()->get_user_agent(),
            'method' => Request::getInstance()->method(),
        ];
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
        $this->monolog->debug($message, $this->extra);
    }

    /**
     * 信息: 程序输出信息
     * @param type $message
     */
    public function info($message) {
        $this->monolog->info($message, $this->extra);
    }

    /**
     * 一般性重要的事件
     * @param type $message
     */
    public function notice($message) {
        $this->monolog->notice($message, $this->extra);
    }

    /**
     * 出现非错误性的异常
     * @param type $message
     * @param type $array
     */
    public function warning($message) {
        $this->monolog->warning($message, $this->extra);
    }

    /**
     * 运行时出现的错误，不需要立刻采取行动，但必须记录下来以备检测。
     * @param type $message
     */
    public function error($message) {
        $this->monolog->error($message, $this->extra);
    }

    /**
     * 紧急情况
     * @param type $message
     */
    public function critical($message) {
        $this->monolog->critical($message, $this->extra);
    }

    /**
     * **必须**立刻采取行动
     * @param type $message
     */
    public function alert($message) {
        $this->monolog->alert($message, $this->extra);
    }

    /**
     * 严重错误
     * @param type $message
     */
    public function emerg($message) {
        $this->monolog->emerg($message, $this->extra);
    }

    /**
     * 记录 sql
     * @param type $message
     */
    public function sql($message) {
        $this->monolog->debug($message, $this->extra);
    }

}
