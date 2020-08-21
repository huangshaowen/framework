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

        if ($ttl > 0) {
            $msg = new \PhpAmqpLib\Message\AMQPMessage($body, ['expiration' => $ttl * 1000, 'delivery_mode' => $delivery_mode]);
            $this->channel->basic_publish($msg, $cache_exchange_name, $cache_exchange_name);
            return true;
        }

        $msg = new \PhpAmqpLib\Message\AMQPMessage($body, ['delivery_mode' => $delivery_mode]);
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

        $this->channel->exchange_declare($exchange_name, 'direct', false, false, false); // 持久交换机
        $this->channel->queue_declare($queue_name, false, true, false, false);   // 持久队列
        $this->channel->queue_bind($queue_name, $exchange_name); //将队列与某个交换机进行绑定

        $body = $this->setValue($data);
        $msg = new \PhpAmqpLib\Message\AMQPMessage($body, ['delivery_mode' => $delivery_mode]);

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

        $this->channel->exchange_declare($exchange_name, 'direct', false, false, false); // 持久交换机
        $this->channel->queue_declare($queue_name, false, true, false, false);   // 持久队列
        $this->channel->queue_bind($queue_name, $exchange_name); //将队列与某个交换机进行绑定

        $i = 0;
        $batch = 100;

        foreach ($datas as $key => $data) {
            $body = $this->setValue($data);
            $msg = new \PhpAmqpLib\Message\AMQPMessage($body, ['delivery_mode' => $delivery_mode]);

            $this->channel->batch_basic_publish($msg, $exchange_name);
            $i++;
            if ($i % $batch == 0) {
                $this->channel->publish_batch();
            }
        }

        return true;
    }

    /**
     * 普通队列弹出数据
     * @param   string      $queue_name     队列名称
     * @param   int         $size           数量
     * @return  array
     */
    public function qpop(string $queue_name = 'queue_task', int $size = 1) {
        $exchange_name = $this->getExchangeKey($queue_name);

        $this->channel->exchange_declare($exchange_name, 'direct', false, false, false); // 持久交换机
        $queue_stats = $this->channel->queue_declare($queue_name, false, true, false, false);   // 持久队列
        $this->channel->queue_bind($queue_name, $exchange_name); //将队列与某个交换机进行绑定

        /* 拉取的方式进行消费 */
        $queue_total = $queue_stats[1];
        if ($queue_total > 0) {
            if ($size <= 1) {
                $max_i = 1;
            } else {
                if ($queue_total > $size) {
                    $max_i = $size;
                } else {
                    $max_i = $queue_total;
                }
            }
            $data = [];
            for ($i = 0; $i < $max_i; $i++) {
                $msg = $this->channel->basic_get($queue_name);
                if (empty($msg)) {
                    break;
                }
                /* 确认消息已经处理 */
                $this->channel->basic_ack($msg->delivery_info['delivery_tag']);
                $data[] = $this->getValue($msg->body, false);
            }
            if ($max_i == 1) {
                return $data[0];
            }
            if (empty($data)) {
                return false;
            }
            return $data;
        }

        return false;
    }

    /**
     * 普通队列未处理消息数量
     * @param   string      $queue_name     队列名称
     * @return int
     */
    public function size(string $queue_name = 'queue_task') {
        $exchange_name = $this->getExchangeKey($queue_name);

        $this->channel->exchange_declare($exchange_name, 'direct', false, false, false); // 持久交换机
        $queue_stats = $this->channel->queue_declare($queue_name, false, true, false, false);   // 持久队列
        $this->channel->queue_bind($queue_name, $exchange_name); //将队列与某个交换机进行绑定

        return $queue_stats[1];
    }

    /**
     * 普通队列消费
     * @param   string      $queue_name     队列名称
     * @param   callable    $callback       回调方法
     */
    public function consume(string $queue_name = 'queue_task', callable $callback) {
        $exchange_name = $this->getExchangeKey($queue_name);

        $this->channel->exchange_declare($exchange_name, 'direct', false, false, false); // 持久交换机
        $this->channel->queue_declare($queue_name, false, true, false, false);   // 持久队列
        $this->channel->queue_bind($queue_name, $exchange_name); //将队列与某个交换机进行绑定

        $consumer = function($msg) use ($callback) {
            try {
                /* 获取消息内容 */
                $json = $msg->getBody();
                $data = $this->getValue($json, false);
                $status = call_user_func($callback, $data);
                if ($status) {
                    // 执行成功
                    $this->channel->basic_ack($msg->delivery_info['delivery_tag']);
                } else {
                    // 执行失败
                    $this->channel->nack($msg->delivery_info['delivery_tag']);
                }
            } catch (\Exception $e) {
                echo '[quick]异常';
            }
        };

        /* 只有consumer已经处理并确认了上一条message时queue才分派新的message给它 */
        $this->channel->basic_qos(null, 1, null);
        $this->channel->basic_consume($queue_name, '', false, false, false, false, $consumer);

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
            usleep(500);
        }
    }

}
