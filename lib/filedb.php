<?php

declare(strict_types=1);

namespace FileDatabase;

use RuntimeException;

/**
 * Non-sql database based on file system.
 *
 * @author Mihajlo Siljanoski
 * @url https://mk.linkedin.com/in/msiljanoski
 * @email mihajlo.siljanoski@gmail.com
 * @skype mihajlo.siljanoski
 */
class FileDB {
    /**
     * @var string
     */
    private $db;

    /**
     * @var string
     */
    private $path;

    /**
     * Initialise the database and create the required directories if they do not yet exist.
     *
     * @param string $database
     * @param string $path
     */
    public function __construct($database, $path = 'databases') {
        $this->db = $database;
        $this->path = rtrim($path, DIRECTORY_SEPARATOR);

        $this->ensureDirectoryExists($this->path);
        $this->ensureDirectoryExists($this->getDatabasePath());

        $htAccessPath = $this->path . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($htAccessPath)) {
            file_put_contents($htAccessPath, 'Deny from all');
        }
    }

    /**
     * Create a table if it does not already exist.
     *
     * @param string $tablename
     * @return bool
     */
    public function create_table($tablename = false) {
        if (!$tablename) {
            return false;
        }

        $tableDirectory = $this->getTablePath($tablename);

        if (!is_dir($tableDirectory)) {
            $this->ensureDirectoryExists($tableDirectory);
            file_put_contents($this->getTableSchemePath($tablename), '1');
            return true;
        }

        return false;
    }
    
    public function insert($table = false, $data = array(), $is_object = false) {

        $this->create_table($table);

        if (!$table || !$data || count($data) < 1) {
            return false;
        }

        $tableDirectory = $this->getTablePath($table);

        if (!is_dir($tableDirectory)) {
            return array();
        }

        $schemePath = $this->getTableSchemePath($table);
        $currentId = (int) $this->readFileContents($schemePath, '1');
        $record = array_merge(array('_id' => $currentId), $data);

        $recordPath = $tableDirectory . DIRECTORY_SEPARATOR . $currentId;
        $encodedRecord = json_encode($record);

        if ($encodedRecord === false) {
            return false;
        }

        if (file_put_contents($recordPath, $encodedRecord) === false) {
            return false;
        }

        file_put_contents($schemePath, $currentId + 1);

        if ($is_object) {
            return (object) $record;
        }

        return $record;
    }

    public function update($table = false, $data = array(), $where = array()) {

        if (!$table || !$data) {
            return false;
        }
        $results = $this->get($table, $where);

        foreach ($results as $item) {
            $newdata = $item;
            foreach ($data as $k => $v) {
                if ($k != '_id') {
                    if ($v) {
                        $newdata[$k] = $v;
                    } else {
                        unset($newdata[$k]);
                    }
                }
            }

            $encodedRecord = json_encode($newdata);
            if ($encodedRecord === false) {
                continue;
            }

            file_put_contents($this->getTablePath($table) . DIRECTORY_SEPARATOR . $newdata['_id'], $encodedRecord);
        }
        return true;
    }


    public function delete($table = false, $where = array()) {

        if (!$table || !$where) {
            return false;
        }
        $results = $this->get($table, $where);

        foreach ($results as $item) {
            $recordPath = $this->getTablePath($table) . DIRECTORY_SEPARATOR . $item['_id'];
            if (file_exists($recordPath)) {
                unlink($recordPath);
            }
        }
        return true;
    }


    public function drop_table($table = false) {
        if (!$table) {
            return false;
        }

        $this->rrmdir($this->getTablePath($table));
        $schemePath = $this->getTableSchemePath($table);
        if (file_exists($schemePath)) {
            unlink($schemePath);
        }
        return true;
    }

    public function drop_database() {
        $this->rrmdir($this->getDatabasePath());
        return true;
    }

    public function get($table = false, $where = false, $join = false) {
        if (!$table) {
            return array();
        }

        $tableDirectory = $this->getTablePath($table);
        if (!is_dir($tableDirectory)) {
            return array();
        }

        if (@$where['_id'] && count(@$where) == 1) {
            $result = $this->readJsonFile($tableDirectory . DIRECTORY_SEPARATOR . @$where['_id']);
            if ($result === null) {
                return array();
            }

            if ($join) {
                $result = $this->applyJoin($result, $join);
            }
            return array($result);
        }


        $returnArr = array();
        $scanDir = scandir($tableDirectory);
        if ($scanDir === false) {
            return $returnArr;
        }

        sort($scanDir);
        foreach ($scanDir as $record) {
            if ($record === '.' || $record === '..') {
                continue;
            }

            $recordData = $this->readJsonFile($tableDirectory . DIRECTORY_SEPARATOR . $record);
            if (!is_array($recordData)) {
                continue;
            }

            if (is_array($where) && !$this->matchesWhere($recordData, $where)) {
                continue;
            }

            if ($join) {
                $recordData = $this->applyJoin($recordData, $join);
            }

            $returnArr[] = $recordData;
        }

        return $returnArr;

    }

    public function getOne($table = false, $where = false, $join = false) {
        $results = $this->get($table, $where, $join);
        if (count($results) === 0) {
            return null;
        }

        return $results[0];
    }

    public function listTables() {
        $databasePath = $this->getDatabasePath();
        if (!is_dir($databasePath)) {
            return array();
        }

        $entries = scandir($databasePath);
        if ($entries === false) {
            return array();
        }

        $tables = array();
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (is_dir($databasePath . DIRECTORY_SEPARATOR . $entry)) {
                $tables[] = $entry;
            }
        }

        sort($tables);

        return array_values($tables);
    }


    public function save($partition = false, $key = false, $data = array()) {

        if (!$partition || !$key) {
            return false;
        }

        $partitionDirectory = $this->ensureStoragePartition($partition);

        $recordPath = $partitionDirectory . DIRECTORY_SEPARATOR . $key;

        if (file_exists($recordPath)) {
            $tmpData = $this->readJsonFile($recordPath);
            if (is_array($tmpData)) {
                foreach ($tmpData as $k => $v) {
                    if (!array_key_exists($k, $data)) {
                        $data[$k] = $v;
                    }
                }
            }
        }

        $encoded = json_encode($data);
        if ($encoded === false) {
            return false;
        }

        if (file_put_contents($recordPath, $encoded) === false) {
            return false;
        }
        return true;
    }
    
    public function read($partition = false, $key = false) {
        if (!$partition) {
            return false;
        }

        $partitionDirectory = $this->getStoragePartitionPath($partition);

        if (!is_dir($partitionDirectory)) {
            return $key ? null : array();
        }

        if (!$key) {
            $list = scandir($partitionDirectory);
            if ($list === false) {
                return array();
            }
            $list = array_values(array_diff($list, array('.', '..')));
            sort($list);
            return $list;
        }

        return $this->readJsonFile($partitionDirectory . DIRECTORY_SEPARATOR . $key);
    }

    public function remove($partition = false, $key = false) {
        if (!$partition || !$key) {
            return false;
        }

        $recordPath = $this->getStoragePartitionPath($partition) . DIRECTORY_SEPARATOR . $key;
        if (file_exists($recordPath)) {
            return unlink($recordPath);
        }
        return false;
    }


    private function rrmdir($dir) {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') as $file) {
            if (is_dir($file)) {
                $this->rrmdir($file);
            } else {
                unlink($file);
            }
        }
        rmdir($dir);
    }

    private function ensureDirectoryExists($directory) {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create directory: ' . $directory);
        }
    }

    private function getDatabasePath() {
        return $this->path . DIRECTORY_SEPARATOR . $this->db;
    }

    private function getTablePath($table) {
        return $this->getDatabasePath() . DIRECTORY_SEPARATOR . $table;
    }

    private function getTableSchemePath($table) {
        return $this->getTablePath($table) . '.scheme';
    }

    private function ensureStoragePartition($partition) {
        $storageDirectory = $this->getDatabasePath() . DIRECTORY_SEPARATOR . 'storage';
        $this->ensureDirectoryExists($storageDirectory);

        $partitionDirectory = $storageDirectory . DIRECTORY_SEPARATOR . $partition;
        $this->ensureDirectoryExists($partitionDirectory);

        return $partitionDirectory;
    }

    private function getStoragePartitionPath($partition) {
        return $this->getDatabasePath() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $partition;
    }

    private function matchesWhere($record, $where) {
        foreach ($where as $k => $v) {
            $isLikeComparison = substr($k, -1) == '%';
            $key = $isLikeComparison ? substr($k, 0, strlen($k) - 1) : $k;
            $value = isset($record[$key]) ? $record[$key] : null;

            if ($isLikeComparison) {
                if (!is_string($value) || stripos($value, $v) === false) {
                    return false;
                }
            } else {
                if ($value != $v) {
                    return false;
                }
            }
        }

        return true;
    }

    private function applyJoin($record, $join) {
        foreach ($join as $jk => $jv) {
            if (!isset($record[$jk])) {
                continue;
            }

            $whereKeyTmp = $record[$jk];
            $joinedResults = $this->get($jv[0], array($jv[1] => $whereKeyTmp));
            $record[$jk] = count($joinedResults) > 0 ? $joinedResults[0] : null;
        }

        return $record;
    }

    private function readFileContents($path, $default = '') {
        if (!file_exists($path)) {
            return $default;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return $default;
        }

        return $contents;
    }

    private function readJsonFile($path) {
        if (!file_exists($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            return null;
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }
}

\class_alias(FileDB::class, 'filedb');
