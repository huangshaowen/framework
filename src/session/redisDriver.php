<?php

namespace framework\session;

/**
 * session redis 驱动类
 */
class redisDriver extends \SessionHandler {

    private $ttl = 7200;       /* 2小时 */

    public function read($session_id) {
        return (string) \framework\nosql\Redis::getInstance('redis_session')->simple_get($session_id);
    }

    public function write($session_id, $session_data) {
        \framework\nosql\Redis::getInstance('redis_session')->simple_set($session_id, $session_data, $this->ttl);
        return true;
    }

    public function destroy($session_id) {
        \framework\nosql\Redis::getInstance('redis_session')->simple_delete($session_id);
        return true;
    }

}
