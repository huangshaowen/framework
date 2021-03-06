<?php

namespace framework\nosql;

use framework\core\Config;
use framework\core\Exception;

/**
 *  Redis 缓存驱动
 *
 * 要求安装phpredis扩展：https://github.com/phpredis/phpredis/
 *
 * https://github.com/nrk/predis
 *
 */
class Redis {

    private $conf;
    private $group = '_cache_';
    private $prefix = 'guipin_';
    private $tag = '_cache_';     /* 缓存标签 */
    private $ver = 0;
    private $link = [];
    private $hash;

    /**
     * 是否连接server
     */
    private $isConnected = false;

    /**
     * 重连次数
     * @var int
     */
    private $reConnected = 0;

    /**
     * 最大重连次数,默认为2次
     * @var int
     */
    private $maxReConnected = 2;

    public function __construct($conf_name = 'redis_cache') {

        if (!extension_loaded('redis')) {
            throw new Exception('当前环境不支持: redis');
        }

        $this->conf = Config::getInstance()->get($conf_name);

        if (empty($this->conf)) {
            throw new Exception('请配置 redis !');
        }

        $this->connect();
    }

    /**
     * 　单实例化
     * @staticvar array $obj
     * @param type $conf_name
     * @return \self
     */
    public static function getInstance($conf_name = 'redis_cache') {
        static $obj = [];
        if (!isset($obj[$conf_name])) {
            $obj[$conf_name] = new self($conf_name);
        }
        return $obj[$conf_name];
    }

    private function connect() {

        $this->hash = new Flexihash();

        foreach ($this->conf as $k => $conf) {
            $con = new \Redis;

            $rs = $con->connect($conf['host'], $conf['port'], $conf['timeout']);

            if ($rs == true) {
                $this->isConnected = true;
                $this->link[$k] = $con;

                $this->hash->addTarget($k);
            } else {
                $this->isConnected = false;
                /* 跳过 */
                continue;
            }

            if ('' != $conf['password']) {
                $con->auth($conf['password']);
            }

            if (0 != $conf['select']) {
                $con->select($conf['select']);
            }

            if (!empty($conf['prefix'])) {
                $this->prefix = $conf['prefix'];
            }
        }

        if ($this->isConnected == false) {
            throw new Exception('redis 连接错误!');
        }
    }

    /**
     * 获取实际的缓存标识
     * @access protected
     * @param  string $name 缓存名
     * @return string
     */
    protected function getCacheKey($name) {
        return $this->prefix . $name;
    }

    private function _getConForKey($key = '') {
        $i = $this->hash->lookup($key);
        return $this->link[$i];
    }

    /**
     * 检查是否能 ping 成功
     * @param type $key
     * @return boolean
     */
    public function ping($key = '') {
        return $this->_getConForKey($key)->ping() == '+PONG';
    }

    /**
     * 检查驱动是否可用
     * @return boolean      是否可用
     */
    public function is_available() {
        if (!$this->isConnected && $this->reConnected < $this->maxReConnected) {

            $this->connect();

            if (!$this->isConnected) {
                $this->reConnected++;
            } else {
                //如果重连成功,重连次数置为0
                $this->reConnected = 0;
            }
        }
        return $this->isConnected;
    }

