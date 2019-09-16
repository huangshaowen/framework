<?php

namespace framework\core;

class Session {

    /**
     * 是否初始化
     * @var bool
     */
    protected $init;

    /**
     * 初始化
     * @access public
     * @return void
     */
    public function init() {

        $type = Config::getInstance()->get('SESSION_TYPE') ? Config::getInstance()->get('SESSION_TYPE') : 'redis';

        $class = strpos($type, '\\') ? $type : 'framework\\session\\' . $type . 'Driver';
        if (!class_exists($class)) {
            throw new Exception('error session handler:' . $class);
        }

        /* 采用 NoSQL 保存 session */
        session_set_save_handler(new $class());

        /* 启动session */
        session_start();

        $this->init = true;
    }

    /**
     * 获取当前　Session 的 sid
     * @return type
     */
    public function get_session_id() {
        !isset($this->init) && $this->init();
        return session_id();
    }

    /**
     * 设置当前 Session 的 sid, 必须要 session 启动前使用
     * @param type $id
     * @return type
     */
    public function set_session_id($id = null) {
        return session_id($id);
    }

    public static function getInstance() {
        static $obj;
        if (!$obj) {
            $obj = new self();
        }
        return $obj;
    }

    /**
     * 设置 session
     * @param   string    $name   名称
     * @param   string    $value  值
     */
    public function set($name, $value = '') {
        !isset($this->init) && $this->init();

        $_SESSION[$name] = $value;
    }

    /**
     * 判断session数据
     * @param type $name    名称
     * @return bool
     */
    public function has($name) {
        !isset($this->init) && $this->init();

        return isset($_SESSION[$name]);
    }

    /**
     * 获取 session
     * @param type $name
     * @param type $prefix
     */
    public function get($name = '') {
        !isset($this->init) && $this->init();

        if ('' == $name) {
            $value = $_SESSION;
        } elseif (isset($_SESSION[$name])) {
            $value = $_SESSION[$name];
        } else {
            $value = null;
        }

        return $value;
    }

    /**
     * 删除 session
     */
    public function delete($name) {
        !isset($this->init) && $this->init();

        unset($_SESSION[$name]);
        return true;
    }

    /**
     * 销毁 session
     * @return boolean
     */
    public function clear() {
        !isset($this->init) && $this->init();

        $_SESSION = [];
        session_unset();
        return true;
    }

}
