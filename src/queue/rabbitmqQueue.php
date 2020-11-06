<?php

namespace framework\queue;

use framework\core\Config;

/**
 *
 * https://www.rabbitmq.com/
 *
 *
 * https://github.com/php-amqplib/php-amqplib
 *
 *
  队列配置示例
  'rabbitmq' => ['host' => '127.0.0.1', 'port' => '5672', 'user' => 'guest', 'pass' => 'guest', 'vhost' => '/'],
 *
 */
class rabbitmqQueue {

    protected $connection;
    protected $channel;
    protected $mq_config;

    public static function getInstance() {
        static $obj;
        if (!$obj) {
            $obj = new self();
        }
        return $obj;
    }

    public function __construct() {
        if (!extension_loaded('sockets')) {
            throw new \Exception('sockets扩展未安装');
        }

        $this->mq_config = Config::getInstance()->get('rabbitmq');

        $this->connection = new \PhpAmqpLib\Connection\AMQPStreamConnection($this->mq_config['host'], $this->mq_config['port'], $this->mq_config['user'], $this->mq_config['pass'], $this->mq_config['vhost']);
        if (!(bool) $this->connection->isConnected()) {
            throw new \Exception('Rabbitmq Connect error!');
        }

        $this->channel = $this->connection->channel();
    }

