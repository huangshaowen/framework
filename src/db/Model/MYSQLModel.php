<?php

namespace framework\db\Model;

use framework\nosql\Redis;
use framework\core\Exception;

/**
 * MySQL Model模型类
 */
class MYSQLModel {

    // 当前数据库操作对象
    protected $db = null;
    // 主键名称
    protected $pk = 'id';
    // 主键是否自动增长
    protected $autoinc = false;
    // 模型名称
    protected $name = '';
    // 数据库名称
    protected $dbName = '';
    //数据库配置
    protected $connection = '';
    // 数据表名（不包含表前缀）
    protected $tableName = '';
    // 最近错误信息
    protected $error = '';
    // 数据库字段缓存
    protected $db_fields_cache = true;
    // 字段信息
    protected $fields = [];
    // 数据信息
    protected $data = [];
    // 查询表达式参数
    protected $options = [];
    protected $_map = [];  // 字段映射定义
    protected $_scope = [];  // 命名范围定义
    // 链操作方法列表
    protected $methods = ['strict', 'order', 'alias', 'having', 'group', 'lock', 'distinct', 'auto', 'filter', 'result', 'index', 'force'];

    /**
     * 设置自增序列名
     * @param string $name
     * return \Think\Model
     */
    public function setSequence($name) {
        $this->db->setSequenceName($name);
        return $this;
    }

    /**
     * 获取自增序列名
     * @param string $name
     */
    public function getSequence($name) {
        return $this->db->getSequenceName();
    }

    /**
     * 架构函数
     * @access public
     * @param string $name 模型名称
     */
    public function __construct($name = '', $connection = 'mysql') {
        // 获取模型名称
        if (!empty($name)) {
            $this->name = $name;
        }
        $this->dbName = $connection;
        // 数据库初始化操作
        $this->db = \framework\db\Driver\Mysql::getInstance($connection);
    }

    /**
     * 析构方法
     * @access public
     */
    public function __destruct() {
        unset($this->db);
    }

    /**
     * 
     * @staticvar array $obj
     * @param type $name
     * @param type $connection
     * @return \self
     */
    public static function getInstance($name = '', $connection = 'mysql') {
        $key = md5("{$name}:{$connection}");
        static $obj = [];
        if (!isset($obj[$key])) {
            $obj[$key] = new self($name, $connection);
        }
        return $obj[$key];
    }

    /**
     * 设置数据对象的值
     * @access public
     * @param string $name 名称
     * @param mixed $value 值
     * @return void
     */
    public function __set($name, $value) {
        // 设置数据对象属性
        $this->data[$name] = $value;
    }

    /**
     * 获取数据对象的值
     * @access public
     * @param string $name 名称
     * @return mixed
     */
    public function __get($name) {
        return isset($this->data[$name]) ? $this->data[$name] : null;
    }

    /**
     * 检测数据对象的值
     * @access public
     * @param string $name 名称
     * @return boolean
     */
    public function __isset($name) {
        return isset($this->data[$name]);
    }

    /**
     * 销毁数据对象的值
     * @access public
     * @param string $name 名称
     * @return void
     */
    public function __unset($name) {
        unset($this->data[$name]);
    }

    /**
     * 利用__call方法实现一些特殊的Model方法
     * @access public
     * @param string $method 方法名称
     * @param array $args 调用参数
     * @return mixed
     */
    public function __call($method, $args) {
        if (in_array(strtolower($method), $this->methods, true)) {
            // 连贯操作的实现
            $this->options[strtolower($method)] = $args[0];
            return $this;
        } elseif (in_array(strtolower($method), ['count', 'sum', 'min', 'max', 'avg'], true)) {
            // 统计查询的实现
            $field = isset($args[0]) ? $args[0] : '*';
            return $this->getField(strtoupper($method) . '(' . $field . ') AS tp_' . $method);
        } elseif (strtolower(substr($method, 0, 5)) == 'getby') {
            // 根据某个字段获取记录
            $field = \framework\core\Loader::parseName(substr($method, 5));
            $where[$field] = $args[0];
            return $this->where($where)->find();
        } elseif (strtolower(substr($method, 0, 10)) == 'getfieldby') {
            // 根据某个字段获取记录的某个值
            $name = \framework\core\Loader::parseName(substr($method, 10));
            $where[$name] = $args[0];
            return $this->where($where)->getField($args[1]);
        } elseif (isset($this->_scope[$method])) {// 命名范围的单独调用支持
            return $this->scope($method, $args[0]);
        } else {
            throw new Exception(__CLASS__ . ':' . $method . '方法不存在！', 500);
        }
    }

