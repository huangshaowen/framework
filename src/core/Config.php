<?php

namespace framework\core;

/**
 * 网站配置
 */
class Config implements \ArrayAccess {

    /**
     * 配置参数
     * @var array
     */
    protected $config = [];

    /**
     * 构造方法
     * @access public
     */
    public function __construct() {
        
    }

    public static function getInstance() {
        static $obj;
        if (!$obj) {
            $obj = new self();
        }
        return $obj;
    }

    /**
     * 加载配置文件（PHP格式）
     *
     * @param string $file 配置文件名
     * @param string $name 配置名（如设置即表示二级配置）
     * @return mixed
     */
    public function load($file, $name = '') {
        if (is_file($file) || is_dir($file)) {
            /* 如果是文件 */
            if (is_file($file)) {
                return $this->set(include $file, $name);
            } elseif (is_dir($file)) {
                /* 如果是文件夹 */
                chdir($file);
                $configFiles = glob("*.php");
                foreach ($configFiles as $single_file) {
                    $this->set(include $file . $single_file, $name);
                }
            }
        }

        return $this->config;
    }

    /**
     * 检测配置是否存在
     * @access public
     * @param  string    $name 配置参数名（支持多级配置 .号分割）
     * @return bool
     */
    public function has($name) {
        return !is_null($this->get($name));
    }

    /**
     * 获取一级配置
     * @access public
     * @param  string    $name 一级配置名
     * @return array
     */
    public function pull($name) {
        $name = strtolower($name);

        return isset($this->config[$name]) ? $this->config[$name] : [];
    }

    /**
     * 获取配置参数 为空则获取所有配置
     * @access public
     * @param  string    $name      配置参数名（支持多级配置 .号分割）
     * @param  mixed     $default   默认值
     * @return mixed
     */
    public function get($name = null, $default = null) {
        // 无参数时获取所有
        if (empty($name)) {
            return $this->config;
        }

        if ('.' == substr($name, -1)) {
            return $this->pull(substr($name, 0, -1));
        }

        $name = strtolower($name);
        $name = explode('.', $name);
        $config = $this->config;

        // 按.拆分成多维数组进行判断
        foreach ($name as $val) {
            if (isset($config[$val])) {
                $config = $config[$val];
            } else {
                return $default;
            }
        }

        return $config;
    }

    /**
     * 设置配置参数 name为数组则为批量设置
     * @access public
     * @param  string|array  $name 配置参数名（支持三级配置 .号分割）
     * @param  mixed         $value 配置值
     * @return mixed
     */
    public function set($name, $value = null) {
        if (is_string($name)) {
            $name = strtolower($name);
            $name = explode('.', $name, 3);

            if (count($name) == 1) {
                $this->config[$name[0]] = $value;
            } elseif (count($name) == 2) {
                $this->config[$name[0]][$name[1]] = $value;
            } else {
                $this->config[$name[0]][$name[1]][$name[2]] = $value;
            }

            return $value;
        } elseif (is_array($name)) {
            // 批量设置
            if (!empty($value)) {
                if (isset($this->config[$value])) {
                    $result = array_merge($this->config[$value], array_change_key_case($name));
                } else {
                    $result = $name;
                }

                $this->config[$value] = $result;
            } else {
                $result = $this->config = array_merge($this->config, array_change_key_case($name));
            }
        } else {
            // 为空直接返回 已有配置
            $result = $this->config;
        }

        return $result;
    }

    /**
     * 移除配置
     * @access public
     * @param  string  $name 配置参数名（支持三级配置 .号分割）
     * @return void
     */
    public function remove($name) {
        $name = explode('.', $name, 3);

        if (count($name) == 2) {
            unset($this->config[strtolower($name[0])][$name[1]]);
        } else {
            unset($this->config[strtolower($name[0])][$name[1]][$name[2]]);
        }
    }

    /**
     * 重置配置参数
     * @access public
     * @param  string    $prefix  配置前缀名
     * @return void
     */
    public function reset($prefix = '') {
        if ('' === $prefix) {
            $this->config = [];
        } else {
            $this->config[$prefix] = [];
        }
    }

    /**
     * 设置配置
     * @access public
     * @param  string    $name  参数名
     * @param  mixed     $value 值
     */
    public function __set($name, $value) {
        return $this->set($name, $value);
    }

    /**
     * 获取配置参数
     * @access public
     * @param  string $name 参数名
     * @return mixed
     */
    public function __get($name) {
        return $this->get($name);
    }

    /**
     * 检测是否存在参数
     * @access public
     * @param  string $name 参数名
     * @return bool
     */
    public function __isset($name) {
        return $this->has($name);
    }

    // ArrayAccess
    public function offsetSet($name, $value) {
        $this->set($name, $value);
    }

    public function offsetExists($name) {
        return $this->has($name);
    }

    public function offsetUnset($name) {
        $this->remove($name);
    }

    public function offsetGet($name) {
        return $this->get($name);
    }

}
