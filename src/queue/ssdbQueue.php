<?php

namespace framework\queue;

use framework\core\Exception;
use framework\nosql\ssdbService;

/**
 * ssdbQueue
 */
class ssdbQueue {

    public static function getInstance() {
        static $obj;
        if (!$obj) {
            $obj = new self();
        }
        return $obj;
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

        return ssdbService::getInstance()->qpush($queue_name, $this->setValue($data));
    }

    /**
     * 批量加入队列
     * @param string $queue_name
     * @param array $data
     * @return boolean
     */
    public function batch_qpush(string $queue_name = 'queue_task', array $data = []) {
        $queue_name = $this->getQueueKey($queue_name);

//        ssdbService::getInstance()->batch($queue_name);

        if (empty($data)) {
            return false;
        }

        foreach ($data as $key => $value) {
            ssdbService::getInstance()->qpush($queue_name, $this->setValue($value));
        }

//        ssdbService::getInstance()->exec($queue_name);

        return true;
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
            $vo = ssdbService::getInstance()->qpop_front($queue_name, $size);
            if ($vo) {
                return $this->getValue($vo);
            }
            return false;
        }
        $list = ssdbService::getInstance()->qpop_front($queue_name, $size);
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
     * @param type $queue_name
     * @param type $start
     * @param type $end
     * @return type
     */
    public function qrange($queue_name = 'queue_task', $start = 0, $end = -1) {
        $queue_name = $this->getQueueKey($queue_name);

        $rows = ssdbService::getInstance()->qrange($queue_name, $start, $end);
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
     * @param type $queue_name
     * @return int
     */
    public function size($queue_name = 'queue_task') {
        $queue_name = $this->getQueueKey($queue_name);

        $rs = ssdbService::getInstance()->qsize($queue_name);
        if ($rs) {
            return $rs;
        }
        return 0;
    }

}
