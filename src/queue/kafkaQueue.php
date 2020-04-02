<?php

namespace framework\queue;

use framework\core\Config;

/**
 * kafka队列
 */
class kafkaQueue {

    private $producer;
    private $consumer;

    public static function getInstance() {
        static $obj;
        if (!$obj) {
            $obj = new self();
        }
        return $obj;
    }

    public function __construct() {
        if (!extension_loaded('rdkafka')) {
            throw new \Exception('rdkafka扩展未安装');
        }

        $kafka_broker_list = Config::getInstance()->get('kafka_broker_list');

        $conf = new \RdKafka\Conf();
        $conf->set('metadata.broker.list', $kafka_broker_list);

        $this->producer = new \RdKafka\Producer($conf);
    }

    public function __destruct() {
        
    }

    protected function getQueueKey($queue_name) {
        if (empty($queue_name)) {
            throw new \LengthException('队列名称不能为空', 410);
        }
        return 'queue_' . $queue_name;
    }

    /**
     * 消费
     * @param type $queue_name
     * @param callable $callback
     * @return boolean
     * @throws \Exception
     */
    public function consume($queue_name = 'queue_task', callable $callback) {

        $queue_name = $this->getQueueKey($queue_name);

        $kafka_broker_list = Config::getInstance()->get('kafka_broker_list');

        $conf = new \RdKafka\Conf();

// Set a rebalance callback to log partition assignments (optional)
        $conf->setRebalanceCb(function (\RdKafka\KafkaConsumer $kafka, $err, array $partitions = null) {
            switch ($err) {
                case RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS:
                    echo "Assign: ";
                    var_dump($partitions);
                    $kafka->assign($partitions);
                    break;

                case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
                    echo "Revoke: ";
                    var_dump($partitions);
                    $kafka->assign(NULL);
                    break;

                default:
                    throw new \Exception($err);
            }
        });

        $conf->set('group.id', "ConsumerGroup:" . $queue_name);
        $conf->set('metadata.broker.list', $kafka_broker_list);
        $conf->set('auto.offset.reset', 'smallest');
        //设置使用手动提交offset
        $conf->set('enable.auto.commit', 'false');

        $consumer = new \RdKafka\KafkaConsumer($conf);

        $consumer->subscribe([$queue_name]);

        echo "Waiting for partition assignment... (make take some time when\n";
        echo "quickly re-joining the group after leaving it.)\n";

        while (true) {
            $message = $consumer->consume(60 * 1000);
            switch ($message->err) {
                case RD_KAFKA_RESP_ERR_NO_ERROR:

                    $queue_data = json_decode($message->payload, true);
                    dump($queue_data);
                    $consumer->commit($message);

                    break;
                case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                    echo "No more messages; will wait for more\n";
                    break;
                case RD_KAFKA_RESP_ERR__TIMED_OUT:
                    echo "Timed out\n";
                    break;
                default:
                    throw new \Exception($message->errstr(), $message->err);
                    break;
            }
        }

        return false;
    }

    /**
     * 加入队列
     * @param   string      $queue_name     队列名称
     * @param   array       $data           数据
     * @return boolean
     */
    public function qpush($queue_name = 'queue_task', $data = []) {
        $queue_name = $this->getQueueKey($queue_name);

        $topic = $this->producer->newTopic($queue_name);

        /**
          第一个参数：是分区。RD_KAFKA_PARTITION_UA代表未分配，并让librdkafka选择分区
          第二个参数：是消息标志，必须为0
          第三个参数：消息，如果不为NULL，它将被传递给主题分区程序
         */
        $topic->produce(RD_KAFKA_PARTITION_UA, 0, json_encode($data));
        $this->producer->poll(0);

        for ($flushRetries = 0; $flushRetries < 10; $flushRetries++) {
            $result = $this->producer->flush(10000);
            if (RD_KAFKA_RESP_ERR_NO_ERROR === $result) {
                break;
            }
        }

        if (RD_KAFKA_RESP_ERR_NO_ERROR !== $result) {
            throw new \RuntimeException('Was unable to flush, messages might be lost!');
        }

        return true;
    }

}
