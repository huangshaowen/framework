<?php

namespace framework\nosql;

use Psr\SimpleCache\CacheInterface;
use Traversable;

/**
 * 基于 PSR-16 的缓存类
 * https://www.php-fig.org/psr/psr-16/
 */
class SimpleCache implements CacheInterface {

    private $redis;

    public function __construct() {
        $this->redis = Redis::getInstance();
    }

    public function get($key, $default = null) {
        if (!is_string($key)) {
            throw new \Exception('key is not a legal value');
        }
        return $this->redis->simple_get($key, $default);
    }

    public function set($key, $value, $ttl = null): bool {
        if (!is_string($key)) {
            throw new \Exception('key is not a legal value');
        }
        if (is_int($ttl)) {
            $ttl = $ttl;
        } elseif ($ttl instanceof \DateInterval) {
            $ttl = (new \DateTime('now'))->add($ttl)->getTimeStamp() - time();
        } elseif ($ttl === null) {
            $ttl = 0;
        } else {
            throw new \Exception('Invalid expires after parameter');
        }

        return $this->redis->simple_set($key, $value, $ttl);
    }

    public function delete($key): bool {
        if (!is_string($key)) {
            throw new \Exception('key is not a legal value');
        }
        return (bool) $this->redis->simple_delete($key);
    }

    public function clear(): bool {
        return true;
    }

    public function getMultiple($keys, $default = null): array {
        if (!is_array($keys) && !($keys instanceof Traversable)) {
            throw new \Exception('keys is not a array');
        }
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->redis->simple_get($key, $default);
        }
        return $result;
    }

    public function setMultiple($values, $ttl = null): bool {
        if (!is_array($values) && !($values instanceof Traversable)) {
            throw new \Exception('values is not a array');
        }
        if (is_int($ttl)) {
            $ttl = $ttl;
        } elseif ($ttl instanceof \DateInterval) {
            $ttl = (new \DateTime('now'))->add($ttl)->getTimeStamp() - time();
        } elseif ($ttl === null) {
            $ttl = 0;
        } else {
            throw new \Exception('Invalid expires after parameter');
        }
        foreach ($values as $k => $v) {
            $this->redis->simple_set($k, $v, $ttl);
        }
        return true;
    }

    public function deleteMultiple($keys): bool {
        if (!is_array($keys) && !($keys instanceof Traversable)) {
            throw new \Exception('keys is not a array');
        }
        foreach ((array) $keys as $key) {
            $this->redis->simple_delete($key);
        }
        return true;
    }

    public function has($key): bool {
        if (!is_string($key)) {
            throw new \Exception('key is not a legal value');
        }
        $rs = $this->redis->simple_get($key, false);
        if ($rs) {
            return true;
        }
        return false;
    }

}
