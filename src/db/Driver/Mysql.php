<?php

namespace framework\db\Driver;

use framework\core\Exception;

/**
 * mysql数据库驱动
 */
class Mysql extends DbDriver {

    public static function getInstance($db = 'mysql') {
        static $obj = [];
        if (!isset($obj[$db])) {
            $obj[$db] = new self($db);
        }
        return $obj[$db];
    }

    /**
     * 创建数据库链接
     * 只有在需要创建时才创建，最小化调用
     *
     * @return resource 返回link_source
     */
    public function __connect() {
        try {
            $this->link = new \PDO($this->db_config['dsn'], $this->db_config['username'], $this->db_config['password'], [\PDO::ATTR_CASE => \PDO::CASE_NATURAL]);
            $this->link->exec('SET QUOTED_IDENTIFIER ON');
//            $this->link->exec('SET NAMES utf8mb4');
            $this->link->exec("SET ANSI_WARNINGS ON");
            $this->link->exec("SET ANSI_PADDING ON");
            $this->link->exec("SET ANSI_NULLS ON");
            $this->link->exec("SET CONCAT_NULL_YIELDS_NULL ON");
        } catch (\PDOException $e) {
            throw new Exception('连接数据库服务器失败:' . $e->getMessage(), 500);
        }
    }

    /**
     * 取得数据表的字段信息
     * @access public
     */
    public function getFields($tableName) {
        list($tableName) = explode(' ', $tableName);
        if (strpos($tableName, '.')) {
            list($dbName, $tableName) = explode('.', $tableName);
            $sql = 'SHOW COLUMNS FROM `' . $dbName . '`.`' . $tableName . '`';
        } else {
            $sql = 'SHOW COLUMNS FROM `' . $tableName . '`';
        }

        $result = $this->query($sql);
        $info = array();
        if ($result) {
            foreach ($result as $key => $val) {
                if (\PDO::CASE_LOWER != $this->link->getAttribute(\PDO::ATTR_CASE)) {
                    $val = array_change_key_case($val, CASE_LOWER);
                }
                $info[$val['field']] = array(
                    'name' => $val['field'],
                    'type' => $val['type'],
                    'notnull' => (bool) ($val['null'] === ''), // not null is empty, null is yes
                    'default' => $val['default'],
                    'primary' => (strtolower($val['key']) == 'pri'),
                    'autoinc' => (strtolower($val['extra']) == 'auto_increment'),
                );
            }
        }
        return $info;
    }

    /**
     * 取得数据库的表信息
     * @access public
     */
    public function getTables($dbName = '') {
        $sql = !empty($dbName) ? 'SHOW TABLES FROM ' . $dbName : 'SHOW TABLES ';
        $result = $this->query($sql);
        $info = array();
        foreach ($result as $key => $val) {
            $info[$key] = current($val);
        }
        return $info;
    }

    /**
     * 字段和表名处理
     * @access protected
     * @param string $key
     * @return string
     */
    protected function parseKey(&$key) {
        $key = trim($key);
        if (!is_numeric($key) && !preg_match('/[,\'\"\*\(\)`.\s]/', $key)) {
            $key = '`' . $key . '`';
        }
        return $key;
    }

    /**
     * 批量插入记录
     * @access public
     * @param mixed $dataSet 数据集
     * @param array $options 参数表达式
     * @param boolean $replace 是否replace
     * @return false | integer
     */
    public function insertAll($dataSet, $options = array(), $replace = false) {
        $values = [];
        $this->model = $options['model'];
        if (!is_array($dataSet[0])) {
            return false;
        }
        $this->parseBind(!empty($options['bind']) ? $options['bind'] : array());
        $fields = array_map(array($this, 'parseKey'), array_keys($dataSet[0]));
        foreach ($dataSet as $data) {
            $value = array();
            foreach ($data as $key => $val) {
                if (is_array($val) && 'exp' == $val[0]) {
                    $value[] = $val[1];
                } elseif (is_null($val)) {
                    $value[] = 'NULL';
                } elseif (is_scalar($val)) {
                    if (0 === strpos($val, ':') && in_array($val, array_keys($this->bind))) {
                        $value[] = $this->parseValue($val);
                    } else {
                        $name = count($this->bind);
                        $value[] = ':' . $name;
                        $this->bindParam($name, $val);
                    }
                }
            }
            $values[] = '(' . implode(',', $value) . ')';
        }
        // 兼容数字传入方式
        $replace = (is_numeric($replace) && $replace > 0) ? true : $replace;
        $sql = (true === $replace ? 'REPLACE' : 'INSERT') . ' INTO ' . $this->parseTable($options['table']) . ' (' . implode(',', $fields) . ') VALUES ' . implode(',', $values) . $this->parseDuplicate($replace);
        $sql .= $this->parseComment(!empty($options['comment']) ? $options['comment'] : '');
        return $this->execute($sql, !empty($options['fetch_sql']) ? true : false);
    }

    /**
     * ON DUPLICATE KEY UPDATE 分析
     * @access protected
     * @param mixed $duplicate
     * @return string
     */
    protected function parseDuplicate($duplicate) {
        // 布尔值或空则返回空字符串
        if (is_bool($duplicate) || empty($duplicate)) {
            return '';
        }
        if (is_string($duplicate)) {
            // field1,field2 转数组
            $duplicate = explode(',', $duplicate);
        } elseif (is_object($duplicate)) {
            // 对象转数组
            $duplicate = get_class_vars($duplicate);
        }
        $updates = array();
        foreach ((array) $duplicate as $key => $val) {
            if (is_numeric($key)) { // array('field1', 'field2', 'field3') 解析为 ON DUPLICATE KEY UPDATE field1=VALUES(field1), field2=VALUES(field2), field3=VALUES(field3)
                $updates[] = $this->parseKey($val) . "=VALUES(" . $this->parseKey($val) . ")";
            } else {
                if (is_scalar($val)) // 兼容标量传值方式
                    $val = array('value', $val);
                if (!isset($val[1]))
                    continue;
                switch ($val[0]) {
                    case 'exp': // 表达式
                        $updates[] = $this->parseKey($key) . "=($val[1])";
                        break;
                    case 'value': // 值
                    default:
                        $name = count($this->bind);
                        $updates[] = $this->parseKey($key) . "=:" . $name;
                        $this->bindParam($name, $val[1]);
                        break;
                }
            }
        }
        if (empty($updates)) {
            return '';
        }
        return " ON DUPLICATE KEY UPDATE " . join(', ', $updates);
    }

}
