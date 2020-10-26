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
    public function qpop(string $queue_name = 'queue_task', int $size = 1) {
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
    public function qpush(string $queue_name = 'queue_task', array $data = []) {
        $queue_name = $this->getQueueKey($queue_name);

        return $this->redis->rPush($queue_name, $data);
    }

    /**
     * 批量加入队列
     * @param string $queue_name
     * @param array $data
     * @return boolean
     */
    public function batch_qpush(string $queue_name = 'queue_task', array $data = []) {
        $queue_name = $this->getQueueKey($queue_name);

        if (empty($data)) {
            return false;
        }

//        $this->redis->batch($queue_name);
        foreach ($data as $key => $value) {
            $this->redis->rPush($queue_name, $value);
        }
//        $this->redis->exec($queue_name);

        return true;
    }

    /**
     * 查看队列数据
     * @param string $queue_name
     * @param int $start
     * @param int $end
     * @return boolean/array
     */
    public function qrange(string $queue_name = 'queue_task', int $start = 0, int $end = -1) {
        $queue_name = $this->getQueueKey($queue_name);

        return $this->redis->lRange($queue_name, $start, $end);
    }

    /**
     * 查看队列数量
     * @param string $queue_name
     * @return int
     */
    public function size(string $queue_name) {
        $queue_name = $this->getQueueKey($queue_name);

        return $this->redis->lLen($queue_name);
    }

}
