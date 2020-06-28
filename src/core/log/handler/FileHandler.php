<?php

namespace framework\core\log\handler;

class FileHandler implements Handler {

    public function write(array $messages = []) {
        if (empty($messages)) {
            return false;
        }

        foreach ($messages as $level => $val) {
           
            $contents = implode("\r\n", $val) . "\r\n";

            $destination = ROOT_PATH . "cache/logs/{$level}_" . date('Y_m_d') . '.log';
            // 自动创建日志目录
            $log_dir = dirname($destination);
            if (is_dir($log_dir) == false) {
                mkdir($log_dir, 0755);
            }
            file_put_contents($destination, $contents, FILE_APPEND);
        }

        return true;
    }

}
