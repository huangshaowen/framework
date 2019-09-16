<?php

namespace framework\db\Driver;

/**
 * Pgsql数据库驱动
 * 修正 thinkphp 在 pgsql 下无法获取 lastInsertId 
 */
class PGSQL extends DbDriver {

    // 数据库表达式
    protected $exp = ['eq' => '=', 'neq' => '<>', 'gt' => '>', 'egt' => '>=', 'lt' => '<', 'elt' => '<=', 'notlike' => 'NOT LIKE', 'like' => 'LIKE', 'in' => 'IN', 'notin' => 'NOT IN', 'not in' => 'NOT IN', 'between' => 'BETWEEN', 'not between' => 'NOT BETWEEN', 'notbetween' => 'NOT BETWEEN'];
    // 查询表达式
    protected $selectSql = 'SELECT%DISTINCT% %FIELD% FROM %TABLE%%FORCE%%JOIN%%WHERE%%GROUP%%HAVING%%ORDER%%LIMIT% %UNION%%LOCK%%COMMENT%';
    // PDO连接参数
    protected $options = [
        \PDO::ATTR_CASE => \PDO::CASE_LOWER,
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_ORACLE_NULLS => \PDO::NULL_NATURAL,
        \PDO::ATTR_STRINGIFY_FETCHES => false,
    ];

    public static function getInstance($db = 'pgsql') {
        static $obj = [];
        if (!isset($obj[$db])) {
            $obj[$db] = new self($db);
        }
        return $obj[$db];
    }

    /**
     * 取得数据表的字段信息
     * @access public
     * @return array
     */
    public function getFields($tableName) {
        list($tableName) = explode(' ', $tableName);
        $result = $this->query('select fields_name as "field",fields_type as "type",fields_not_null as "null",fields_key_name as "key",fields_default as "default",fields_default as "extra" from table_msg(' . $tableName . ');');
        $info = [];
        if ($result) {
            foreach ($result as $key => $val) {
                $info[$val['field']] = [
                    'name' => $val['field'],
                    'type' => $val['type'],
                    'notnull' => (bool) ('' === $val['null']), // not null is empty, null is yes
                    'default' => $val['default'],
                    'primary' => (strtolower($val['key']) == 'pri'),
                    'autoinc' => (strtolower($val['extra']) == 'auto_increment'),
                ];
            }
        }
        return $info;
    }

    /**
     * 取得数据库的表信息
     * @access public
     * @return array
     */
    public function getTables($dbName = '') {
        $result = $this->query("select tablename as Tables_in_test from pg_tables where  schemaname ='public'");
        $info = [];
        foreach ($result as $key => $val) {
            $info[$key] = current($val);
        }
        return $info;
    }

    /**
     * limit分析
     * @access protected
     * @param mixed $lmit
     * @return string
     */
    public function parseLimit($limit) {
        $limitStr = '';
        if (!empty($limit)) {
            $limit = explode(',', $limit);
            if (count($limit) > 1) {
                $limitStr .= ' LIMIT ' . $limit[1] . ' OFFSET ' . $limit[0] . ' ';
            } else {
                $limitStr .= ' LIMIT ' . $limit[0] . ' ';
            }
        }
        return $limitStr;
    }

    /**
     * 字段和表名处理
     * @access protected
     * @param string $key
     * @return string
     */
    protected function parseKey(&$key) {
        $key = trim($key);
        if (strpos($key, '$.') && false === strpos($key, '(')) {
            // JSON字段支持
            list($field, $name) = explode($key, '$.');
            $key = $field . '->>\'' . $name . '\'';
        }
        return $key;
    }

    /**
     * 随机排序
     * @access protected
     * @return string
     */
    protected function parseRand() {
        return 'RANDOM()';
    }

    /**
     * order分析
     * @access protected
     * @param mixed $order
     * @return string
     */
    protected function parseOrder($order) {
        return !empty($order) ? ' ORDER BY ' . $order : '';
    }

    /**
     * 执行语句
     * @access public
     * @param string $sql  sql指令
     * @param boolean $fetchSql  不执行只是获取SQL
     * @return mixed
     */
    public function execute($sql, $fetchSql = false) {
        if (!$this->link) {
            $this->__connect();
        }
        $this->queryString = $sql;
        if (!empty($this->bind)) {
            $that = $this;
            $this->queryString = strtr($this->queryString, array_map(function($val) use($that) {
                        return '\'' . $that->escapeString($val) . '\'';
                    }, $this->bind));
        }
        if ($fetchSql) {
            return $this->queryString;
        }
        //释放前次的查询结果
        if (!empty($this->PDOStatement)) {
            $this->free();
        }
        $this->executeTimes++;

        $this->PDOStatement = $this->link->prepare($sql);
        if (false === $this->PDOStatement) {
            $this->error();
            return false;
        }

        foreach ($this->bind as $key => $val) {
            if (is_array($val)) {
                $this->PDOStatement->bindValue($key, $val[0], $val[1]);
            } else {
                $this->PDOStatement->bindValue($key, $val);
            }
        }
        $this->bind = array();

        try {
            $result = $this->PDOStatement->execute();
            if (false === $result) {
                $this->error();
                return false;
            } else {
                $this->numRows = $this->PDOStatement->rowCount();
                if (preg_match("/^\s*(INSERT\s+INTO|REPLACE\s+INTO)\s+/i", $this->queryString)) {

                    if (preg_match("/^\s*(INSERT\s+INTO)\s+(\w+)\s+/i", $this->queryString, $match)) {
                        $this->table = $match [2];
                    }

                    $sequenceName = $this->getSequenceName();
                    if (empty($sequenceName)) {
                        // PostgreSQL里，创建表时，如果使用serial类型，默认生成的自增序列名为：'表名' + '_' + '字段名' + '_' + 'seq'
                        $sequenceName = $this->table . '_id_seq';
                        $this->setSequenceName($sequenceName);
                    }

                    $this->lastInsID = $this->link->lastInsertId($sequenceName);
                }
                return $this->numRows;
            }
        } catch (\PDOException $e) {
            $this->error();
            return false;
        }
    }

}
