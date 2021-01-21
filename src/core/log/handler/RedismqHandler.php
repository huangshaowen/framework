<?php

namespace framework\core\log\handler;

/**
 * 日志存储处理
 */
class RedismqHandler implements Handler {

    public function write(array $messages = []) {

        $queue_data = [];

        foreach ($messages as $level => $val) {
            foreach ($val as $key => $value) {
                $queue_data[] = [
                    'level' => $level,
                    'message' => $value,
                    'ctime' => date('Y-m-d H:i:s'),
                ];
            }
        }

        \framework\queue\RedisQueue::getInstance()->batch_qpush('web_error_log_queue', $queue_data);
    }

}