    /**
     * 对保存到数据库的数据进行处理
     * @access protected
     * @param mixed $data 要操作的数据
     * @return boolean
     */
    protected function _facade($data) {
        // 检查数据字段合法性
        if (!empty($this->fields)) {
            if (!empty($this->options['field'])) {
                $fields = $this->options['field'];
                unset($this->options['field']);
                if (is_string($fields)) {
                    $fields = explode(',', $fields);
                }
            } else {
                $fields = $this->fields;
            }
            foreach ($data as $key => $val) {
                if (!in_array($key, $fields, true)) {
                    if (!empty($this->options['strict'])) {
                        throw new Exception('非法数据对象！' . ':[' . $key . '=>' . $val . ']', 500);
                    }
                    unset($data[$key]);
                } elseif (is_scalar($val)) {
                    // 字段类型检查 和 强制转换
                    $this->_parseType($data, $key);
                }
            }
        }

        // 安全过滤
        if (!empty($this->options['filter'])) {
            $data = array_map($this->options['filter'], $data);
            unset($this->options['filter']);
        }

        return $data;
    }

    /**
     * 新增数据
     * @access public
     * @param mixed $data 数据
     * @param array $options 表达式
     * @param boolean $replace 是否replace
     * @return mixed
     */
    public function add($data = '', $options = [], $replace = false) {
        if (empty($data)) {
            // 没有传递数据，获取当前数据对象的值
            if (!empty($this->data)) {
                $data = $this->data;
                // 重置数据
                $this->data = [];
            } else {
                $this->error = '非法数据对象！';
                return false;
            }
        }
        // 数据处理
        $data = $this->_facade($data);
        // 分析表达式
        $options = $this->_parseOptions($options);

        // 写入数据到数据库
        $result = $this->db->insert($data, $options, $replace);
        if (false !== $result && is_numeric($result)) {
            $pk = $this->getPk();
            // 增加复合主键支持
            if (is_array($pk))
                return $result;
            $insertId = $this->getLastInsID();
            if ($insertId) {
                // 自增主键返回插入ID
                return $insertId;
            }
        }
        return $result;
    }

    /**
     * 保存数据
     * @access public
     * @param mixed $data 数据
     * @param array $options 表达式
     * @return boolean
     */
    public function save($data = '', $options = []) {
        if (empty($data)) {
            // 没有传递数据，获取当前数据对象的值
            if (!empty($this->data)) {
                $data = $this->data;
                // 重置数据
                $this->data = [];
            } else {
                $this->error = '非法数据对象！';
                return false;
            }
        }
        // 数据处理
        $data = $this->_facade($data);
        if (empty($data)) {
            // 没有数据则不执行
            $this->error = '非法数据对象！';
            return false;
        }
        // 分析表达式
        $options = $this->_parseOptions($options);
        $pk = $this->getPk();
        if (!isset($options['where'])) {
            // 如果存在主键数据 则自动作为更新条件
            if (is_string($pk) && isset($data[$pk])) {
                $where[$pk] = $data[$pk];
                unset($data[$pk]);
            } elseif (is_array($pk)) {
                // 增加复合主键支持
                foreach ($pk as $field) {
                    if (isset($data[$field])) {
                        $where[$field] = $data[$field];
                    } else {
                        // 如果缺少复合主键数据则不执行
                        $this->error = '操作出现错误';
                        return false;
                    }
                    unset($data[$field]);
                }
            }
            if (!isset($where)) {
                // 如果没有任何更新条件则不执行
                $this->error = '操作出现错误';
                return false;
            } else {
                $options['where'] = $where;
            }
        }

        if (is_array($options['where']) && isset($options['where'][$pk])) {
            $pkValue = $options['where'][$pk];
        }

        return $this->db->update($data, $options);
    }

