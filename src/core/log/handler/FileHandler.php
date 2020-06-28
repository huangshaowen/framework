<?php

namespace framework\core\log\handler;

/**
 * 日志存储处理
 */
class FileHandler implements Handler {

    private $log_path = ROOT_PATH . "cache/logs/";

    public function __construct() {
        /* 自动创建日志目录 */
        $log_dir = dirname($this->log_path);
        if (is_dir($log_dir) == false) {
            mkdir($log_dir, 0777);
        }
    }

    public function write(array $messages = []) {
        foreach ($messages as $level => $val) {
            $contents = implode("\r\n\r\n", $val) . "\r\n\r\n";
            $destination = $this->log_path . "{$level}_" . date('Y_m_d') . '.log';
            file_put_contents($destination, $contents, FILE_APPEND);
        }
    }

}
