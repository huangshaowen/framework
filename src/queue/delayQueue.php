<?php

namespace framework\queue;

use framework\core\Exception;

/**
 * 分钟级延时队列
 */
class delayQueue {

    private $ssdb;

    /**
     * 　单实例化
     * @staticvar array $obj
     * @param type $conf_name
     * @return \self
     */
    public static function getInstance($conf_name = 'ssdb_mq') {
        static $obj = [];
        if (!isset($obj[$conf_name])) {
            $obj[$conf_name] = new self($conf_name);
        }
        return $obj[$conf_name];
    }

    public function __construct($conf_name = 'ssdb_mq') {
        $this->ssdb = \framework\nosql\ssdbService::getInstance($conf_name);
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
     * 加入队列
     * @param   string      $queue_name     队列名称
     * @param   array       $data           数据
     * @param   int         $ttl            延时时间(300秒)
     * @return  int         $id             队列编号
     */
    public function qpush(string $queue_name = 'queue_task', array $data = [], int $ttl = 300) {
        if (empty($queue_name)) {
            throw new \LengthException('队列名称不能为空', 410);
        }

        $zname = "delay_queue_{$queue_name}";
        $hname = "delay_queue_{$queue_name}";

        /* 记录队列顺序 */
        $id = $this->ssdb->zincr('tickets_id', 'delay_queue_id', 1);
        if ($id == PHP_INT_MAX) {
            $id = 1;
            $this->ssdb->zset('tickets_id', 'delay_queue_id', 1);
        }

        /* 记录数据 */
        /* 修正延时 */
        $ttl = ($ttl <= 0) ? 10 : $ttl;
        $time = time() + $ttl;
        $this->ssdb->zset($zname, $id, $time);
        $this->ssdb->hset($hname, $id, $this->setValue($data));

        /* 积压队列数量 */
        $this->ssdb->zincr('delay_queue', $queue_name, 1);

        return $id;
    }

    /**
     * 弹出队列数据
     * @param   string      $queue_name     队列名称
     * @param   int         $size           数量
     * @return boolean/array
     */
    public function qpop(string $queue_name = 'queue_task', int $size = 1) {
        if (empty($queue_name)) {
            throw new \LengthException('队列名称不能为空', 410);
        }

        /* 检查积压数量 */
        $zname = "delay_queue_{$queue_name}";
        $hname = "delay_queue_{$queue_name}";
        $total = $this->size($queue_name);
        if ($total == 0) {
            return false;
        }

        /* 加锁 */
        $lock_key = "qpop_{$zname}";
        $lock_value = \framework\nosql\Redis::getInstance()->lock($lock_key, 10);
        if ($lock_value == false) {
            /* 加锁失败 */
            return false;
        }

        $return_data = [];
        /* 获取数据 */
        $score_end = time();
        $score_start = $score_end - 365 * 24 * 3600;
        $size = ($size > 1000 && $size <= 0) ? 1000 : $size;
        $items = $this->ssdb->zscan($zname, '', $score_start, $score_end, $size);
        if ($items) {
            foreach ($items as $id => $time) {
                /* 组合数据 */
                $value = $this->ssdb->hget($hname, $id);
                /* 删除队列数据 */
                $this->ssdb->zdel($zname, $id);
                $this->ssdb->hdel($hname, $id);
                if (empty($value)) {
                    continue;
                }
                $data = $this->getValue($value);
                if ($data) {
                    $return_data[] = $data;
                }
            }
        }

        /* 修正统计 */
        $total = $this->size($queue_name);
        $this->ssdb->zset('delay_queue', $queue_name, $total);

        /* 解锁 */
        \framework\nosql\Redis::getInstance()->unlock($lock_key, $lock_value);

        /* 返回 */
        if (empty($return_data)) {
            return false;
        }
        return $return_data;
    }

    /**
     * 弹出队列数据自动转正式队列
     * @param       string          $queue_name        队列名称
     * @param       int             $size              数量
     * @return boolean
     * @throws \LengthException
     */
    public function move_to_queue(string $queue_name = 'queue_task', int $size = 1) {
        if (empty($queue_name)) {
            throw new \LengthException('队列名称不能为空', 410);
        }

        /* 检查积压数量 */
        $zname = "delay_queue_{$queue_name}";
        $hname = "delay_queue_{$queue_name}";
        $total = $this->size($queue_name);
        if ($total == 0) {
            return false;
        }

        /* 加锁 */
        $lock_key = "move_to_queue_{$zname}";
        $lock_value = \framework\nosql\Redis::getInstance()->lock($lock_key, 5);
        if ($lock_value == false) {
            /* 加锁失败 */
            return false;
        }

        /* 获取数据 */
        $score_end = time();
        $score_start = $score_end - 365 * 24 * 3600;
        $size = ($size > 1000 && $size <= 0) ? 1000 : $size;
        $items = $this->ssdb->zscan($zname, '', $score_start, $score_end, $size);
        if ($items) {
            foreach ($items as $id => $time) {
                /* 组合数据 */
                $value = $this->ssdb->hget($hname, $id);
                /* 删除队列数据 */
                $this->ssdb->zdel($zname, $id);
                $this->ssdb->hdel($hname, $id);
                if (empty($value)) {
                    continue;
                }
                /* 存入正式队列 */
                $data = $this->getValue($value);
                if (empty($data)) {
                    continue;
                }
                /* 加入 redis 队列 */
                \framework\queue\ssdbQueue::getInstance()->qpush($queue_name, $data);
            }
        }

        /* 修正统计 */
        $total = $this->size($queue_name);
        $this->ssdb->zset('delay_queue', $queue_name, $total);

        /* 解锁 */
        \framework\nosql\Redis::getInstance()->unlock($lock_key, $lock_value);

        /* 返回 */
        return true;
    }

    /**
     * 删除指定队列的任务
     * @param string $queue_name
     * @param array $ids
     * @return boolean
     * @throws \LengthException
     */
    public function delete(string $queue_name = 'queue_task', array $ids = []) {
        if (empty($queue_name)) {
            throw new \LengthException('队列名称不能为空', 410);
        }

        if (empty($ids)) {
            return false;
        }

        $zname = "delay_queue_{$queue_name}";
        $hname = "delay_queue_{$queue_name}";

        if (is_array($ids)) {
            foreach ($ids as $key => $id) {
                $this->ssdb->zdel($zname, $id);
                $this->ssdb->hdel($hname, $id);
            }
        }

        /* 修正统计 */
        $total = $this->size($queue_name);
        $this->ssdb->zset('delay_queue', $queue_name, $total);

        return true;
    }

    /**
     * 获取所有延时队列名称列表
     * @param int $page
     * @param int $size
     * @return array
     */
    public function queue_list(int $page = 1, int $size = 20) {
        $zname = 'delay_queue';

        $total = $this->ssdb->zsize($zname);
        $total = intval($total);
        $max_page = ceil($total / $size);

        /* 返回数据结果 */
        $data = [
            'total' => $total,
            'max_page' => $max_page,
            'size' => $size,
            'page' => $page,
            'list' => [],
        ];
        if ($page > $max_page) {
            return $data;
        }

        $start = (($page - 1) * $size);
        $sort_order_method = 0;
        // 优化大数据量翻页
        if ($start > 1000 && $total > 2000 && $start > $total / 2) {
            $order = $sort_order_method == 0 ? 0 : 1;
            $newstart = $total - $start - $size;
            if ($newstart < 0) {
                $size += $newstart;
                $newstart = 0;
            }
            if ($order == 0) {
                $items = $this->ssdb->zrange($zname, $newstart, $size);
            } else {
                $items = $this->ssdb->zrrange($zname, $newstart, $size);
            }
            $items = array_reverse($items, TRUE);
        } else {
            $order = $sort_order_method == 0 ? 1 : 0;
            if ($order == 0) {
                $items = $this->ssdb->zrange($zname, $start, $size);
            } else {
                $items = $this->ssdb->zrrange($zname, $start, $size);
            }
        }

        $list = [];

        if ($items) {
            foreach ($items as $name => $score) {
                $list[] = [
                    'name' => $name,
                    'total' => $score,
                ];
            }
            $data['list'] = $list;
        }

        return $data;
    }

    /**
     * 获取队列数量
     * @param string $queue_name
     * @return int
     * @throws \LengthException
     */
    public function size(string $queue_name = 'queue_task') {
        if (empty($queue_name)) {
            throw new \LengthException('队列名称不能为空', 410);
        }

        $zname = "delay_queue_{$queue_name}";
        $total = $this->ssdb->zsize($zname);
        if (empty($total)) {
            return 0;
        }
        return $total;
    }

}