    /**
     * 删除数据
     * @access public
     * @param mixed $options 表达式
     * @return mixed
     */
    public function delete($options = []) {
        $pk = $this->getPk();
        if (empty($options) && empty($this->options['where'])) {
            // 如果删除条件为空 则删除当前数据对象所对应的记录
            if (!empty($this->data) && isset($this->data[$pk])) {
                return $this->delete($this->data[$pk]);
            } else {
                return false;
            }
        }
        if (is_numeric($options) || is_string($options)) {
            // 根据主键删除记录
            if (strpos($options, ',')) {
                $where[$pk] = ['IN', $options];
            } else {
                $where[$pk] = $options;
            }
            $options = [];
            $options['where'] = $where;
        }
        // 根据复合主键删除记录
        if (is_array($options) && (count($options) > 0) && is_array($pk)) {
            $count = 0;
            foreach (array_keys($options) as $key) {
                if (is_int($key)) {
                    $count++;
                }
            }
            if ($count == count($pk)) {
                $i = 0;
                foreach ($pk as $field) {
                    $where[$field] = $options[$i];
                    unset($options[$i++]);
                }
                $options['where'] = $where;
            } else {
                return false;
            }
        }
        // 分析表达式
        $options = $this->_parseOptions($options);
        if (empty($options['where'])) {
            // 如果条件为空 不进行删除操作 除非设置 1=1
            return false;
        }
        if (is_array($options['where']) && isset($options['where'][$pk])) {
            $pkValue = $options['where'][$pk];
        }

        return $this->db->delete($options);
    }

    /**
     * 查询数据集
     * @access public
     * @param array $options 表达式参数
     * @return mixed
     */
    public function select($options = []) {
        $pk = $this->getPk();
        if (is_string($options) || is_numeric($options)) {
            // 根据主键查询
            if (strpos($options, ',')) {
                $where[$pk] = ['IN', $options];
            } else {
                $where[$pk] = $options;
            }
            $options = [];
            $options['where'] = $where;
        } elseif (is_array($options) && (count($options) > 0) && is_array($pk)) {
            // 根据复合主键查询
            $count = 0;
            foreach (array_keys($options) as $key) {
                if (is_int($key)) {
                    $count++;
                }
            }
            if ($count == count($pk)) {
                $i = 0;
                foreach ($pk as $field) {
                    $where[$field] = $options[$i];
                    unset($options[$i++]);
                }
                $options['where'] = $where;
            } else {
                return false;
            }
        } elseif (false === $options) { // 用于子查询 不查询只返回SQL
            $options = [];
            // 分析表达式
            $options = $this->_parseOptions($options);
            return '( ' . $this->fetchSql(true)->select($options) . ' )';
        }
        // 分析表达式
        $options = $this->_parseOptions($options);
        $resultSet = $this->db->select($options);
        if (false === $resultSet) {
            return false;
        }
        if (empty($resultSet)) { // 查询结果为空
            return null;
        }

        if (is_string($resultSet)) {
            return $resultSet;
        }

        $resultSet = array_map(array($this, '_read_data'), $resultSet);
        if (isset($options['index'])) { // 对数据集进行索引
            $index = explode(',', $options['index']);
            foreach ($resultSet as $result) {
                $_key = $result[$index[0]];
                if (isset($index[1]) && isset($result[$index[1]])) {
                    $cols[$_key] = $result[$index[1]];
                } else {
                    $cols[$_key] = $result;
                }
            }
            $resultSet = $cols;
        }
        return $resultSet;
    }

    /**
     * 分析表达式
     * @access protected
     * @param array $options 表达式参数
     * @return array
     */
    protected function _parseOptions($options = []) {
        if (is_array($options)) {
            $options = array_merge($this->options, $options);
        }

        if (!isset($options['table'])) {
            // 自动获取表名
            $options['table'] = $this->getTableName();
            $fields = $this->fields;
        } else {
            // 指定数据表 则重新获取字段列表 但不支持类型检测
            $fields = $this->getDbFields();
        }

        // 数据表别名
        if (!empty($options['alias'])) {
            $options['table'] .= ' ' . $options['alias'];
        }
        // 记录操作的模型名称
        $options['model'] = $this->name;

        // 字段类型验证
        if (isset($options['where']) && is_array($options['where']) && !empty($fields) && !isset($options['join'])) {
            // 对数组查询条件进行字段类型检查
            foreach ($options['where'] as $key => $val) {
                $key = trim($key);
                if (in_array($key, $fields, true)) {
                    if (is_scalar($val)) {
                        $this->_parseType($options['where'], $key);
                    }
                } elseif (!is_numeric($key) && '_' != substr($key, 0, 1) && false === strpos($key, '.') && false === strpos($key, '(') && false === strpos($key, '|') && false === strpos($key, '&')) {
                    if (!empty($this->options['strict'])) {
                        throw new Exception('错误的查询条件:[' . $key . '=>' . $val . ']', 500);
                    }
                    unset($options['where'][$key]);
                }
            }
        }
        // 查询过后清空sql表达式组装 避免影响下次查询
        $this->options = [];
        // 表达式过滤
        $this->_options_filter($options);
        return $options;
    }

