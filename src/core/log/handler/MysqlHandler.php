<?php

namespace framework\core\log\handler;

use framework\core\Config;

/**
 * 日志存储处理
 *
 *
  CREATE TABLE IF NOT EXISTS `web_error_log` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `level` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `ctime` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='网站出错日志';
 *
 */
class MysqlHandler implements Handler {

    private $db;

    public function __construct() {
        $db_name = Config::getInstance()->get('logs_db_name', 'logs_db');
        $table_name = Config::getInstance()->get('logs_table_name', 'web_error_log');

        $this->db = \framework\db\Model\MYSQLModel::getInstance($table_name, $db_name);
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
