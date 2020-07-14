<?php

namespace framework\queue;

use framework\core\Config;
use framework\core\Exception;

/**
 *
 * https://nsq.io/components/nsqd.html#post-pub
 *
 */
class nsqQueue {

    private $client;

    public static function getInstance($host = '') {
        static $obj;

        if (empty($host)) {
            $host = Config::getInstance()->get('nsqd_host');
        }

        if (!isset($obj[$host])) {
            $obj[$host] = new self($host);
        }
        return $obj[$host];
    }

    /**
     * 设置value,用于序列化存储
     * @param mixed $value
     * @return mixed
     */
    public function setValue($value) {
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

    public function __construct($host) {
        $this->client = new \GuzzleHttp\Client([
            'base_uri' => $host,
            'timeout' => 2.0
        ]);
    }

    /**
     * 加入队列
     * @param string $queue_name
     * @param array $data
     * @param int $ttl
     * @return int
     * @throws \LengthException
     */
    public function qpush(string $queue_name = 'queue_task', array $data = [], int $ttl = 0) {
        if (empty($queue_name)) {
            throw new \LengthException('队列名称不能为空', 410);
        }

        $body = $this->setValue($data);

        $url = "/pub?topic={$queue_name}";
        if ($ttl > 0) {
            $url .= "&defer=" . $ttl * 1000;
        }

        $response = $this->client->request('POST', $url, [
            'body' => $body
        ]);

        $code = $response->getStatusCode();

        return $code;
    }

    /**
     * 批量加入队列
     * @param string $queue_name
     * @param array $data
     * @return int
     * @throws \LengthException
     */
    public function batch_qpush(string $queue_name = 'queue_task', array $data = []) {
        if (empty($queue_name)) {
            throw new \LengthException('队列名称不能为空', 410);
        }

        if (empty($data)) {
            throw new \LengthException('队列数据不能为空', 411);
        }

        $body = [];
        foreach ($data as $key => $value) {
            $body[] = $this->setValue($value);
        }

        $url = "/mpub?topic={$queue_name}";

        $response = $this->client->request('POST', $url, [
            'body' => implode("\n", $body),
        ]);

        $code = $response->getStatusCode();

        return $code;
    }

    public function stats(string $queue_name = 'queue_task') {

        if (empty($queue_name)) {
            $url = "/stats?format=json";
        } else {
            $url = "/stats?format=json&topic={$queue_name}";
        }

        try {
            $response = $this->client->request('GET', $url);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            \framework\core\Log::getInstance()->warning($e);
            return ['ret' => 500, 'data' => null, 'msg' => '远程服务器响应错误！'];
        }

        if ($response->getStatusCode() == 200) {
            $body = $response->getBody();
            return json_decode(trim($body), true);
        } else {
            return ['ret' => 500, 'data' => null, 'msg' => '远程服务器响应错误！'];
        }
    }

    public function info() {
        $url = "/info";

        try {
            $response = $this->client->request('GET', $url);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            \framework\core\Log::getInstance()->warning($e);
            return ['ret' => 500, 'data' => null, 'msg' => '远程服务器响应错误！'];
        }

        if ($response->getStatusCode() == 200) {
            $body = $response->getBody();
            return json_decode(trim($body), true);
        } else {
            return ['ret' => 500, 'data' => null, 'msg' => '远程服务器响应错误！'];
        }
    }

}