    // 表达式过滤回调方法
    protected function _options_filter(&$options) {
        
    }

    /**
     * 数据类型检测
     * @access protected
     * @param mixed $data 数据
     * @param string $key 字段名
     * @return void
     */
    protected function _parseType(&$data, $key) {
        if (!isset($this->options['bind'][':' . $key]) && isset($this->fields['_type'][$key])) {
            $fieldType = strtolower($this->fields['_type'][$key]);
            if (false !== strpos($fieldType, 'enum')) {
                // 支持ENUM类型优先检测
            } elseif (false === strpos($fieldType, 'bigint') && false !== strpos($fieldType, 'int')) {
                $data[$key] = intval($data[$key]);
            } elseif (false !== strpos($fieldType, 'float') || false !== strpos($fieldType, 'double')) {
                $data[$key] = floatval($data[$key]);
            } elseif (false !== strpos($fieldType, 'bool')) {
                $data[$key] = (bool) $data[$key];
            }
        }
    }

    /**
     * 数据读取后的处理
     * @access protected
     * @param array $data 当前数据
     * @return array
     */
    protected function _read_data($data) {
        // 检查字段映射
        if (!empty($this->_map)) {
            foreach ($this->_map as $key => $val) {
                if (isset($data[$val])) {
                    $data[$key] = $data[$val];
                    unset($data[$val]);
                }
            }
        }
        return $data;
    }

    /**
     * 查询数据
     * @access public
     * @param mixed $options 表达式参数
     * @return mixed
     */
    public function find($options = []) {
        if (is_numeric($options) || is_string($options)) {
            $where[$this->getPk()] = $options;
            $options = [];
            $options['where'] = $where;
        }
        // 根据复合主键查找记录
        $pk = $this->getPk();
        if (is_array($options) && (count($options) > 0) && is_array($pk)) {
            // 根据复合主键查询
            $count = 0;
            foreach (array_keys($options) as $key) {
                if (is_int($key)) {
                    $count++;
                }
            }
            if ($count == count($pk)) {
                $i = 0;
                foreach ($pk as $field) {
                    $where[$field] = $options[$i];
                    unset($options[$i++]);
                }
                $options['where'] = $where;
            } else {
                return false;
            }
        }
        // 总是查找一条记录
        $options['limit'] = 1;
        // 分析表达式
        $options = $this->_parseOptions($options);
        $resultSet = $this->db->select($options);

        if (false === $resultSet) {
            return false;
        }
        if (empty($resultSet)) {// 查询结果为空
            return null;
        }
        if (is_string($resultSet)) {
            return $resultSet;
        }

        // 读取数据后的处理
        $data = $this->_read_data($resultSet[0]);
        $this->data = $data;
        return $this->data;
    }

    /**
     * 设置记录的某个字段值
     * 支持使用数据库字段和方法
     * @access public
     * @param string|array $field  字段名
     * @param string $value  字段值
     * @return boolean
     */
    public function setField($field, $value = '') {
        if (is_array($field)) {
            $data = $field;
        } else {
            $data[$field] = $value;
        }
        return $this->save($data);
    }

    /**
     * 字段值增长
     * @access public
     * @param string $field  字段名
     * @param integer $step  增长值
     * @param integer $lazyTime  延时时间(s)
     * @return boolean
     */
    public function setInc($field, $step = 1, $lazyTime = 0) {
        if ($lazyTime > 0) {// 延迟写入
            $condition = $this->options['where'];
            $guid = md5($this->name . '_' . $field . '_' . serialize($condition));
            $step = $this->lazyWrite($guid, $step, $lazyTime);
            if (empty($step)) {
                return true; // 等待下次写入
            } elseif ($step < 0) {
                $step = '-' . $step;
            }
        }
        return $this->setField($field, ['exp', $field . '+' . $step]);
    }

