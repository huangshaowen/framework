<?php

namespace framework\core;

use framework\core\Exception;

/**
 * 响应
 */
class Response {

    /**
     * 当前contentType
     * @var string
     */
    protected $contentType = 'text/html';

    /**
     * 字符集
     * @var string
     */
    protected $charset = 'utf-8';

    /**
     * HTTP 状态代码
     * @var int
     */
    protected $status = 200;

    /**
     * HTTP 响应首部字段
     * @var array
     */
    protected $headers = [];

    /**
     * HTTP 响应体
     * @var array
     */
    protected $body;

    /**
     * @var array HTTP status codes
     */
    public static $codes = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        // 用于一般性的成功返回
        201 => 'Created',
        // 资源被创建
        202 => 'Accepted',
        // 用于Controller控制类资源异步处理的返回，仅表示请求已经收到。对于耗时比较久的处理，一般用异步处理来完成
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        // 此状态可能会出现在PUT、POST、DELETE的请求中，一般表示资源存在，但消息体中不会返回任何资源相关的状态或信息
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        210 => 'Content Different',
        226 => 'IM Used',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        // 资源的URI被转移，需要使用新的URI访问
        302 => 'Found',
        // 不推荐使用，此代码在HTTP1.1协议中被303/307替代。我们目前对302的使用和最初HTTP1.0定义的语意是有出入的，应该只有在GET/HEAD方法下，客户端才能根据Location执行自动跳转，而我们目前的客户端基本上是不会判断原请求方法的，无条件的执行临时重定向
        303 => 'See Other',
        // 返回一个资源地址URI的引用，但不强制要求客户端获取该地址的状态(访问该地址)
        304 => 'Not Modified',
        // 有一些类似于204状态，服务器端的资源与客户端最近访问的资源版本一致，并无修改，不返回资源消息体。可以用来降低服务端的压力
        305 => 'Use Proxy',
        306 => '(Unused)',
        307 => 'Temporary Redirect',
        // 目前URI不能提供当前请求的服务，临时性重定向到另外一个URI。在HTTP1.1中307是用来替代早期HTTP1.0中使用不当的302
        308 => 'Permanent Redirect',
        310 => 'Too many Redirect',
        400 => 'Bad Request',
        // 用于客户端一般性错误返回, 在其它4xx错误以外的错误，也可以使用400，具体错误信息可以放在body中
        401 => 'Unauthorized',
        // 在访问一个需要验证的资源时，验证错误
        402 => 'Payment Required',
        403 => 'Forbidden',
        //  一般用于非验证性资源访问被禁止，例如对于某些客户端只开放部分API的访问权限，而另外一些API可能无法访问时，可以给予403状态
        404 => 'Not Found',
        // 找不到URI对应的资源
        405 => 'Action Not Allowed',
        // HTTP的方法不支持，例如某些只读资源，可能不支持POST/DELETE。但405的响应header中必须声明该URI所支持的方法
        406 => 'Not Acceptable',
        // 客户端所请求的资源数据格式类型不被支持，例如客户端请求数据格式为application/xml，但服务器端只支持application/json
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        // 资源状态冲突，例如客户端尝试删除一个非空的Store资源
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Long',
        415 => 'Unsupported Media Type',
        // 用于有条件的操作不被满足时
        416 => 'Requested Range Not Satisfiable',
        417 => 'Expectation Failed',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Method failure',
        425 => 'Unordered Collection',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        449 => 'Retry With',
        450 => 'Blocked by Windows Parental Controls',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        // 服务器端的接口错误，此错误于客户端无关
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        // 网关错误
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        507 => 'Insufficient storage',
        508 => 'Loop Detected',
        509 => 'Bandwidth Limit Exceeded',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];

    public function __construct() {
        $this->contentType($this->contentType, $this->charset);
    }

    public static function getInstance() {
        static $obj;
        if (!$obj) {
            $obj = new self();
        }
        return $obj;
    }

    /**
     * LastModified
     * @access public
     * @param  string $time
     * @return $this
     */
    public function lastModified(string $time) {
        $this->headers['Last-Modified'] = $time;

        return $this;
    }

    /**
     * Expires
     * @access public
     * @param  string $time
     * @return $this
     */
    public function expires(string $time) {
        $this->headers['Expires'] = $time;

        return $this;
    }

    /**
     * ETag
     * @access public
     * @param  string $eTag
     * @return $this
     */
    public function eTag(string $eTag) {
        $this->headers['ETag'] = $eTag;

        return $this;
    }

    /**
     * 页面缓存控制
     * @access public
     * @param  string $cache 状态码
     * @return $this
     */
    public function cacheControl(string $cache) {
        $this->headers['Cache-control'] = $cache;

        return $this;
    }

    /**
     * 页面输出类型
     * @access public
     * @param  string $contentType 输出类型
     * @param  string $charset     输出编码
     * @return $this
     */
    public function contentType(string $contentType, string $charset = 'utf-8') {
        $this->headers['Content-Type'] = $contentType . '; charset=' . $charset;

        return $this;
    }

    /**
     * 输出长度
     * @param type $length
     * @return $this
     */
    public function contentLength($length) {
        $this->headers['Content-Length'] = $length;
        return $this;
    }

    /**
     * 获取头部信息
     * @access public
     * @param  string $name 头部名称
     * @return mixed
     */
    public function getHeaders(string $name = '') {
        if (!empty($name)) {
            return $this->headers[$name] ?? null;
        }

        return $this->headers;
    }

    /**
     * Sets the HTTP status of the response.
     *
     * @param int $code HTTP status code.
     * @return object|int Self reference
     * @throws Exception If invalid status code
     */
    public function status($code = null) {
        if ($code === null) {
            return $this->status;
        }

        if (array_key_exists($code, self::$codes)) {
            $this->status = $code;
        } else {
            throw new Exception('Invalid status code.', 500);
        }

        return $this;
    }

    /**
     * Adds a header to the response.
     *
     * @param string|array $name Header name or array of names and values
     * @param string $value Header value
     * @return object Self reference
     */
    public function header($name, $value = null) {
        if (is_array($name)) {
            foreach ($name as $k => $v) {
                $this->headers[$k] = $v;
            }
        } else {
            $this->headers[$name] = $value;
        }

        return $this;
    }

    /**
     * Writes content to the response body.
     *
     * @param string $str Response content
     * @return object Self reference
     */
    public function write($str) {
        $this->body .= $str;

        return $this;
    }

    /**
     * Clears the response.
     *
     * @return object Self reference
     */
    public function clear() {
        $this->status = 200;
        $this->headers = [];
        $this->body = '';

        return $this;
    }

    /**
     * Sets caching headers for the response.
     *
     * @param int|string $expires Expiration time
     * @return object Self reference
     */
    public function cache($expires) {
        if ($expires === false) {
            $this->headers['Expires'] = 'Mon, 15 Jul 2001 05:00:00 GMT';
            $this->headers['Cache-Control'] = [
                'no-store, no-cache, must-revalidate',
                'post-check=0, pre-check=0',
                'max-age=0'
            ];
            $this->headers['Pragma'] = 'no-cache';
        } else {
            $expires = is_int($expires) ? $expires : strtotime($expires);
            $this->headers['Expires'] = gmdate('D, d M Y H:i:s', $expires) . ' GMT';
            $this->headers['Cache-Control'] = 'max-age=' . ($expires - time());
            if (isset($this->headers['Pragma']) && $this->headers['Pragma'] == 'no-cache') {
                unset($this->headers['Pragma']);
            }
        }
        return $this;
    }

    /**
     * Sends HTTP headers.
     *
     * @return object Self reference
     */
    public function sendHeaders() {
        // Send status code header
        if (strpos(php_sapi_name(), 'cgi') !== false) {
            header(sprintf('Status: %d %s', $this->status, self::$codes[$this->status]), true);
        } else {
            header(sprintf('%s %d %s', (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1'), $this->status, self::$codes[$this->status]), true, $this->status);
        }

        // Send other headers
        foreach ($this->headers as $field => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    header($field . ': ' . $v, false);
                }
            } else {
                header($field . ': ' . $value);
            }
        }

        return $this;
    }

    /**
     * Sends a HTTP response.
     */
    public function send() {
        if (ob_get_length() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            $this->sendHeaders();
        }

        echo $this->body;
    }

    /**
     * 重定向
     *
     * @param string  $url
     * @param int     $status
     */
    public function redirect($url, $status = 302) {
        $this->clear()->status($status)->header('Location', $url)->write($url)->send();
    }

    /**
     * 发送 json 格式到浏览器
     * @param type $data
     */
    public function json($data) {
        $json = json_encode($data);
        $this->clear()->contentType("application/json")->write($json)->send();
    }

    /**
     * 发送 jsonp 格式到浏览器
     * @param type $data
     */
    public function jsonp($data) {
        $callback = Request::getInstance()->get('callback', '', 'trim');
        $json = json_encode($data);

        $this->clear()->contentType("application/javascript")->write($callback . '(' . $json . ');')->send();
    }

    /**
     * 返回主体
     *
     * @return string
     */
    public function __toString() {
        return (string) $this->body;
    }

}
