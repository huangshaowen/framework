<?php

namespace framework\queue;

use framework\core\Exception;

/**
 * ssdbQueue
 */
class ssdbQueue {

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
    protected function setValue($value) {
        if (!is_numeric($value)) {
            try {
                $value = json_encode($value);
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
    protected function getValue($value, $default = false) {
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

    protected function getQueueKey(string $queue_name) {
        if (empty($queue_name)) {
            throw new \LengthException('队列名称不能为空', 410);
        }
        return 'queue_' . $queue_name;
    }

    /**
     * 加入队列
     * @param   string      $queue_name     队列名称
     * @param   array       $data           数据
     * @return boolean
     */
    public function qpush(string $queue_name = 'queue_task', array $data = []) {
        $queue_name = $this->getQueueKey($queue_name);

        return $this->ssdb->qpush($queue_name, $this->setValue($data));
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

        foreach ($data as $key => $value) {
            $this->ssdb->qpush($queue_name, $this->setValue($value));
        }

        return true;
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
            $vo = $this->ssdb->qpop_front($queue_name, $size);
            if ($vo) {
                return $this->getValue($vo);
            }
            return false;
        }
        $list = $this->ssdb->qpop_front($queue_name, $size);
        if ($list) {
            $data = [];
            foreach ($list as $key => $value) {
                $data[$key] = $this->getValue($value);
            }
            return $data;
        }
        return false;
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

        $rows = $this->ssdb->qrange($queue_name, $start, $end);
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
     * 查看队列数量
     * @param string $queue_name
     * @return int
     */
    public function size(string $queue_name = 'queue_task') {
        $queue_name = $this->getQueueKey($queue_name);

        $rs = $this->ssdb->qsize($queue_name);
        if ($rs) {
            return $rs;
        }
        return 0;
    }

}