    /**
     * 字段值减少
     * @access public
     * @param string $field  字段名
     * @param integer $step  减少值
     * @param integer $lazyTime  延时时间(s)
     * @return boolean
     */
    public function setDec($field, $step = 1, $lazyTime = 0) {
        if ($lazyTime > 0) {// 延迟写入
            $condition = $this->options['where'];
            $guid = md5($this->name . '_' . $field . '_' . serialize($condition));
            $step = $this->lazyWrite($guid, -$step, $lazyTime);
            if (empty($step)) {
                return true; // 等待下次写入
            } elseif ($step > 0) {
                $step = '-' . $step;
            }
        }
        return $this->setField($field, ['exp', $field . '-' . $step]);
    }

    /**
     * 延时更新检查 返回false表示需要延时
     * 否则返回实际写入的数值
     * @access public
     * @param string $guid  写入标识
     * @param integer $step  写入步进值
     * @param integer $lazyTime  延时时间(s)
     * @return false|integer
     */
    protected function lazyWrite($guid, $step, $lazyTime) {
        $value = Redis::getInstance()->simple_get($guid);
        if (false !== $value) {
            // 存在缓存写入数据
            $lazy_time = Redis::getInstance()->simple_get($guid . '_time');
            $lazyTime = intval($lazy_time) + $lazyTime;
            if (time() > $lazyTime) {
                // 延时更新时间到了，删除缓存数据 并实际写入数据库
                Redis::getInstance()->simple_delete($guid);
                Redis::getInstance()->simple_delete($guid . '_time');

                return $value + $step;
            } else {
                // 追加数据到缓存
                Redis::getInstance()->simple_set($guid, ($value + $step), 1209600);

                return false;
            }
        } else {
            // 没有缓存数据
            Redis::getInstance()->simple_set($guid, $step, 1209600);
            // 计时开始
            Redis::getInstance()->simple_set($guid . '_time', time(), 1209600);

            return false;
        }
    }

    /**
     * 获取一条记录的某个字段值
     * @param       string      $field          字段名
     * @param       string      $sepa           字段数据间隔符号 NULL返回数组
     * @return      mix
     */
    public function getField($field, $sepa = null) {
        $options['field'] = $field;
        $options = $this->_parseOptions($options);
        $field = trim($field);
        if (strpos($field, ',') && false !== $sepa) { // 多字段
            if (!isset($options['limit'])) {
                $options['limit'] = is_numeric($sepa) ? $sepa : '';
            }
            $resultSet = $this->db->select($options);
            if (!empty($resultSet)) {
                $_field = explode(',', $field);
                $field = array_keys($resultSet[0]);
                $key1 = array_shift($field);
                $key2 = array_shift($field);
                $cols = [];
                $count = count($_field);
                foreach ($resultSet as $result) {
                    $name = $result[$key1];
                    if (2 == $count) {
                        $cols[$name] = $result[$key2];
                    } else {
                        $cols[$name] = is_string($sepa) ? implode($sepa, array_slice($result, 1)) : $result;
                    }
                }
                return $cols;
            }
        } else {   // 查找一条记录
            // 返回数据个数
            if (true !== $sepa) {// 当sepa指定为true的时候 返回所有数据
                $options['limit'] = is_numeric($sepa) ? $sepa : 1;
            }
            $result = $this->db->select($options);
            if (!empty($result)) {
                if (true !== $sepa && 1 == $options['limit']) {
                    $data = reset($result[0]);
                    return $data;
                }
                foreach ($result as $val) {
                    $array[] = $val[$field];
                }
                return $array;
            }
        }
        return null;
    }

    /**
     * SQL查询
     * @access public
     * @param string $sql  SQL指令
     * @return mixed
     */
    public function getAll($sql) {
        return $this->db->getAll($sql);
    }

    public function getOne($sql) {
        return $this->db->getOne($sql);
    }

    /**
     * 执行SQL语句
     * @access public
     * @param string $sql  SQL指令
     * @return false | integer
     */
    public function execute($sql) {
        return $this->db->execute($sql);
    }

    /**
     * SQL查询语句
     * @access public
     * @param string $sql  SQL指令
     * @return false | integer
     */
    public function query($sql) {
        return $this->db->query($sql);
    }

    /**
     * 得到完整的数据表名
     * @access public
     * @return string
     */
    public function getTableName() {
        return $this->name;
    }

    public function getdbName() {
        return $this->dbName;
    }

    /**
     * 启动事务
     * @access public
     * @return void
     */
    public function startTrans() {
        $this->commit();
        $this->db->startTrans();
        return;
    }

