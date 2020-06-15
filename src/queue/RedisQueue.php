<?php

namespace framework\queue;

/**
 * RedisQueue
 */
class RedisQueue {

    private $redis;

    public function __construct($conf_name = 'redis_mq') {
        $this->redis = \framework\nosql\Redis::getInstance($conf_name);
    }

    /**
     * 　单实例化
     * @staticvar array $obj
     * @param type $conf_name
     * @return \self
     */
    public static function getInstance($conf_name = 'redis_mq') {
        static $obj = [];
        if (!isset($obj[$conf_name])) {
            $obj[$conf_name] = new self($conf_name);
        }
        return $obj[$conf_name];
    }

    protected function getQueueKey($queue_name) {
        if (empty($queue_name)) {
            throw new \LengthException('队列名称不能为空', 410);
        }
        return 'queue_' . $queue_name;
    }

    /**
     * 弹出队列数据
     * @param   string      $queue_name     队列名称
     * @param   int         $size           数量
     * @return boolean/array
     */
    public function qpop($queue_name = 'queue_task', $size = 1) {
        $queue_name = $this->getQueueKey($queue_name);

        if ($size == 1) {
            $vo = $this->redis->lPop($queue_name);
            if ($vo) {
                return $vo;
            }
            return false;
        }

        $data = [];
        for ($i = 0; $i < $size; $i++) {
            $vo = $this->redis->lPop($queue_name);
            if ($vo) {
                $data[] = $vo;
            }
        }
        return $data;
    }

    /**
     * 加入队列
     * @param   string      $queue_name     队列名称
     * @param   array       $data           数据
     * @return boolean
     */
    public function qpush($queue_name = 'queue_task', $data = []) {
        $queue_name = $this->getQueueKey($queue_name);

        return $this->redis->rPush($queue_name, $data);
    }

    /**
     * 查看队列数据
     * @param type $queue_name
     * @param type $start
     * @param type $end
     * @return type
     */
    public function qrange($queue_name = 'queue_task', $start = 0, $end = -1) {
        $queue_name = $this->getQueueKey($queue_name);

        return $this->redis->lRange($queue_name, $start, $end);
    }

    /**
     * 查看队列数量
     * @param type $queue_name
     * @return int
     */
    public function size($queue_name) {
        $queue_name = $this->getQueueKey($queue_name);

        return $this->redis->lLen($queue_name);
    }

}
