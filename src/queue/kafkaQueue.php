<?php

namespace framework\queue;

use framework\core\Config;

/**
 *
 */
class kafkaQueue {

    private $broker_list = '127.0.0.1:9092';
    private $producer;

    public static function getInstance() {
        static $obj;
        if (!$obj) {
            $obj = new self();
        }
        return $obj;
    }

    public function __construct() {
        if (!extension_loaded('RdKafka')) {
            throw new \Exception('RdKafka扩展未安装');
        }

        $this->broker_list = Config::getInstance()->get('kafka_broker_list');

        $conf = new \RdKafka\Conf();
        $conf->set('metadata.broker.list', $this->broker_list);
        /* 保持原始顺序 */
        $conf->set('enable.idempotence', 'true');
        $this->producer = new \RdKafka\Producer($conf);
    }

    /**
     * 普通队列加入数据
     * @param   string      $queue_name         队列名称
     * @param   array       $data               数据
     * @param   string      $key                主键(NULL)
     * @return boolean
     */
    public function qpush(string $queue_name = 'queue_task', array $data = [], string $key = null) {
        if (empty($data)) {
            return false;
        }

        $topic = $this->producer->newTopic($queue_name);

        $body = $this->setValue($data);

        /* 随机选择partition */
        $topic->produce(\RD_KAFKA_PARTITION_UA, 0, $body, $key);
        $this->producer->poll(0);

        return true;
    }

    /**
     * 普通队列批量加入数据
     * @param   string      $queue_name         队列名称
     * @param   array       $datas              数据组
     * @return boolean
     */
    public function batch_qpush(string $queue_name = 'queue_task', array $datas = []) {
        if (empty($datas)) {
            return false;
        }

        $topic = $this->producer->newTopic($queue_name);

        $total = 0;
        foreach ($datas as $key => $value) {
            $total++;
            $body = $this->setValue($value);
            /* 随机选择partition */
            $topic->produce(\RD_KAFKA_PARTITION_UA, 0, $body);
            $this->producer->poll(0);
        }

        for ($flushRetries = 0; $flushRetries < $total; $flushRetries++) {
            $result = $this->producer->flush(10000);
            if (\RD_KAFKA_RESP_ERR_NO_ERROR === $result) {
                return true;
            }
        }

        if (\RD_KAFKA_RESP_ERR_NO_ERROR !== $result) {
            throw new \RuntimeException('Was unable to flush, messages might be lost!');
        }

        return true;
    }

    /**
     * 数据出队，会有卡顿2秒
     * @param string $queue_name
     * @param int $size
     * @return boolean
     * @throws \Exception
     */
    public function qpop(string $queue_name = 'queue_task', int $size = 1) {
        $conf = new \RdKafka\Conf();

        $conf->set('group.id', $queue_name);

        $rk = new \RdKafka\Consumer($conf);
        $rk->addBrokers(\framework\core\Config::getInstance()->get('kafka_broker_list'));

        $topicConf = new \RdKafka\TopicConf();
        $topicConf->set('auto.commit.interval.ms', 100);
        $topicConf->set('offset.store.method', 'broker');
        $topicConf->set('auto.offset.reset', 'earliest');

        $topic = $rk->newTopic($queue_name, $topicConf);

        $topic->consumeStart(0, \RD_KAFKA_OFFSET_STORED);

        $total = 0;
        $return_data = [];
        $sleep_times = 0;
        while (true) {
            $message = $topic->consume(0, 2000);
            if (empty($message)) {
                break;
            }
            switch ($message->err) {
                case \RD_KAFKA_RESP_ERR_NO_ERROR:

                    if (empty($message->payload)) {
                        $sleep_times++;
                        break;
                    }

                    /* 转换数据 */
                    $data = $this->getValue($message->payload, true);

                    if ($size == 1) {
                        return $data;
                    }

                    /* 多条返回结果 */
                    $return_data[] = $data;

                    $total++;
                    if ($total >= $size) {
                        $sleep_times++;
                    }

                    break;
                case \RD_KAFKA_RESP_ERR__PARTITION_EOF:
                    echo "No more messages; will wait for more\n";
                    break;
                case \RD_KAFKA_RESP_ERR__TIMED_OUT:
                    echo "Timed out\n";
                    $sleep_times++;
                    break;
                default:
                    throw new \Exception($message->errstr(), $message->err);
                    break;
            }

            if ($sleep_times >= 1) {
                break;
            }
        }

        if (empty($return_data)) {
            return false;
        }

        return $return_data;
    }

    public function __destruct() {
        $result = $this->producer->flush(500);
        if (\RD_KAFKA_RESP_ERR_NO_ERROR === $result) {
            return true;
        }

        if (\RD_KAFKA_RESP_ERR_NO_ERROR !== $result) {
            throw new \RuntimeException('Was unable to flush, messages might be lost!');
        }
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

}