    /**
     * 提交事务
     * @access public
     * @return boolean
     */
    public function commit() {
        return $this->db->commit();
    }

    /**
     * 事务回滚
     * @access public
     * @return boolean
     */
    public function rollback() {
        return $this->db->rollback();
    }

    /**
     * 返回模型的错误信息
     * @access public
     * @return string
     */
    public function getError() {
        return $this->error;
    }

    /**
     * 返回数据库的错误信息
     * @access public
     * @return string
     */
    public function getDbError() {
        return $this->db->error();
    }

    /**
     * 返回最后插入的ID
     * @access public
     * @return string
     */
    public function getLastInsID() {
        return $this->db->getLastInsID();
    }

    /**
     * 返回最后执行的sql语句
     * @access public
     * @return string
     */
    public function getLastSql() {
        return $this->db->getLastSql($this->name);
    }

    // 鉴于getLastSql比较常用 增加_sql 别名
    public function _sql() {
        return $this->getLastSql();
    }

    /**
     * 获取主键名称
     * @access public
     * @return string
     */
    public function getPk() {
        return $this->pk;
    }

    /**
     * 获取数据表字段信息
     * @access public
     * @return array
     */
    public function getDbFields() {
        if ($this->fields) {
            $fields = $this->fields;
            unset($fields['_type'], $fields['_pk']);
            return $fields;
        } else {
            $table = $this->getTableName();
            $cache_id = 'getFields:' . $this->getdbName() . $table;
            $fields = Redis::getInstance()->simple_get($cache_id);
            if (empty($fields)) {
                $fields = $this->db->getFields($table);
                /* 表结构缓存 60 秒 */
                Redis::getInstance()->simple_set($cache_id, $fields, 60);
            }
            return $fields ? array_keys($fields) : false;
        }
        return false;
    }

    /**
     * 设置数据对象值
     * @access public
     * @param mixed $data 数据
     * @return Model
     */
    public function data($data = '') {
        if ('' === $data && !empty($this->data)) {
            return $this->data;
        }
        if (is_object($data)) {
            $data = get_object_vars($data);
        } elseif (is_string($data)) {
            parse_str($data, $data);
        } elseif (!is_array($data)) {
            throw new Exception('非法数据对象！', 500);
        }
        $this->data = $data;
        return $this;
    }

    /**
     * 指定当前的数据表
     * @access public
     * @param mixed $table
     * @return Model
     */
    public function table($table) {
        if (is_array($table)) {
            $this->options['table'] = $table;
        } elseif (!empty($table)) {
            $this->options['table'] = $table;
        }
        return $this;
    }

    /**
     * USING支持 用于多表删除
     * @access public
     * @param mixed $using
     * @return Model
     */
    public function using($using) {
        if (is_array($using)) {
            $this->options['using'] = $using;
        } elseif (!empty($using)) {
            $this->options['using'] = $using;
        }
        return $this;
    }

    /**
     * 查询SQL组装 join
     * @access public
     * @param mixed $join
     * @param string $type JOIN类型
     * @return Model
     */
    public function join($join, $type = 'INNER') {
        if (is_array($join)) {
            foreach ($join as $key => &$_join) {
                $_join = false !== stripos($_join, 'JOIN') ? $_join : $type . ' JOIN ' . $_join;
            }
            $this->options['join'] = $join;
        } elseif (!empty($join)) {
            $this->options['join'][] = false !== stripos($join, 'JOIN') ? $join : $type . ' JOIN ' . $join;
        }
        return $this;
    }

    /**
     * 查询SQL组装 union
     * @access public
     * @param mixed $union
     * @param boolean $all
     * @return Model
     */
    public function union($union, $all = false) {
        if (empty($union)){
            return $this;
        }
        if ($all) {
            $this->options['union']['_all'] = true;
        }
        if (is_object($union)) {
            $union = get_object_vars($union);
        }
        // 转换union表达式
        if (is_string($union)) {
            $options = $union;
        } elseif (is_array($union)) {
            if (isset($union[0])) {
                $this->options['union'] = array_merge($this->options['union'], $union);
                return $this;
            } else {
                $options = $union;
            }
        } else {
            throw new Exception('非法数据对象！', 500);
        }
        $this->options['union'][] = $options;
        return $this;
    }