    /**
     * 设置value,用于序列化存储
     * @param mixed $value
     * @return mixed
     */
    public function setValue($value) {
        if (!is_numeric($value)) {
            try {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS, 512);
            } catch (Exception $exc) {
                return false;
            }
        }
        return $value;
    }

    /**
     * 获取value,解析可能序列化的值
     * @param mixed $value
     * @return mixed
     */
    public function getValue($value, $default = false) {
        if (is_null($value) || $value === false) {
            return false;
        }
        if (!is_numeric($value)) {
            try {
                $value = json_decode($value, true);
            } catch (Exception $exc) {
                return $default;
            }
        }
        return $value;
    }

    /**
     * 设置缓存分组
     * @param type $group
     * @return $this
     */
    public function group($group = '_cache_') {
        $this->group = $group;

        $key = $this->getCacheKey('cache_ver_' . $this->group);

        try {
            /* 获取版本号 */
            $this->ver = $this->_getConForKey($key)->get($key);
            if ($this->ver) {
                return $this;
            }
            /* 设置新版本号 */
            $this->ver = $this->_getConForKey($key)->incrby($key, 1);
        } catch (Exception $ex) {
            //连接状态置为false
            $this->isConnected = false;
            $this->is_available();
            /* 出错 */
            $this->ver = 0;
        }

        return $this;
    }

    /**
     * 按分组清空缓存
     * @param string $group
     * @return type
     * @return boolean
     */
    public function clear() {
        if ($this->group) {

            $key = $this->getCacheKey('cache_ver_' . $this->group);
            try {
                /* 获取新版本号 */
                $this->ver = $this->_getConForKey($key)->incrby($key, 1);

                /* 最大版本号修正 */
                if ($this->ver == PHP_INT_MAX) {
                    $this->ver = 1;
                    $this->_getConForKey($key)->set($key, 1);
                }

                return $this->ver;
            } catch (Exception $ex) {
                //连接状态置为false
                $this->isConnected = false;
                $this->is_available();
            }
        }

        return true;
    }

    /**
     * 获取有分组的缓存
     * @access public
     * @param string $cache_id 缓存变量名
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function get($cache_id, $default = false) {

        $key = $this->getCacheKey("{$this->group}_{$cache_id}");

        try {

            $value = $this->_getConForKey($key)->get($key);

            if (is_null($value) || false === $value) {
                return $default;
            }

            $data = $this->getValue($value, $default);

            if ($data && $data['ver'] == $this->ver) {
                return $data['data'];
            }
        } catch (Exception $ex) {
            //连接状态置为false
            $this->isConnected = false;
            $this->is_available();
        }
        return false;
    }

    /**
     * 设置有分组的缓存
     * @param type $cache_id    缓存 key
     * @param type $var         缓存值
     * @param type $ttl      有效期(秒)
     * @return boolean
     */
    public function set($cache_id, $var, $ttl = 0) {
        $key = $this->getCacheKey("{$this->group}_{$cache_id}");

        /* 缓存数据 */
        $data = $this->setValue(['ver' => $this->ver, 'data' => $var]);

        try {
            if ($ttl == 0) {
                // 缓存 15 ~ 18 天
                $ttl = random_int(1296000, 1555200);
                return $this->_getConForKey($key)->setex($key, $ttl, $data);
            } else {
                // 有时间限制
                return $this->_getConForKey($key)->setex($key, $ttl, $data);
            }
        } catch (Exception $ex) {
            //连接状态置为false
            $this->isConnected = false;
            $this->is_available();
        }
        return false;
    }

    /**
     * 删除有分组的缓存
     * @param type $cache_id
     * @return type
     */
    public function delete($cache_id) {
        $key = $this->getCacheKey("{$this->group}_{$cache_id}");

        try {
            return $this->_getConForKey($key)->del($key);
        } catch (Exception $ex) {
            //连接状态置为false
            $this->isConnected = false;
            $this->is_available();
        }
        return false;
    }

    /**
     * 获取字符串内指定位置的位值(BIT).
     * @param type $k           key
     * @param type $offset      位偏移
     * @return int      返回位值(0 或 1), 如果 key 不存在或者偏移超过活字符串长度范围, 返回 0.
     */
    public function getbit($k, $offset) {
        if ($this->is_available()) {
            return $this->_getConForKey($k)->getbit($k, $offset);
        }
        return 0;
    }

    /**
     * 设置字符串内指定位置的位值(BIT), 字符串的长度会自动扩展.
     * @param type $k           key
     * @param type $offset      位偏移, 取值范围 [0, 1073741824]
     * @param type $val          0 或 1
     * @return int      返回原来的位值. 如果 val 不是 0 或者 1, 返回 false.
     */
    public function setbit($k, $offset, $val) {
        if ($this->is_available()) {
            return $this->_getConForKey($k)->setbit($k, $offset, $val);
        }
        return 0;
    }

    /**
     * 计算字符串的子串所包含的位值为 1 的个数.
     * @param type $k           key
     * @return int              返回位值为 1 的个数. 出错返回 false.
     */
    public function bitcount($k) {
        if ($this->is_available()) {
            return $this->_getConForKey($k)->bitcount($k);
        }
        return 0;
    }

    /**
     * 计算字符串的长度(字节数).
     * @param type $k
     * @return int      返回字符串的长度, key 不存在则返回 0.
     */
    public function strlen($k) {
        if ($this->is_available()) {
            return $this->_getConForKey($k)->strlen($k);
        }
        return 0;
    }

    /**
     * HyperLogLog 增加一个元素到key中
     * @param   string    $key
     * @param   array     $var
     * @return  1/0
     */
    public function pfadd($key, $var = []) {
        return $this->_getConForKey($key)->pfadd($key, $var);
    }

    /**
     * HyperLogLog 统计key中不重复元素的个数
     * @param string $key
     * @return int
     */
    public function pfcount($key) {
        return $this->_getConForKey($key)->pfcount($key);
    }

    /**
     * 设置 hashmap 中指定 key 对应的值内容.
     * 参数
     *     name - hashmap 的名字.
     *     key - hashmap 中的 key.
     *     value - key 对应的值内容.
     * 返回值
     *      出错则返回 false, 其它值表示正常.
     */
    public function hset($name, $k, $v) {
        $cache_id = $this->getCacheKey($name);
        if ($this->is_available()) {
            return $this->_getConForKey($cache_id)->hSet($cache_id, $k, $v);
        }
        return false;
    }

    /**
     * 获取 hashmap 中指定 key 的值内容.
     * 参数
     *      name - hashmap 的名字.
     *      key - hashmap 中的 key.
     * 返回值
     *      如果 key 不存在则返回 null, 如果出错则返回 false, 否则返回 key 对应的值内容.
     */
    public function hget($name, $k) {
        $cache_id = $this->getCacheKey($name);

        if ($this->is_available()) {
            return $this->_getConForKey($cache_id)->hGet($cache_id, $k);
        }
        return false;
    }

    /**
     * 获取 hashmap 中的指定 key.
     * 参数
     *      name - hashmap 的名字.
     *      key - hashmap 中的 key.
     * 返回值
     *      如果出错则返回 false, 其它值表示正常. 你无法通过返回值来判断被删除的 key 是否存在.
     */
    public function hdel($name, $k) {
        $cache_id = $this->getCacheKey($name);

        if ($this->is_available()) {
            return $this->_getConForKey($cache_id)->hDel($cache_id, $k);
        }
        return false;
    }

    /**
     * 使 hashmap 中的 key 对应的值增加 num. 参数 num 可以为负数. 如果原来的值不是整数(字符串形式的整数), 它会被先转换成整数.
     * 参数
     *      name - hashmap 的名字.
     *      key -
     *      num - 必须是有符号整数.
     * 返回值
     *      如果出错则返回 false, 否则返回新的值.
     */
    public function hincr($name, $k, $v) {
        $cache_id = $this->getCacheKey($name);

        if ($this->is_available()) {
            $v = intval($v);
            return $this->_getConForKey($cache_id)->hIncrBy($cache_id, $k, $v);
        }
        return false;
    }

    /**
     * 判断指定的 key 是否存在于 hashmap 中.
     * 参数
     *      name - hashmap 的名字.
     *      key -
     * 返回值
     *      如果存在, 返回 true, 否则返回 false.
     */
    public function hexists($name, $k) {
        $cache_id = $this->getCacheKey($name);

        if ($this->is_available()) {
            return $this->_getConForKey($cache_id)->hExists($cache_id, $k);
        }
        return false;
    }

    /**
     * 返回 hashmap 中的元素个数.
     * 参数
     *      name - hashmap 的名字.
     * 返回值
     *      出错则返回 false, 否则返回元素的个数, 0 表示不存在 hashmap(空).
     */
    public function hsize($name) {
        $cache_id = $this->getCacheKey($name);

        if ($this->is_available()) {
            return $this->_getConForKey($cache_id)->hLen($cache_id);
        }
        return false;
    }

    /**
     * 返回整个 hashmap.
     * 参数
     *      name - hashmap 的名字.
     * 返回值
     *      如果出错则返回 false, 否则返回包含 key-value 的关联数组.
     */
    public function hgetall($name) {
        $cache_id = $this->getCacheKey($name);

        if ($this->is_available()) {
            return $this->_getConForKey($cache_id)->hGetAll($cache_id);
        }
        return false;
    }

    /**
     * 批量设置 hashmap 中的 key-value.
     * 参数
     *        name - hashmap 的名字.
     *        kvs - 包含 key-value 的关联数组 .
     * 返回值
     *        出错则返回 false, 其它值表示正常.
     */
    public function hsetall($name, $data) {

        $cache_id = $this->getCacheKey($name);

        if ($this->is_available()) {
            return $this->_getConForKey($cache_id)->hMSet($cache_id, $data);
        }
        return false;
    }

    /**
     * 删除 hashmap 中的所有 key.
     * 参数
     *      name - hashmap 的名字.
     * 返回值
     *      如果出错则返回 false, 否则返回删除的 key 的数量.
     */
    public function hclear($name) {
        $cache_id = $this->getCacheKey($name);

        if ($this->is_available()) {
            return $this->_getConForKey($cache_id)->del($cache_id);
        }
        return false;
    }

    /**
     * 获得锁,如果锁被占用,阻塞,直到获得锁或者超时。
     * -- 1、如果 $timeout 参数为 0,则立即返回锁。
     * -- 2、建议 $timeout 设置为 0,避免 redis 因为阻塞导致性能下降。请根据实际需求进行设置。
     * @param string $cache_id      锁KEY
     * @param int $lock_second      锁定时间(秒)
     * @param int $timeout          取锁超时时间(秒)。等于0,如果当前锁被占用,则立即返回失败。如果大于0,则反复尝试获取锁直到达到该超时时间。
     * @param int $sleep            取锁间隔时间。单位(微秒)。当锁为占用状态时。每隔多久尝试去取锁。默认 0.1 秒一次取锁。
     * @return boolean              值或false
     */
    public function lock(string $cache_id, int $lock_second = 5, int $timeout = 0, int $sleep = 100000) {
        $key = "{$this->prefix}lock:{$cache_id}";

        if (!is_int($timeout) || $timeout < 0) {
            $timeout = 0;
        }

        if ($lock_second <= 0) {
            $lock_second = 1;
        }

        $start = $this->get_micro_time();

        $lock_value = time();

        do {
            $rs = $this->_getConForKey($key)->set($key, $lock_value, ['nx', 'ex' => $lock_second]);
            if ($rs) {
                break;
            }
            if ($timeout === 0) {
                break;
            }
            usleep($sleep);
        } while (($this->get_micro_time()) < ($start + ($timeout * 1000000)));
        return $rs ? $lock_value : false;
    }

    /**
     * 对指定键名移除锁标记
     * @param string    $cache_id       锁名称
     * @param int       $lock_value     值
     * @return boolean
     */
    public function unlock($cache_id, $lock_value) {
        $key = "{$this->prefix}lock:{$cache_id}";

        $rs = $this->_getConForKey($key)->get($key);
        if ($rs && ($rs == $lock_value)) {
            return $this->_getConForKey($key)->del($key);
        }

        return false;
    }

    /**
     * 获取当前微秒。
     *
     * @return bigint
     */
    public function get_micro_time(): int {
        return bcmul(microtime(true), 1000000);
    }

    /**
     * 简单设置缓存
     * @param string    $cache_id       缓存key
     * @param mix       $var            缓存值
     * @param int       $ttl            有效期(秒)
     * @return bool
     */
    public function simple_set(string $cache_id, $var, int $ttl = 0): bool {
        $key = $this->getCacheKey($cache_id);
        $var = $this->setValue($var);

        if ($this->is_available()) {
            if ($ttl == 0) {
                return $this->_getConForKey($key)->set($key, $var);
            } else {
                // 有时间限制
                return $this->_getConForKey($key)->setex($key, $ttl, $var);
            }
        }
        return false;
    }

    /**
     * 简单设置缓存，不存在时设置成功
     * @param string    $cache_id       缓存key
     * @param mix       $var            缓存值
     * @param int       $ttl            有效期(5秒)
     * @return bool
     */
    public function simple_setnx(string $cache_id, $var, int $ttl = 5) {
        $key = $this->getCacheKey($cache_id);
        $var = $this->setValue($var);

        if ($this->is_available()) {
            return $this->_getConForKey($key)->set($key, $var, ['nx', 'ex' => $ttl]);
        }
        
        return false;
    }

    /**
     * 简单获取缓存
     * @param string    $cache_id           缓存名称
     * @param bool      $default            默认返回　false
     * @return boolean|int|array
     */
    public function simple_get(string $cache_id, $default = false) {
        $key = $this->getCacheKey($cache_id);
        if ($this->is_available()) {
            $value = $this->_getConForKey($key)->get($key);
            if (is_null($value) || false === $value) {
                return $default;
            }
            return $this->getValue($value, $default);
        }
        return false;
    }

    /**
     * 简单删除缓存(同步)
     * @param type $cache_id
     * @return boolean/int
     */
    public function simple_delete(string $cache_id) {
        $key = $this->getCacheKey($cache_id);
        if ($this->is_available()) {
            return $this->_getConForKey($key)->del($key);
        }
        return false;
    }

    /**
     * 自增缓存（针对数值缓存）
     * @param  string    $cache_id 缓存变量名
     * @param  int       $step 步长
     * @return false|int
     */
    public function simple_inc(string $cache_id, int $step = 1) {
        $key = $this->getCacheKey($cache_id);
        return $this->_getConForKey($key)->incrby($key, $step);
    }

    /**
     * 自减缓存（针对数值缓存）
     * @param  string    $cache_id 缓存变量名
     * @param  int       $step 步长
     * @return false|int
     */
    public function simple_dec(string $cache_id, int $step = 1) {
        $key = $this->getCacheKey($cache_id);
        return $this->_getConForKey($key)->decrby($key, $step);
    }

    /**
     * 简单删除缓存（异步）
     * @param string $cache_id
     * @return boolean/int
     */
    public function simple_unlink(string $cache_id) {
        $key = $this->getCacheKey($cache_id);
        if ($this->is_available()) {
            return $this->_getConForKey($key)->unlink($key);
        }
        return false;
    }

    /**
     * 设置 key(只针对 KV 类型) 的存活时间
     * @param string $cache_id
     * @param int $ttl
     * @return bool
     */
    public function expire(string $cache_id, int $ttl): bool {
        $key = $this->getCacheKey($cache_id);

        if ($this->is_available()) {
            return $this->_getConForKey($key)->expire($key, $ttl);
        }
        return false;
    }

    /**
     * 返回 key(只针对 KV 类型) 的存活时间
     * @param string $cache_id
     * @return int
     */
    public function ttl(string $cache_id): int {
        $key = $this->getCacheKey($cache_id);

        if ($this->is_available()) {
            return $this->_getConForKey($key)->ttl($key);
        }
        return 0;
    }

    /**
     * 获取毫秒时间戳
     * @return int
     */
    function get_js_timestamp(): int {
        list($t1, $t2) = explode(' ', microtime());
        return (floatval($t1) + floatval($t2)) * 1000;
    }

    /**
     *  操作次数限制函数,采用滑动窗口: 限制 uid 在 period 秒内能操作 action 最多 max_count 次.
     *  如果超过限制, 返回 false.
     * @param type $uid
     * @param type $action
     * @param type $max_count
     * @param type $period
     * @return boolean
     */
    public function act_limit($uid, $action, $max_count, $period) {
        $now = time();
        $expire = intval($now / $period) * $period + $period;
        $ttl = $expire - $now;
        $end_time = $now - $ttl;

        //将时间戳写入有序集合里面
        $zname = "act_limit:{$uid}:{$action}:nums";
        $key = $this->get_js_timestamp();

        $pipe = $this->_getConForKey($zname)->multi(\Redis::PIPELINE); //使用管道提升性能
        $pipe->zAdd($zname, $key, $now);
        /* 删除过期的数据 */
        $pipe->zremrangebyscore($zname, 0, $end_time); //移除时间窗口之前的行为记录，剩下的都是时间窗口内的
        $pipe->zcard($zname);  //获取窗口内的行为数量
        $pipe->expire($zname, $ttl + 1);  //多加一秒过期时间
        $replies = $pipe->exec();

        return $replies[2] <= $max_count;
    }

    /**
     *  操作次数限制函数,采用计数次: 限制 uid 在 period 秒内能操作 action 最多 max_count 次.
     *  如果超过限制, 返回 false.
     * @param type $uid
     * @param type $action
     * @param type $max_count
     * @param type $period
     * @return boolean
     */
    public function act_count_limit($uid, $action, $max_count, $period) {
        $now = time();
        $expire = intval($now / $period) * $period + $period;
        $ttl = $expire - $now;
        $key = 'act_limit:' . md5("{$uid}|{$action}");
        $count = $this->_getConForKey($key)->incrby($key, 1);
        $this->_getConForKey($key)->expire($key, $ttl);
        if ($count === false || $count > $max_count) {
            return false;
        }
        return true;
    }

    /**
     * 列出处于区间 (key_start, key_end] 的 key-value 列表.
     * ("", ""] 表示整个区间.
     * 参数
     *      key_start - 返回的起始 key(不包含), 空字符串表示 -inf.
     *      key_end - 返回的结束 key(包含), 空字符串表示 +inf.
     *      limit - 最多返回这么多个元素.
     * 返回值
     *      如果出错则返回 false, 否则返回包含 key-value 的数关联组.
     */
    public function scan($key_start, $key_end, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($key_start)->scan($key_start, $key_end, $limit);
        }
        return false;
    }

    /**
     * 将一个或多个值 value 插入到列表 key 的表尾(最右边)。
     * @param type $name
     * @param type $data
     * @return type
     */
    public function rPush($name = 'queue_task', $data = []) {
        $data = $this->setValue($data);
        return $this->_getConForKey($name)->rPush($name, $data);
    }

    /**
     * 将一个值 value 插入到列表头部
     * @param type $name
     * @param type $data
     * @return type
     */
    public function lPush($name = 'queue_task', $data = []) {
        $data = $this->setValue($data);
        return $this->_getConForKey($name)->lPush($name, $data);
    }

    /**
     * 命令用于移除并返回列表的第一个元素
     * @param type $name
     * @return boolean
     */
    public function lPop($name = 'queue_task') {
        $value = $this->_getConForKey($name)->lPop($name);
        if (is_null($value) || false === $value) {
            return false;
        }
        return $this->getValue($value, false);
    }

    /**
     * 移除并返回列表 key 的尾元素。
     * @param type $name
     * @return boolean
     */
    public function rPop($name = 'queue_task') {
        $value = $this->_getConForKey($name)->rPop($name);
        if (is_null($value) || false === $value) {
            return false;
        }
        return $this->getValue($value, false);
    }

    /**
     * 查看列表中的数据
     * @param type $name
     * @param type $start
     * @param type $end
     * @return boolean
     */
    public function lRange($name = 'queue_task', $start = 0, $end = -1) {
        $rows = $this->_getConForKey($name)->lRange($name, $start, $end);
        if (empty($rows)) {
            return false;
        }

        $list = [];
        foreach ($rows as $key => $value) {
            if (is_null($value) || false === $value) {
                continue;
            }
            $list[] = $this->getValue($value, false);
        }
        if (empty($list)) {
            return false;
        }

        return $list;
    }

    /**
     * 返回列表 key 的长度
     * @param string $name
     * @return boolean/int
     */
    public function lLen($name = 'queue_task') {
        $rs = $this->_getConForKey($name)->lLen($name);
        if ($rs) {
            return $rs;
        }
        return 0;
    }

    /**
     * 设置 zset 中指定 key 对应的权重值.
     * 参数
     *     name - zset 的名字.
     *     key - zset 中的 key.
     *     score - 整数, key 对应的权重值
     * 返回值
     *      出错则返回 false, 其它值表示正常.
     */
    public function zset($name, $k, $v) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->zAdd($name, $v, $k);
        }
        return false;
    }

    /**
     * 获取  中指定 key 的权重值.
     * 参数
     *       name - zset 的名字.
     *       key - zset 中的 key.
     * 返回值
     *       如果 key 不存在则返回 null, 如果出错则返回 false, 否则返回 key 对应的权重值.
     */
    public function zget($name, $k) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->zScore($name, $k);
        }
        return false;
    }

    /**
     * 获取 zset 中的指定 key.
     * 参数
     *        name - zset 的名字.
     *        key - zset 中的 key.
     * 返回值
     *        如果出错则返回 false, 其它值表示正常. 你无法通过返回值来判断被删除的 key 是否存在.
     */
    public function zdel($name, $k) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->zRem($name, $k);
        }
        return false;
    }

    /**
     * 使 zset 中的 key 对应的值增加 num. 参数 num 可以为负数
     * 参数
     *      name - zset 的名字.
     *      key -
     *      num - 必须是有符号整数.
     * 返回值
     *      如果出错则返回 false, 否则返回新的值.
     */
    public function zincr($name, $k, $v) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->zIncrBy($name, $v, $k);
        }
        return false;
    }

    /**
     * 判断指定的 key 是否存在于 zset 中.
     * 参数
     *      name - zset 的名字.
     *      key -
     * 返回值
     *      如果存在, 返回 true, 否则返回 false.
     */
    public function zexists($name, $k) {
        if ($this->is_available()) {
            $rs = $this->_getConForKey($name)->zScore($name, $k);
            if ($rs == false) {
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * 返回 zset 中的元素个数.
     * 参数
     *       name - zset 的名字.
     * 返回值
     *       出错则返回 false, 否则返回元素的个数, 0 表示不存在 zset(空).
     */
    public function zsize($name) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->zCard($name);
        }
        return false;
    }

    /**
     * zrank
     * 返回有序集 key 中成员 member 的排名。其中有序集成员按 score 值递增(从小到大)顺序排列。
     * 排名以 0 为底，也就是说， score 值最小的成员排名为 0
     * 参数
     *      name - zset 的名字.
     *      key -
     * 返回值
     * found.
     *     如果 key 是有序集     name 的成员，返回 key 的排名。
     *     如果 key 不是有序集   name 的成员，返回 nil 。
     */
    public function zrank($name, $k) {
        return $this->_getConForKey($name)->zRank($name, $k);
    }

    public function zrrank($name, $k) {
        return $this->_getConForKey($name)->zRevRank($name, $k);
    }

    /**
     * zrange, zrrange
     * 注意! 本方法在 offset 越来越大时, 会越慢!
     * 根据下标索引区间 [offset, offset + limit) 获取 key-score 对, 下标从 0 开始. zrrange 是反向顺序获取.
     * 参数
     *      name - zset 的名字.
     *      offset - 正整数, 从此下标处开始返回. 从 0 开始.
     *      limit - 正整数, 最多返回这么多个 key-score 对.
     * 返回值
     *      如果出错则返回 false, 否则返回包含 key-score 的关联数组.
     */
    public function zrange($name, $offset, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->zRange($name, $offset, $limit, true);
        }
        return false;
    }

    public function zrrange($name, $offset, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->zRevRange($name, $offset, $limit, true);
        }
        return false;
    }

    /**
     * 删除权重处于区间 [start,end] 的元素.
     * 参数
     *      name - zset 的名字.
     *      start - (包含).
     *      end -(包含).
     * 返回值
     *      出错则返回 false, 否则返回被删除的元素个数.
     */
    public function zremrangebyscore($name, $score_start, $score_end) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->zRemRangeByScore($name, $score_start, $score_end);
        }
        return false;
    }

    /**
     * 删除 zset 中的所有 key.
     * 参数
     *      name - zset 的名字.
     * 返回值
     *      如果出错则返回 false, 否则返回删除的 key 的数量.
     */
    public function zclear($name) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->del($name);
        }
        return false;
    }

    /**
     *
     * 返回处于区间 [start,end] key 数量.
     * 参数
     *       name - zset 的名字.
     *       score_start - key 的最小权重值(包含), 空字符串表示 -inf.
     *       score_end - key 的最大权重值(包含), 空字符串表示 +inf.
     * 返回值
     *       如果出错则返回 false, 否则返回符合条件的 key 的数量.
     */
    public function zcount($name, $score_start, $score_end) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->zCount($name, $score_start, $score_end);
        }
        return false;
    }

    /**
     * Redis 集合命令
     */

    /**
     * Redis Sadd 命令将一个或多个成员元素加入到集合中，已经存在于集合的成员元素将被忽略。
     * @param type $name
     * @param type $v
     * @return boolean
     */
    public function sadd(string $name, $v) {
        $cache_id = $this->getCacheKey($name);
        return $this->_getConForKey($cache_id)->sAdd($cache_id, $v);
    }

    /**
     * 获取集合的成员数
     * @param type $name
     * @return boolean
     */
    public function ssize(string $name) {
        $cache_id = $this->getCacheKey($name);

        return $this->_getConForKey($cache_id)->sCard($cache_id);
    }

    /**
     * 集合之间的差集,如果两个集合不在同一台服务器，返回结果会出错
     * @param string $name1
     * @param string $name2
     */
    public function sdiff(string $name1, string $name2) {
        $cache_id1 = $this->getCacheKey($name1);
        $cache_id2 = $this->getCacheKey($name2);

        return $this->_getConForKey($cache_id1)->sDiff($cache_id1, $cache_id2);
    }

    /**
     * 集合之间的交集,如果两个集合不在同一台服务器，返回结果会出错
     * @param string $name1
     * @param string $name2
     */
    public function sinter(string $name1, string $name2) {
        $cache_id1 = $this->getCacheKey($name1);
        $cache_id2 = $this->getCacheKey($name2);

        return $this->_getConForKey($cache_id1)->sInter($cache_id1, $cache_id2);
    }

    /**
     * 集合之间的并集,如果两个集合不在同一台服务器，返回结果会出错
     * @param string $name1
     * @param string $name2
     */
    public function sunion(string $name1, string $name2) {
        $cache_id1 = $this->getCacheKey($name1);
        $cache_id2 = $this->getCacheKey($name2);

        return $this->_getConForKey($cache_id1)->sUnion($cache_id1, $cache_id2);
    }

    /**
     * 返回集合中的所有成员
     * @param string $name
     * @return boolean
     */
    public function smembers(string $name) {
        $cache_id = $this->getCacheKey($name);
        return $this->_getConForKey($cache_id)->sMembers($cache_id);
    }

    /**
     * 移除集合中指定 key
     * @param type $name
     * @param type $v
     * @return boolean
     */
    public function sdel($name, $v) {
        $cache_id = $this->getCacheKey($name);
        return $this->_getConForKey($cache_id)->sRem($cache_id, $v);
    }

    public function batch($name) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->multi();
        }
        return false;
    }

    public function exec($name) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->exec();
        }
        return false;
    }

    /**
     * 最好能保证它能最后析构!
     * 关闭连接
     */
    public function __destruct() {
        if (!empty($this->link)) {
            foreach ($this->link as $key => $value) {
                $this->link[$key]->close();
            }
        }
        unset($this->link);
        unset($this->isConnected);
    }

}
