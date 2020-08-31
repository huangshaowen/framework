<?php

namespace framework\core;

use framework\core\Exception;

class App {

    public static function getInstance() {
        static $obj;
        if (!$obj) {
            $obj = new self();
        }
        return $obj;
    }

    /**
     * 应用程序初始化
     */
    public function init() {
        /* 设置默认时区 */
        date_default_timezone_set('Asia/Shanghai');

        /* 注册异常处理类 */
        Error::register();

        /* 注册自动加载 */
        Loader::register();
    }

    /**
     * 执行应用程序
     * @access public
     * @return void
     */
    public function exec() {
        $class_name = Dispatcher::getInstance()->get_app_name() . "\\action\\" . Dispatcher::getInstance()->get_module_name();
        $action = Dispatcher::getInstance()->get_action_name();

        /* 检查类是否存在 */
        if (!class_exists($class_name)) {
            $class_name = Dispatcher::getInstance()->get_app_name() . "\\action\\_empty";
            if (!class_exists($class_name)) {
                /* 显示 404 页面 */
                throw new Exception('class not exists:' . $class_name, 404);
            }
        }

        /* 检查方法是否存在 */
        try {
            $reflect = new \ReflectionClass($class_name);
        } catch (\ReflectionException $e) {
            /* 显示 404 页面 */
            throw new Exception('class not exists:' . $class_name, 404);
        }

        if ($reflect->hasMethod($action) == false) {
            if ($reflect->hasMethod('_empty') == true) {
                $action = '_empty';
            } else {
                /* 显示 404 页面 */
                throw new Exception("class {$class_name} not exists action:" . $action, 404);
            }
        }

        /* 判断要调用的方法是否可用 */
        $method = $reflect->getMethod($action);
        if (($method->isPublic() == false) && ($method->isStatic() == false)) {
            /* 显示 404 页面 */
            throw new Exception("class {$class_name} not exists action:" . $action, 404);
        }

        /* 执行 */
        $object = $reflect->newInstance();
        try {
            $reflect = new \ReflectionMethod($class_name, $action);
            return $reflect->invoke($object);
        } catch (\ReflectionException $e) {
            throw new Exception($e->getTraceAsString(), 500);
        }
    }

    /**
     * 获取当前应用名称
     * @return type
     */
    public function get_app_name() {
        return Dispatcher::getInstance()->get_app_name();
    }

    /**
     * 获取当前模板名称
     * @return type
     */
    public function get_module_name() {
        return Dispatcher::getInstance()->get_module_name();
    }

    /**
     * 获取当前操作方法名称
     * @return type
     */
    public function get_action_name() {
        return Dispatcher::getInstance()->get_action_name();
    }

    /**
     * 运行应用实例 入口文件使用的快捷方法
     * @access public
     * @return void
     */
    public function run() {
        /* 初始化 */
        $this->init();
        /* URL调度 */
        Dispatcher::getInstance()->dispatch();

        /* 执行 */
        $data = $this->exec();
        /* 输出 */
        if (is_object($data) || is_array($data)) {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
            if ($json == false) {
                if (false === $data) {
                    throw new \InvalidArgumentException(json_last_error_msg());
                }
            }
            Response::getInstance()->clear()->contentType('application/json')->write($json)->send();
        } else {
            /* 字符输出 */
            if (!empty($data)) {
                Response::getInstance()->write($data)->send();
            }
        }
    }

}
