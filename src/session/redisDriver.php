<?php

namespace framework\session;

use framework\nosql\Redis;

/**
 * session redis 驱动类
 */
class redisDriver extends \SessionHandler {

    private $ttl = 7200;       /* 2小时 */

    public function open($savePath, $sessionName) {
        return true;
    }

    public function close() {
        return true;
    }

    public function read($session_id) {
        if (empty($session_id)) {
            return '';
        }
        return (string) Redis::getInstance()->simple_get($session_id);
    }

    public function write($session_id, $session_data) {
        if (empty($session_id)) {
            return false;
        }
        Redis::getInstance()->simple_set($session_id, $session_data, $this->ttl);
        return true;
    }

    public function destroy($session_id) {
        if (empty($session_id)) {
            return false;
        }
        Redis::getInstance()->simple_delete($session_id);
        return true;
    }

    public function gc($maxlifetime) {
        return true;
    }

}
