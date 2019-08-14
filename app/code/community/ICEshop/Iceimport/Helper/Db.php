<?php

/**
 * Class ICEshop_Iceimport_Helper_Db
 */
class ICEshop_Iceimport_Helper_Db extends Mage_Core_Helper_Abstract
{

    /**
     * @var object
     */
    private $_resource;

    /**
     * @var object
     */
    private $_reader;

    /**
     * @var object
     */
    private $_writer;

    /**
     * @var string
     */
    public $_prefix = '';

    /**
     * int
     */
    const LOG_ROWS_LIMIT = 500;

    /**
     * __construct
     */
    public function __construct()
    {
        try {
            $this->_resource = Mage::getSingleton('core/resource');
            $this->_writer = $this->_resource->getConnection('core_write');
            $this->_reader = $this->_resource->getConnection('core_read');
            $prefix = Mage::getConfig()->getTablePrefix();
            if (!empty($prefix[0])) {
                $this->_prefix = $prefix[0];
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * @param $table_name
     * @param bool|array $conditions
     * @return mixed
     */
    public function getRowsCount($table_name, $conditions = false)
    {
        $sql = "SELECT COUNT(*) AS `row_count` FROM `{$table_name}`";
        if (!empty($conditions) && is_array($conditions)) {
            $sql .= ' WHERE ';
            foreach ($conditions as $key => $condition) {
                if (is_array($condition)) {
                    if ($key > 0) {
                        $sql .= ' ' . $condition['conjunction'] . ' ';
                    }
                    $sql .= $condition['field'] . ' ' . $condition['comparison'] . ' ';
                    switch ($condition['value_type']) {
                        case 'num':
                            $sql .= $condition['value'];
                            break;
                        case 'str':
                            $sql .= '\'' . $condition['value'] . '\'';
                            break;
                    }
                } elseif (is_string($condition)) {
                    $sql .= $condition;
                }
            }

        } elseif (!empty($conditions) && is_string($conditions)) {
            $sql .= $conditions;
        }
        $result = $this->_reader->fetchAll($sql);
        $result = array_shift($result);
        return $result['row_count'];
    }

    /**
     * @param $table_name
     * @param $field_name
     * @param bool|string $before_group
     * @param bool|string $after_group
     * @return mixed
     */
    public function getRowCountByField($table_name, $field_name, $before_group = false, $after_group = false)
    {
        $approved_before_group = '';
        if ($before_group != false && is_string($before_group)) {
            $approved_before_group = $before_group;
        }
        $approved_after_group = '';
        if ($after_group != false && is_string($after_group)) {
            $approved_after_group = $after_group;
        }
        // select is_default, count(is_default) from icecat_products_images group by is_default;
        $sql = "SELECT `{$field_name}`, COUNT(`{$field_name}`) as `row_count` FROM `{$table_name}`{$approved_before_group} GROUP BY `{$field_name}`{$approved_after_group}";
        $result = $this->_reader->fetchAll($sql);
        return $result;
    }

    /**
     * @param $table_name
     * @param $field_name
     * @return bool
     */
    public function checkIsFieldExists($table_name, $field_name)
    {
        if (!$this->_checkProcedureExists('FIELD_EXISTS')) {
            //recreate the procedure FIELD_EXISTS
            $this->_recreateFieldExistsProcedure();
        }
        $sql = "CALL FIELD_EXISTS(@_exists, '{$table_name}', '{$field_name}', NULL);";
        $this->_reader->query($sql);

        $sql = "SELECT @_exists;";
        $res = $this->_reader->fetchCol($sql);
        if (!array_shift($res)) {
            //field exists
            return false;
        }
        return true;
    }

    /**
     * @param $procedure_name
     * @return bool
     */
    private function _checkProcedureExists($procedure_name)
    {
        $sql = "SET @_exists = (SELECT COUNT(ROUTINE_NAME) FROM INFORMATION_SCHEMA.ROUTINES WHERE ROUTINE_TYPE = 'PROCEDURE' AND ROUTINE_SCHEMA = database() AND ROUTINE_NAME = '{$procedure_name}')";
        $this->_reader->query($sql);

        $sql = "SELECT @_exists;";
        $res = $this->_reader->fetchCol($sql);
        $res = array_shift($res);
        if ($res > 0) {
            //field exists
            return true;
        }
        return false;
    }

    /**
     *
     */
    private function _recreateFieldExistsProcedure()
    {
        $sql = "CREATE PROCEDURE FIELD_EXISTS(
    OUT _exists    BOOLEAN, -- return value
    IN  tableName  CHAR(255), -- name of table to look for
    IN  columnName CHAR(255), -- name of column to look for
    IN  dbName     CHAR(255)       -- optional specific db
) BEGIN
-- try to lookup db if none provided
    SET @_dbName := IF(dbName IS NULL, database(), dbName);

    IF CHAR_LENGTH(@_dbName) = 0
    THEN -- no specific or current db to check against
        SELECT
            FALSE
        INTO _exists;
    ELSE -- we have a db to work with
        SELECT
            IF(count(*) > 0, TRUE, FALSE)
        INTO _exists
        FROM information_schema.COLUMNS c
        WHERE
            c.TABLE_SCHEMA = @_dbName
            AND c.TABLE_NAME = tableName
            AND c.COLUMN_NAME = columnName;
    END IF;
END;";
        $this->_writer->query($sql);
    }

    /**
     * @param $sql
     * @return bool
     */
    public function readQuery($sql)
    {
        if (!empty($sql) && is_string($sql)) {
            return $this->_reader->fetchAll($sql);
        }
        return false;
    }

    /**
     * @param $key
     * @param $value
     * @param string $type
     * @return bool
     */
    public function insertLogEntry($key, $value, $type = 'info')
    {
        if ($type == 'error') {
            $this->rotateLog();
        }
        if (!empty($key)) {
            return $this->_writer->query("INSERT INTO `{$this->_prefix}iceshop_extensions_logs` (`log_key`, `log_value`, `log_type`)
                                            VALUES ('{$key}', '{$value}', '{$type}')
                                            ON DUPLICATE KEY UPDATE `log_value` = VALUES(`log_value`), `log_type` = VALUES(`log_type`)");
        }
        return false;
    }

    /**
     * @param string $log_type
     * @return bool
     */
    public function rotateLog($log_type = 'error')
    {
        $sql = "SELECT COUNT(*) AS row_amount
                        FROM `{$this->_prefix}iceshop_extensions_logs`
                        WHERE `log_type` = '{$log_type}';";
        $value = $this->_reader->fetchAll($sql);
        $row_amount = 0;
        if (array_key_exists(0, $value)) {
            $row_amount = $value[0]['row_amount'];
        }
        if ($row_amount > self::LOG_ROWS_LIMIT) {
            $rows_to_delete = $row_amount - self::LOG_ROWS_LIMIT;
            return $this->_writer->query("DELETE
                                            FROM `{$this->_prefix}iceshop_extensions_logs`
                                            WHERE `log_type` = '{$log_type}'
                                                ORDER BY `log_id` ASC
                                                LIMIT {$rows_to_delete}");
        }
        return false;
    }

    /**
     * @param $key
     * @return bool
     */
    public function getLogEntryByKey($key)
    {
        if (!empty($key)) {
            $sql = "SELECT `log_value`, `log_type`
                        FROM `{$this->_prefix}iceshop_extensions_logs`
                        WHERE `log_key` = '{$key}'
                            ORDER BY `log_id` DESC
                            LIMIT 1;";
            $value = $this->_reader->fetchAll($sql);
            if (array_key_exists(0, $value)) {
                return $value[0];
            }
        }
        return false;
    }

    /**
     * @param bool $type
     * @param string $comparison
     * @param int $limit
     * @return array|bool
     */
    public function getLogByType($type = false, $comparison = 'eq', $limit = 10)
    {
        $comparison_whitelist = array('eq', 'neq');
        if (!in_array($comparison, $comparison_whitelist)) {
            $comparison = 'eq';
        }
        if (!empty($type)) {
            if ($type == 'report') {
                $result_arr = array();
                $sql = "SELECT `log_value`, `timecol`
                        FROM `{$this->_prefix}iceshop_extensions_logs`
                        WHERE `log_type` = 'error'
                            AND (`timecol` > DATE_SUB(now(), INTERVAL 1 DAY))
                            ORDER BY `log_id` DESC;";
                $results = $this->_reader->fetchAll($sql);
                foreach ($results as $row) {
                    $result_arr[] = $row;
                }
                return (!empty($result_arr)) ? $result_arr : false;
            } else {
                switch($comparison) {
                    case 'eq':
                        $result_arr = array();
                        $sql = "SELECT `log_value`, `timecol`
                                    FROM `{$this->_prefix}iceshop_extensions_logs`
                                    WHERE `log_type` = '{$type}'
                                        ORDER BY `log_id` DESC
                                        LIMIT {$limit};";
                        $results = $this->_reader->fetchAll($sql);
                        foreach ($results as $row) {
                            $result_arr[] = $row;
                        }
                        return (!empty($result_arr)) ? $result_arr : false;
                        break;

                    case 'neq':
                        $result_arr = array();
                        $sql = "SELECT `log_value`, `timecol`
                                    FROM `{$this->_prefix}iceshop_extensions_logs`
                                    WHERE `log_type` <> '{$type}'
                                        ORDER BY `log_id` DESC
                                        LIMIT {$limit};";
                        $results = $this->_reader->fetchAll($sql);
                        foreach ($results as $row) {
                            $result_arr[] = $row;
                        }
                        return (!empty($result_arr)) ? $result_arr : false;
                        break;
                }
            }
        } else {
            $sql = "SELECT `log_value`, `log_type`, `timecol`
                        FROM `{$this->_prefix}iceshop_extensions_logs`
                            ORDER BY `log_id` DESC
                            LIMIT {$limit};";
            return $this->_reader->fetchAll($sql);
        }
    }

    /**
     * @param $key
     * @return bool
     */
    public function deleteLogEntry($key)
    {
        if (!empty($key)) {
            return $this->_writer->query("DELETE
                                            FROM `{$this->_prefix}iceshop_extensions_logs`
                                            WHERE `log_key` = '{$key}';");
        }
        return false;
    }

    /**
     * @param $table_name
     * @return bool
     */
    public function getTableName($table_name)
    {
        if (!empty($table_name) && is_string($table_name)) {
            return $this->_resource->getTableName($table_name);
        }
        return false;
    }
}