    public function __destruct() {
        $this->channel->close();
        $this->connection->close();
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
     * 获取交换器名称
     * @param type $queue_name
     * @return type
     * @throws \LengthException
     */
    protected function getExchangeKey($queue_name) {
        if (empty($queue_name)) {
            throw new \LengthException('队列名称不能为空', 410);
        }
        return $queue_name . '_ex';
    }

    /**
     * 延时队列加入数据
     * @param   string      $queue_name         队列名称
     * @param   array       $data               数据
     * @param   int         $ttl                延时时间（单位秒,会影响后面的数据）
     * @param   int         $delivery_mode      持久化模式(1内存、2硬盘)
     * @return  boolean
     */
    public function delay_qpush(string $queue_name = 'queue_task', array $data = [], int $ttl = 0, int $delivery_mode = 2) {

        $exchange_name = $this->getExchangeKey($queue_name);
        $cache_exchange_name = "{$exchange_name}_{$ttl}";
        $cache_queue_name = "{$queue_name}_{$ttl}";

        /* 声明两个队列 */
        $this->channel->exchange_declare($exchange_name, 'direct', false, false, false);
        $this->channel->exchange_declare($cache_exchange_name, 'direct', false, false, false);

        $tale = new \PhpAmqpLib\Wire\AMQPTable(
                [
            'x-dead-letter-exchange' => $exchange_name,
            'x-dead-letter-routing-key' => $exchange_name,
                ]
        );

        $this->channel->queue_declare($cache_queue_name, false, true, false, false, false, $tale);
        $this->channel->queue_bind($cache_queue_name, $cache_exchange_name, $cache_exchange_name);

        $this->channel->queue_declare($queue_name, false, true, false, false, false);
        $this->channel->queue_bind($queue_name, $exchange_name, $exchange_name);

        $body = $this->setValue($data);
        $message_id = \Ramsey\Uuid\Uuid::uuid4();

        if ($ttl > 0) {
            $msg = new \PhpAmqpLib\Message\AMQPMessage($body, ['expiration' => $ttl * 1000, 'delivery_mode' => $delivery_mode, 'message_id' => $message_id]);
            $this->channel->basic_publish($msg, $cache_exchange_name, $cache_exchange_name);
            return true;
        }

        $msg = new \PhpAmqpLib\Message\AMQPMessage($body, ['delivery_mode' => $delivery_mode, 'message_id' => $message_id]);
        $this->channel->basic_publish($msg, $exchange_name);

        return true;
    }

    /**
     * 普通队列加入数据
     * @param   string      $queue_name         队列名称
     * @param   array       $data               数据
     * @param   int         $delivery_mode      持久化模式(1内存、2硬盘)
     * @return boolean
     */
    public function qpush(string $queue_name = 'queue_task', array $data = [], int $delivery_mode = 1) {
        $exchange_name = $this->getExchangeKey($queue_name);

        $this->exchange_declare($queue_name);
        $this->queue_declare($queue_name);
        $this->queue_bind($queue_name);

        $body = $this->setValue($data);
        $message_id = \Ramsey\Uuid\Uuid::uuid4();
        $msg = new \PhpAmqpLib\Message\AMQPMessage($body, ['delivery_mode' => $delivery_mode, 'message_id' => $message_id]);

        $this->channel->basic_publish($msg, $exchange_name);

        return true;
    }

    /**
     * 普通队列批量加入数据
     * @param   string      $queue_name         队列名称
     * @param   array       $datas              数据组
     * @param   int         $delivery_mode      持久化模式(1内存、2硬盘)
     * @return boolean
     */
    public function batch_qpush(string $queue_name = 'queue_task', array $datas = [], int $delivery_mode = 1) {
        $exchange_name = $this->getExchangeKey($queue_name);

        $this->exchange_declare($queue_name);
        $this->queue_declare($queue_name);
        $this->queue_bind($queue_name);

        $i = 0;
        $batch = 100;

        foreach ($datas as $key => $data) {
            $body = $this->setValue($data);
            $message_id = \Ramsey\Uuid\Uuid::uuid4();
            $msg = new \PhpAmqpLib\Message\AMQPMessage($body, ['delivery_mode' => $delivery_mode, 'message_id' => $message_id]);

            $this->channel->batch_basic_publish($msg, $exchange_name);
            $i++;
            if ($i % $batch == 0) {
                $this->channel->publish_batch();
            }
        }

        $this->channel->publish_batch();

        return true;
    }

    /**
     * 消费消息（拉模式）
     * @param string $queue_name        队列名称
     * @return boolean/obj
     */
    public function receive(string $queue_name = 'queue_task') {
        $this->exchange_declare($queue_name);
        $this->queue_declare($queue_name);
        $this->queue_bind($queue_name);

        $msg = $this->channel->basic_get($queue_name);
        if (empty($msg)) {
            return false;
        }

        return $msg;
    }

    /**
     * 声明交换器
     * @param string $queue_name
     * @return $this
     */
    public function exchange_declare(string $queue_name = '') {
        $exchange_name = $this->getExchangeKey($queue_name);
        $this->channel->exchange_declare($exchange_name, 'direct', false, false, false); // 持久交换机
        return $this;
    }

    /**
     * 声明队列
     * @param string $queue_name
     * @return $this
     */
    public function queue_declare(string $queue_name = 'queue_task') {
        $this->channel->queue_declare($queue_name, false, true, false, false);   // 持久队列
        return $this;
    }

    /**
     * 队列与交换器绑定
     * @param string $queue_name
     * @return $this
     */
    public function queue_bind(string $queue_name = 'queue_task') {
        $exchange_name = $this->getExchangeKey($queue_name);
        $this->channel->queue_bind($queue_name, $exchange_name);
        return $this;
    }

    /**
     * 消费确认
     * @param obj $delivery
     * @return bool
     */
    public function acknowledge($delivery) {
        return $delivery['channel']->basic_ack($delivery['delivery_tag']);
    }

    /**
     * 消息拒绝 - 单条
     * @param obj $delivery
     * @return bool
     */
    public function reject($delivery): bool {
        return $delivery['channel']->basic_ack($delivery['delivery_tag']);
    }

    /**
     * 消息未确认上限
     * @param $prefetch_size 未确认消息总体大小（B）0表示没有上限
     * @param $prefetch_count 信道未确认消息上限
     * @param $a_global 全局配置（信道上全部消费者都得遵从/信道上新消费者）
     * @return mixed
     */
    public function basic_qos($prefetch_count = 1) {
        $this->channel->basic_qos(null, $prefetch_count, null);
        return $this;
    }

    /**
     * 消费消息（推模式）
     * @param   string $queue_name            队列名称
     * @param   callable $callback            回调函数
     * @param   int $qos                      消息未确认上限
     */
    public function basic_consume(string $queue_name, callable $callback, int $qos = 1) {
        $this->exchange_declare($queue_name);
        $this->queue_declare($queue_name);
        $this->queue_bind($queue_name);
        $this->basic_qos($qos);
        $this->channel->basic_consume($queue_name, '', false, false, false, false, $callback);
    }

    public function wait() {
        return $this->channel->wait();
    }

}
