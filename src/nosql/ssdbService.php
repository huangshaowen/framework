<?php

namespace framework\nosql;

use framework\core\Exception;
use framework\core\Config;

/**
 * SSDB 分布式中间层
 * 只列出常用方法
 * @author shaowen
 */
class ssdbService {

    private $conf;
    private $link = [];
    private $hash;

    /**
     * 是否连接server
     * @var boolean
     */
    private $isConnected = false;

    /**
     * 重连次数
     * @var int
     */
    private $reConnected = 0;

    /**
     * 最大重连次数,默认为3次
     * @var int
     */
    private $maxReConnected = 3;

    public function __construct($conf_name = 'ssdb_cache') {
        $this->conf = Config::getInstance()->get($conf_name);

        $this->connect();
    }

    /**
     * 连接ssdb
     */
    private function connect() {

        $this->hash = new Flexihash();

        foreach ($this->conf as $k => $conf) {

            try {
                $con = new SimpleSSDB($conf['host'], $conf['port'], 2000);
                $this->isConnected = true;
            } catch (Exception $e) {
                $this->exception($ex);
                $this->isConnected = false;
                continue;
            }

            if (!($con == false)) {
                /* ssdb 需要验证 */
                if (!empty($conf['password'])) {
                    $con->auth($conf['password']);
                }
                $this->link[$k] = $con;
                $this->hash->addTarget($k);
            }
        }
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
            }
            //如果重连成功,重连次数置为0
            else {
                $this->reConnected = 0;
            }
        }
        return $this->isConnected;
    }

    private function _getConForKey($key) {
        $i = $this->hash->lookup($key);
        return $this->link[$i];
    }

    /**
     * 处理异常信息
     * @param Exception $ex
     */
    public function exception($ex) {
        throw new Exception($ex);
    }

    /**
     * 获取毫秒时间戳
     * @return int
     */
    function get_js_timestamp() {
        list($t1, $t2) = explode(' ', microtime());
        return intval((floatval($t1) + floatval($t2)) * 1000);
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
    public function act_limit($uid, $action, $max_count, $period) {
        $now = time();
        $expire = intval($now / $period) * $period + $period;
        $ttl = $expire - $now;
        $key = 'act_limit:' . md5("{$uid}|{$action}");
        $count = $this->_getConForKey($key)->incr($key);
        $this->_getConForKey($key)->expire($key, $ttl);
        if ($count === false || $count > $max_count) {
            return false;
        }
        return true;
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
        $key = "lock:{$cache_id}";

        if (!is_int($timeout) || $timeout < 0) {
            $timeout = 0;
        }

        if ($lock_second <= 0) {
            $lock_second = 1;
        }

        $start = $this->getMicroTime();

        $lock_value = time();

        do {
            $rs = $this->_getConForKey($key)->setx($key, $lock_value, $lock_second);
            if ($rs) {
                break;
            }
            if ($timeout === 0) {
                break;
            }
            usleep($sleep);
        } while (($this->getMicroTime()) < ($start + ($timeout * 1000000)));
        return $rs ? $lock_value : false;
    }

    /**
     * 对指定键名移除锁标记
     * @param string    $cache_id       锁名称
     * @param int       $lock_value     值
     * @return boolean
     */
    public function unlock($cache_id, $lock_value) {
        $key = "lock:{$cache_id}";

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
    private function getMicroTime() {
        return bcmul(microtime(true), 1000000);
    }

    /**
     * 设置指定 key 的值内容.
     * 参数
     *      key
     *      value
     * 返回值
     *      出错则返回 false, 其它值表示正常.
     */
    public function set($k, $v) {
        if ($this->is_available()) {
            /* 编码 */
            $v = json_encode($v);

            return $this->_getConForKey($k)->set($k, $v);
        }
        return false;
    }

    /**
     * 设置指定 key 的值内容, 同时设置存活时间.
     * @param type $k
     * @param type $v
     * @param type $ttl
     * @return 出错则返回 false, 其它值表示正常.
     */
    public function setx($k, $v, $ttl) {
        if ($this->is_available()) {
            /* 编码 */
            $v = json_encode($v);

            if (empty($ttl)) {
                return $this->_getConForKey($k)->set($k, $v);
            } else {
                return $this->_getConForKey($k)->setx($k, $v, $ttl);
            }
        }
        return false;
    }

    /**
     * 当 key 不存在时, 设置指定 key 的值内容. 如果已存在, 则不设置.
     * @param type $k
     * @param type $v
     * @return  出错则返回 false, 1: value 已经设置, 0: key 已经存在, 不更新.
     */
    public function setnx($k, $v) {
        if ($this->is_available()) {
            /* 编码 */
            $v = json_encode($v);

            return $this->_getConForKey($k)->setnx($k, $v);
        }
        return false;
    }

    /**
     * 设置 key(只针对 KV 类型) 的存活时间.
     * @param type $k
     * @param type $ttl
     * @return type
     */
    public function expire($k, $ttl) {
        if ($this->is_available()) {
            return $this->_getConForKey($k)->expire($k, $ttl);
        }
        return false;
    }

    /**
     * 返回 key(只针对 KV 类型) 的存活时间.
     * @param type $k
     * @return type
     */
    public function ttl($k) {
        if ($this->is_available()) {
            return $this->_getConForKey($k)->ttl($k);
        }
        return false;
    }

    /**
     * 获取指定 key 的值内容.
     * 参数
     *      key
     * 返回值
     *      如果 key 不存在则返回 null, 如果出错则返回 false, 否则返回 key 对应的值内容.
     */
    public function get($k) {
        if ($this->is_available()) {
            $v = $this->_getConForKey($k)->get($k);
            if (empty($v)) {
                return false;
            }
            /* 自动解码 */
            return json_decode($v, true);
        }
        return false;
    }

    /**
     * 更新 key 对应的 value, 并返回更新前的旧的 value.
     * 参数
     *      key
     *      value
     * 返回值
     *      如果 key 不存在则返回 null, 如果出错则返回 false, 否则返回 key 对应的值内容.
     */
    public function getset($k, $v) {
        if ($this->is_available()) {
            return $this->_getConForKey($k)->getset($k, $v);
        }
        return false;
    }

    /**
     * 删除指定的 key.
     * 参数
     *      key
     * 返回值
     *      如果出错则返回 false, 其它值表示正常. 你无法通过返回值来判断被删除的 key 是否存在.
     */
    public function del($k) {
        if ($this->is_available()) {
            return $this->_getConForKey($k)->del($k);
        }
        return false;
    }

    /**
     * 简单设置缓存
     * @param type $k    缓存 key
     * @param type $v         缓存值
     * @param type $ttl      有效期(秒)
     * @return
     */
    public function simple_set($k, $v, $ttl = 0) {
        if ($this->is_available()) {
            if (empty($ttl)) {
                return $this->_getConForKey($k)->set($k, $v);
            } else {
                return $this->_getConForKey($k)->setx($k, $v, $ttl);
            }
        }
        return false;
    }

    /**
     * 简单获取缓存
     * @param   string  $k    缓存名称
     * @return  boolean/value
     */
    public function simple_get($k) {
        if ($this->is_available()) {
            $v = $this->_getConForKey($k)->get($k);
            if (empty($v)) {
                return false;
            }
            return $v;
        }
        return false;
    }

    /**
     * 简单删除缓存
     * @param   string  $k      缓存名称
     * @return  boolean
     */
    public function simple_delete($k) {
        if ($this->is_available()) {
            return $this->_getConForKey($k)->del($k);
        }
        return false;
    }

    /**
     * 使 key 对应的值增加 num. 参数 num 可以为负数. 如果原来的值不是整数(字符串形式的整数), 它会被先转换成整数.
     * 参数
     *      key
     *      num - 必须是有符号整数.
     * 返回值
     *      如果出错则返回 false, 否则返回新的值.
     */
    public function incr($k, $v = 1) {
        if ($this->is_available()) {
            $v = intval($v);
            return $this->_getConForKey($k)->incr($k, $v);
        }
        return false;
    }

    /**
     * 判断指定的 key 是否存在.
     * 参数
     *      key
     * 返回值
     *      如果存在, 返回 true, 否则返回 false.
     */
    public function exists($k) {
        if ($this->is_available()) {
            return $this->_getConForKey($k)->exists($k);
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
     * 计算字符串的子串所包含的位值为 1 的个数. 若 start 是负数, 则从字符串末尾算起. 若 end 是负数, 则表示从字符串末尾算起(包含). 类似 Redis 的 bitcount
     * @param type $k           key
     * @param type $start       可选, 子串的字节偏移
     * @param type $end         可选
     * @return int              返回位值为 1 的个数. 出错返回 false.
     */
    public function bitcount($k, $start = 0, $end = -1) {
        if ($this->is_available()) {
            return $this->_getConForKey($k)->bitcount($k, $start, $end);
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
     * 列出处于区间 (key_start, key_end] 的 key 列表.
     * ("", ""] 表示整个区间.
     * 参数
     *      key_start - 返回的起始 key(不包含), 空字符串表示 -inf.
     *      key_end - 返回的结束 key(包含), 空字符串表示 +inf.
     *      limit - 最多返回这么多个元素.
     * 返回值
     *      如果出错则返回 false, 否则返回包含 key 的数组.
     */
    public function keys($key_start, $key_end, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($key_start)->keys($key_start, $key_end, $limit);
        }
        return false;
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
     * 列出处于区间 (key_start, key_end] 的 key-value 列表, 反向顺序.
     * ("", ""] 表示整个区间.
     * 参数
     *      key_start - 返回的起始 key(不包含), 空字符串表示 -inf.
     *      key_end - 返回的结束 key(包含), 空字符串表示 +inf.
     *      limit - 最多返回这么多个元素.
     * 返回值
     *      如果出错则返回 false, 否则返回包含 key-value 的数关联组.
     */
    public function rscan($key_start, $key_end, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($key_start)->rscan($key_start, $key_end, $limit);
        }
        return false;
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
        if ($this->is_available()) {
            return $this->_getConForKey($name)->hset($name, $k, $v);
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
        if ($this->is_available()) {
            return $this->_getConForKey($name)->hget($name, $k);
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
        if ($this->is_available()) {
            return $this->_getConForKey($name)->hdel($name, $k);
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
        if ($this->is_available()) {
            $v = intval($v);
            return $this->_getConForKey($name)->hincr($name, $k, $v);
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
        if ($this->is_available()) {
            return $this->_getConForKey($name)->hexists($name, $k);
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
        if ($this->is_available()) {
            return $this->_getConForKey($name)->hsize($name);
        }
        return false;
    }

    /**
     * hlist, hrlist
     * 列出名字处于区间 (name_start, name_end] 的 hashmap.
     * ("", ""] 表示整个区间.
     * 参数
     *      name_start - 返回的起始名字(不包含), 空字符串表示 -inf.
     *      name_end - 返回的结束名字(包含), 空字符串表示 +inf.
     *      limit - 最多返回这么多个元素.
     * 返回值
     *      出错则返回 false, 返回返回包含名字的数组.
     */
    public function hlist($name_start, $name_end, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($name_start)->hlist($name_start, $name_end, $limit);
        }
        return false;
    }

    public function hrlist($name_start, $name_end, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($name_start)->hrlist($name_start, $name_end, $limit);
        }
        return false;
    }

    /**
     * 列出 hashmap 中处于区间 (key_start, key_end] 的 key 列表.
     * ("", ""] 表示整个区间.
     * 参数
     *      name - hashmap 的名字.
     *      key_start - 起始 key(不包含), 空字符串表示 -inf.
     *      key_end - 结束 key(包含), 空字符串表示 +inf.
     *      limit - 最多返回这么多个元素.
     * 返回值
     *      如果出错则返回 false, 否则返回包含 key 的数组.
     */
    public function hkeys($name, $key_start, $key_end, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->hkeys($name, $key_start, $key_end, $limit);
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
        if ($this->is_available()) {
            return $this->_getConForKey($name)->hgetall($name);
        }
        return false;
    }

    /**
     * 列出 hashmap 中处于区间 (key_start, key_end] 的 key-value 列表.
     * ("", ""] 表示整个区间.
     * 参数
     *      name - hashmap 的名字.
     *      key_start - 返回的起始 key(不包含), 空字符串表示 -inf.
     *      key_end - 返回的结束 key(包含), 空字符串表示 +inf.
     *      limit - 最多返回这么多个元素.
     * 返回值
     *      如果出错则返回 false, 否则返回包含 key-value 的关联数组.
     */
    public function hscan($name, $key_start, $key_end, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->hscan($name, $key_start, $key_end, $limit);
        }
        return false;
    }

    /**
     * 列出 hashmap 中处于区间 (key_start, key_end] 的 key-value 列表, 反向顺序.
     */
    public function hrscan($name, $key_start, $key_end, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->hrscan($name, $key_start, $key_end, $limit);
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
        if ($this->is_available()) {
            return $this->_getConForKey($name)->hclear($name);
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
    public function multi_hset($name, $kvs) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->multi_hset($name, $kvs);
        }
        return false;
    }

    /**
     * 批量获取 hashmap 中多个 key 对应的权重值.
     * 参数
     *        name - hashmap 的名字.
     *        keys - 包含 key 的数组 .
     * 返回值
     *        如果出错则返回 false, 否则返回包含 key-value 的关联数组, 如果某个 key 不存在, 则它不会出现在返回数组中.
     */
    public function multi_hget($name, $keys) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->multi_hget($name, $keys);
        }
        return false;
    }

    /**
     * 指删除 hashmap 中的 key.
     * 参数
     *      name - hashmap 的名字.
     *      keys - 包含 key 的数组 .
     * 返回值
     *      出错则返回 false, 其它值表示正常.
     */
    public function multi_hdel($name, $keys) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->multi_hdel($name, $keys);
        }
        return false;
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
            $v = intval($v);
            return $this->_getConForKey($name)->zset($name, $k, $v);
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
            return $this->_getConForKey($name)->zget($name, $k);
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
            return $this->_getConForKey($name)->zdel($name, $k);
        }
        return false;
    }

    /**
     * 使 zset 中的 key 对应的值增加 num. 参数 num 可以为负数. 如果原来的值不是整数(字符串形式的整数), 它会被先转换成整数.
     * 参数
     *      name - zset 的名字.
     *      key -
     *      num - 必须是有符号整数.
     * 返回值
     *      如果出错则返回 false, 否则返回新的值.
     */
    public function zincr($name, $k, $v) {
        if ($this->is_available()) {
            $v = intval($v);
            return $this->_getConForKey($name)->zincr($name, $k, $v);
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
            return $this->_getConForKey($name)->zexists($name, $k);
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
            return $this->_getConForKey($name)->zsize($name);
        }
        return false;
    }

    /**
     * zlist, zrlist
     * 列出名字处于区间 (name_start, name_end] 的 zset.
     * ("", ""] 表示整个区间.
     * 参数
     *       name_start - 返回的起始名字(不包含), 空字符串表示 -inf.
     *       name_end - 返回的结束名字(包含), 空字符串表示 +inf.
     *       limit - 最多返回这么多个元素.
     * 返回值
     *       出错则返回 false, 否则返回包含名字的数组.
     */
    public function zlist($name_start, $name_end, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($name_start)->zlist($name_start, $name_end, $limit);
        }
        return false;
    }

    public function zrlist($name_start, $name_end, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($name_start)->zrlist($name_start, $name_end, $limit);
        }
        return false;
    }

    /**
     * 列出 zset 中的 key 列表. 参见 zscan().
     * 参数
     *      name - zset 的名字.
     *      key_start - 参见 zscan().
     *      score_start - 参见 zscan().
     *      score_end - 参见 zscan().
     *      limit - 最多返回这么多个元素.
     * 返回值
     *      如果出错则返回 false, 否则返回包含 key 的数组.
     */
    public function zkeys($name, $key_start, $score_start, $score_end, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->zkeys($name, $key_start, $score_start, $score_end, $limit);
        }
        return false;
    }

    /**
     * 列出 zset 中处于区间 (key_start+score_start, score_end] 的 key-score 列表. 如果 key_start 为空, 那么对应权重值大于或者等于 score_start 的 key 将被返回. 如果 key_start 不为空, 那么对应权重值大于 score_start 的 key, 或者大于 key_start 且对应权重值等于 score_start 的 key 将被返回.
     * 也就是说, 返回的 key 在 (key.score == score_start && key > key_start || key.score > score_start), 并且 key.score <= score_end 区间. 先判断 score_start, score_end, 然后判断 key_start.
     * ("", ""] 表示整个区间.
     * 参数
     *        name - zset 的名字.
     *        key_start - score_start 对应的 key.
     *        score_start - 返回 key 的最小权重值(可能不包含, 依赖 key_start), 空字符串表示 -inf.
     *        score_end - 返回 key 的最大权重值(包含), 空字符串表示 +inf.
     *        limit - 最多返回这么多个元素.
     * 返回值
     *        如果出错则返回 false, 否则返回包含 key-score 的关联数组.
     */
    public function zscan($name, $key_start, $score_start, $score_end, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->zscan($name, $key_start, $score_start, $score_end, $limit);
        }
        return false;
    }

    /**
     * 列出 zset 中的 key-score 列表, 反向顺序. 参见 zkeys().
     */
    public function zrscan($name, $key_start, $score_start, $score_end, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->zrscan($name, $key_start, $score_start, $score_end, $limit);
        }
        return false;
    }

    /**
     * zrank, zrrank
     * 注意! 本方法可能会非常慢! 请在离线环境中使用.
     * 返回指定 key 在 zset 中的排序位置(排名), 排名从 0 开始. zrrank 获取是是倒序排名.
     * 参数
     *      name - zset 的名字.
     *      key -
     * 返回值
     * found.
     * 出错则返回 false, -1 表示 key 不存在于 zset, 否则返回排名.
     */
    public function zrank($name, $k) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->zrank($name, $k);
        }
        return false;
    }

    public function zrrank($name, $k) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->zrrank($name, $k);
        }
        return false;
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
            return $this->_getConForKey($name)->zrange($name, $offset, $limit);
        }
        return false;
    }

    public function zrrange($name, $offset, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->zrrange($name, $offset, $limit);
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
            return $this->_getConForKey($name)->zclear($name);
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
            return $this->_getConForKey($name)->zcount($name, $score_start, $score_end);
        }
        return false;
    }

    /**
     * 返回 key 处于区间 [start,end] 的 score 的和.
     * 参数
     *      name - zset 的名字.
     *      score_start - key 的最小权重值(包含), 空字符串表示 -inf.
     *      score_end - key 的最大权重值(包含), 空字符串表示 +inf.
     * 返回值
     *      如果出错则返回 false, 否则返回符合条件的 score 的求和.
     */
    public function zsum($name, $score_start, $score_end) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->zsum($name, $score_start, $score_end);
        }
        return false;
    }

    /**
     * 返回 key 处于区间 [start,end] 的 score 的平均值.
     * 参数
     *      name - zset 的名字.
     *      score_start - key 的最小权重值(包含), 空字符串表示 -inf.
     *      score_end - key 的最大权重值(包含), 空字符串表示 +inf.
     * 返回值
     *      如果出错则返回 false, 否则返回符合条件的 score 的平均值.
     */
    public function zavg($name, $score_start, $score_end) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->zavg($name, $score_start, $score_end);
        }
        return false;
    }

    /**
     * 删除位置处于区间 [start,end] 的元素.
     * 参数
     *      name - zset 的名字.
     *      start - (包含).
     *      end -(包含).
     * 返回值
     *      出错则返回 false, 否则返回被删除的元素个数.
     */
    public function zremrangebyrank($name, $start, $end) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->zremrangebyrank($name, $start, $end);
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
            return $this->_getConForKey($name)->zremrangebyscore($name, $score_start, $score_end);
        }
        return false;
    }

    /**
     * 从 zset 首部删除并返回 `limit` 个元素.
     * 参数
     *     name - zset 的名字.
     *     limit - 正整数, 最多要删除并返回这么多个 key-score 对.
     * 返回值
     * 如果出错则返回 false, 否则返回包含 key-score 的关联数组.
     */
    public function zpop_front($name, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->zpop_front($name, $limit);
        }
        return false;
    }

    /**
     * 从 zset 尾部删除并返回 `limit` 个元素.
     * 参数
     *     name - zset 的名字.
     *     limit - 正整数, 最多要删除并返回这么多个 key-score 对.
     * 返回值
     * 如果出错则返回 false, 否则返回包含 key-score 的关联数组.
     */
    public function zpop_back($name, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->zpop_back($name, $limit);
        }
        return false;
    }

    /**
     * 批量设置 zset 中的 key-score.
     * 参数
     *      name - zset 的名字.
     *      kvs - 包含 key-score 的关联数组 .
     * 返回值
     *      出错则返回 false, 其它值表示正常.
     */
    public function multi_zset($name, $kvs) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->multi_zset($name, $kvs);
        }
        return false;
    }

    /**
     * 批量获取 zset 中多个 key 对应的权重值.
     * 参数
     *      name - zset 的名字.
     *      keys - 包含 key 的数组 .
     * 返回值
     *      如果出错则返回 false, 否则返回包含 key-score 的关联数组, 如果某个 key 不存在, 则它不会出现在返回数组中.
     */
    public function multi_zget($name, $keys) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->multi_zget($name, $keys);
        }
        return false;
    }

    /**
     * 指删除 zset 中的 key.
     * 参数
     *      name - zset 的名字.
     *      keys - 包含 key 的数组 .
     * 返回值
     *      出错则返回 false, 其它值表示正常.
     */
    public function multi_zdel($name, $keys) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->multi_zdel($name, $keys);
        }
        return false;
    }

    /**
     * 返回队列的长度.
     * 参数
     *     name -
     * 返回值
     *  出错返回 false, 否则返回一个整数, 0 表示队列不存在(或者为空).
     */
    public function qsize($name) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->qsize($name);
        }
        return false;
    }

    /**
     * qlist, qrlist
     * 列出名字处于区间 (name_start, name_end] 的 queue/list.
     * ("", ""] 表示整个区间.
     * 参数
     *      name_start - 返回的起始名字(不包含), 空字符串表示 -inf.
     *      name_end - 返回的结束名字(包含), 空字符串表示 +inf.
     *      limit - 最多返回这么多个元素.
     * 返回值
     *      出错则返回 false, 返回返回包含名字的数组.
     */
    public function qlist($name_start, $name_end, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($name_start)->qlist($name_start, $name_end, $limit);
        }
        return false;
    }

    public function qrlist($name_start, $name_end, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($name_start)->qrlist($name_start, $name_end, $limit);
        }
        return false;
    }

    /**
     * 清空一个队列.
     * 参数
     *      name -
     * 返回值
     *      出错返回 false.
     */
    public function qclear($name) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->qclear($name);
        }
        return false;
    }

    /**
     * 返回队列的第一个元素.
     * 参数
     *      name -
     * 返回值
     *      出错返回 false, 队列不存在(或者为空)则返回 null, 否则返回一个元素.
     */
    public function qfront($name) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->qfront($name);
        }
        return false;
    }

    /**
     * qback
     * 返回队列的最后一个元素.
     * 参数
     *      name -
     * 返回值
     *      出错返回 false, 队列不存在(或者为空)则返回 null, 否则返回一个元素.
     */
    public function qback($name) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->qback($name);
        }
        return false;
    }

    /**
     *
     * 返回指定位置的元素. 0 表示第一个元素, 1 是第二个 ... -1 是最后一个.
     * 参数
     *          name -
     *          index - 可传负数.
     * 返回值
     *          出错返回 false, 如果指定位置不存在一个元素, 则返回 null, 否则返回一个元素.
     */
    public function qget($name, $index) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->qget($name, $index);
        }
        return false;
    }

    /**
     * 更新位于 index 位置的元素. 如果超过现有的元素范围, 会返回错误.
     * 参数
     *      name -
     *      index - 可传负数.
     *      val -
     * 返回值
     *      出错则返回 false, 其它值表示正常.
     */
    public function qset($name, $index, $val) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->qset($name, $index, $val);
        }
        return false;
    }

    /**
     * 返回下标处于区域 [offset, offset + limit] 的元素.
     * 参数
     *      name - queue 的名字.
     *      offset - 整数, 从此下标处开始返回. 从 0 开始. 可以是负数, 表示从末尾算起.
     *      limit - 正整数, 最多返回这么多个元素.
     * 返回值
     *      如果出错则返回 false, 否则返回数组.
     */
    public function qrange($name, $offset, $limit) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->qrange($name, $offset, $limit);
        }
        return false;
    }

    /**
     * 返回下标处于区域 [begin, end] 的元素. begin 和 end 可以是负数
     * 参数
     *       name -
     *       begin -
     *       end -
     * 返回值
     *       出错返回 false, 否则返回包含元素的数组.
     */
    public function qslice($name, $begin, $end) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->qslice($name, $begin, $end);
        }
        return false;
    }

    /**
     * 本函数是 qpush_back() 的别名.
     */
    public function qpush($name, $v) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->qpush_front($name, $v);
        }
        return false;
    }

    /**
     * 往队列的首部添加一个或者多个元素
     * 参数
     *        name -
     *        item - 字符串或是字符串数组.
     * 返回值
     *        添加元素之后, 队列的长度, 出错返回 false.
     */
    public function qpush_front($name, $item) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->qpush_front($name, $item);
        }
        return false;
    }

    /**
     * 往队列的尾部添加一个或者多个元素
     * 参数
     *       name -
     *       item - 字符串或是字符串数组.
     * 返回值
     *       添加元素之后, 队列的长度, 出错返回 false.
     */
    public function qpush_back($name, $item) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->qpush_back($name, $item);
        }
        return false;
    }

    /**
     * 本函数是 qpop_front() 的别名.
     */
    public function qpop($name) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->qpop_back($name, 1);
        }
        return false;
    }

    /**
     * 从队列首部弹出最后一个或者多个元素.
     * 参数
     *       name -
     *       size - 可选, 最多从队列弹出这么多个元素
     * 返回值
     *       出错返回 false. 当 size 未指定或者小于等于 1 时, 队列不存在(或者为空)则返回 null, 否则删除并返回一个元素. 当 size 大于等于 2 时, 返回一个数组包含弹出的元素.
     */
    public function qpop_front($name, $size = 1) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->qpop_back($name, $size);
        }
        return false;
    }

    /**
     *
     * 从队列尾部弹出最后一个或者多个元素.
     * 参数
     *      name -
     *      size - 可选, 最多从队列弹出这么多个元素
     * 返回值
     *      出错返回 false. 当 size 未指定或者小于等于 1 时, 队列不存在(或者为空)则返回 null, 否则删除并返回一个元素. 当 size 大于等于 2 时, 返回一个数组包含弹出的元素.
     */
    public function qpop_back($name, $size = 1) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->qpop_back($name, $size);
        }
        return false;
    }

    /**
     *
     * 从队列头部删除多个元素.
     * 参数
     *       name -
     *       size - 最多从队列删除这么多个元素
     * 返回值
     *       出错返回 false. 返回被删除的元素数量.
     */
    public function qtrim_front($name, $size) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->qtrim_front($name, $size);
        }
        return false;
    }

    /**
     *
     * 从队列尾部删除多个元素.
     * 参数
     *      name -
     *      size - 最多从队列删除这么多个元素
     * 返回值
     *      出错返回 false. 返回被删除的元素数量.
     */
    public function qtrim_back($name, $size) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->qtrim_back($name, $size);
        }
        return false;
    }

    /**
     * 清理 zhash
     * @param type $zname
     * @return boolean
     */
    public function multi_zscan_del($zname) {
        $key_start = '';
        $score_start = '';
        while (1) {
            $items = $this->_getConForKey($zname)->zscan($zname, $key_start, $score_start, '', 100);
            if (!$items) {
                break;
            }
            foreach ($items as $key => $score) {
                $key_start = $key;
                $score_start = $score;

                $this->_getConForKey($zname)->zdel($zname, $key);
            }
        }
        return true;
    }

    /**
     * 清理 hash
     * @param type $hname
     * @return boolean
     */
    public function multi_hscan_del($hname) {
        if ($this->is_available()) {
            $key_start = '';
            while (1) {
                $items = $this->_getConForKey($hname)->hscan($hname, $key_start, '', 100);
                if (!$items) {
                    break;
                }
                foreach ($items as $key => $score) {
                    $key_start = $key;
                    $this->_getConForKey($hname)->hdel($hname, $key);
                }
            }
            return true;
        }
        return false;
    }

    public function batch($name) {
        if ($this->is_available()) {
            return $this->_getConForKey($name)->batch();
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
        foreach ($this->link as $key => $value) {
            $this->link[$key]->close();
        }
        unset($this->link);
        unset($this->isConnected);
    }

    /**
     * 　单实例化
     * @staticvar array $obj
     * @param type $conf_name
     * @return \self
     */
    public static function getInstance($conf_name = 'ssdb_cache') {
        static $obj = [];
        if (!isset($obj[$conf_name])) {
            $obj[$conf_name] = new self($conf_name);
        }
        return $obj[$conf_name];
    }

}