    /**
     * 指定查询字段 支持字段排除
     * @access public
     * @param mixed $field
     * @param boolean $except 是否排除
     * @return Model
     */
    public function field($field, $except = false) {
        if (true === $field) {// 获取全部字段
            $fields = $this->getDbFields();
            $field = $fields ? implode(',', $fields) : "*";
        } elseif ($except) {// 字段排除
            if (is_string($field)) {
                $field = explode(',', $field);
            }
            $fields = $this->getDbFields();
            $field = $fields ? array_diff($fields, $field) : $field;
        }
        $this->options['field'] = $field;
        return $this;
    }

    /**
     * 调用命名范围
     * @access public
     * @param mixed $scope 命名范围名称 支持多个 和直接定义
     * @param array $args 参数
     * @return Model
     */
    public function scope($scope = '', $args = NULL) {
        if ('' === $scope) {
            if (isset($this->_scope['default'])) {
                // 默认的命名范围
                $options = $this->_scope['default'];
            } else {
                return $this;
            }
        } elseif (is_string($scope)) { // 支持多个命名范围调用 用逗号分割
            $scopes = explode(',', $scope);
            $options = [];
            foreach ($scopes as $name) {
                if (!isset($this->_scope[$name])) {
                    continue;
                }
                $options = array_merge($options, $this->_scope[$name]);
            }
            if (!empty($args) && is_array($args)) {
                $options = array_merge($options, $args);
            }
        } elseif (is_array($scope)) { // 直接传入命名范围定义
            $options = $scope;
        }

        if (is_array($options) && !empty($options)) {
            $this->options = array_merge($this->options, array_change_key_case($options));
        }
        return $this;
    }

    /**
     * 指定查询条件 支持安全过滤
     * @access public
     * @param mixed $where 条件表达式
     * @param mixed $parse 预处理参数
     * @return Model
     */
    public function where($where, $parse = null) {
        if (!is_null($parse) && is_string($where)) {
            if (!is_array($parse)) {
                $parse = func_get_args();
                array_shift($parse);
            }
            $parse = array_map("addslashes", $parse);
            $where = vsprintf($where, $parse);
        } elseif (is_object($where)) {
            $where = get_object_vars($where);
        }
        if (is_string($where) && '' != $where) {
            $map = [];
            $map['_string'] = $where;
            $where = $map;
        }
        if (isset($this->options['where'])) {
            $this->options['where'] = array_merge($this->options['where'], $where);
        } else {
            $this->options['where'] = $where;
        }

        return $this;
    }

    /**
     * 指定查询数量
     * @access public
     * @param mixed $offset 起始位置
     * @param mixed $length 查询数量
     * @return Model
     */
    public function limit($offset, $length = null) {
        if (is_null($length) && strpos($offset, ',')) {
            list($offset, $length) = explode(',', $offset);
        }
        $this->options['limit'] = intval($offset) . ( $length ? ',' . intval($length) : '' );
        return $this;
    }

    /**
     * 指定分页
     * @access public
     * @param mixed $page 页数
     * @param mixed $listRows 每页数量
     * @return Model
     */
    public function page($page, $listRows = null) {
        if (is_null($listRows) && strpos($page, ',')) {
            list($page, $listRows) = explode(',', $page);
        }
        $this->options['page'] = array(intval($page), intval($listRows));
        return $this;
    }

    /**
     * 查询注释
     * @access public
     * @param string $comment 注释
     * @return Model
     */
    public function comment($comment) {
        $this->options['comment'] = $comment;
        return $this;
    }

    /**
     * 获取执行的SQL语句
     * @access public
     * @param boolean $fetch 是否返回sql
     * @return Model
     */
    public function fetchSql($fetch = true) {
        $this->options['fetch_sql'] = $fetch;
        return $this;
    }

    /**
     * 参数绑定
     * @access public
     * @param string $key  参数名
     * @param mixed $value  绑定的变量及绑定参数
     * @return Model
     */
    public function bind($key, $value = false) {
        if (is_array($key)) {
            $this->options['bind'] = $key;
        } else {
            $num = func_num_args();
            if ($num > 2) {
                $params = func_get_args();
                array_shift($params);
                $this->options['bind'][$key] = $params;
            } else {
                $this->options['bind'][$key] = $value;
            }
        }
        return $this;
    }

    /**
     * 设置模型的属性值
     * @access public
     * @param string $name 名称
     * @param mixed $value 值
     * @return Model
     */
    public function setProperty($name, $value) {
        if (property_exists($this, $name)) {
            $this->$name = $value;
        }
        return $this;
    }

}
