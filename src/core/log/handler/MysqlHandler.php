<?php

namespace framework\core\log\handler;

/**
 * 日志存储处理
 */
class MysqlHandler implements Handler {

    private $db;

    public function __construct() {
        $this->db = \framework\db\Model\MYSQLModel::getInstance('web_log', 'logs_db');
    }

    public function write(array $messages = []) {
        $this->db->startTrans();
        foreach ($messages as $level => $val) {
            foreach ($val as $key => $value) {
                $data = [
                    'level' => $level,
                    'message' => $value,
                    'ctime' => date('Y-m-d H:i:s'),
                ];
                $this->db->add($data);
            }
        }
        $this->db->commit();
    }

}
