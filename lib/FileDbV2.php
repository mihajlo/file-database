<?php
/**
 * FileDB v2
 *  - one file per table (table.db)
 *  - index file (table.idx) with "id:offset:length" per line
 *  - append-only writes, index updated on insert/update/delete
 *  - fast read-by-id via fseek
 *  - AWK accelerated search for non-id WHERE (uses shell_exec('awk ...'))
 *
 * Usage:
 *   $db = new FileDbV2(__DIR__ . '/databases');
 *   $db->create_table('users');
 *   $db->insert('users', ['_id' => 1, 'name' => 'Mihajlo', 'email' => 'a@a.com']);
 *   $row = $db->getOne('users', ['_id' => 1]);
 *
 * Note: requires PHP 7.4+ (typed hints), Linux environment with awk available for best performance.
 */

class FileDbV2
{
    private string $basePath;
    private string $storageDirName = 'storage';

    public function __construct(string $basePath = 'databases')
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->ensureDirectoryExists($this->basePath);
    }

    /* -------------------------
     * Table & path helpers
     * ------------------------- */
    private function getTablePath(string $table): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . $table;
    }

    private function getDataFile(string $table): string
    {
        return $this->getTablePath($table) . '.db';
    }

    private function getIndexFile(string $table): string
    {
        return $this->getTablePath($table) . '.idx';
    }

    private function ensureDirectoryExists(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException("Unable to create directory: {$dir}");
            }
        }
    }

    /* -------------------------
     * Table management
     * ------------------------- */
    public function create_table(string $table): bool
    {
        if (!$table) return false;
        $dir = $this->getTablePath($table);
        $this->ensureDirectoryExists($dir);
        $data = $this->getDataFile($table);
        $idx = $this->getIndexFile($table);
        if (!file_exists($data)) {
            // create empty files
            file_put_contents($data, '');
        }
        if (!file_exists($idx)) {
            file_put_contents($idx, '');
        }
        return true;
    }

    public function listTables(): array
    {
        $entries = @scandir($this->basePath);
        if ($entries === false) return [];
        $tables = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (is_dir($this->basePath . DIRECTORY_SEPARATOR . $entry)) {
                // table name is directory name
                $tables[] = $entry;
            }
        }
        sort($tables);
        return $tables;
    }

    public function drop_table(string $table): bool
    {
        $path = $this->getTablePath($table);
        if (!is_dir($path)) return false;
        // remove files with prefix table.* inside base or remove dir
        $data = $this->getDataFile($table);
        $idx = $this->getIndexFile($table);
        if (file_exists($data)) unlink($data);
        if (file_exists($idx)) unlink($idx);
        // try to rmdir table dir if empty
        @rmdir($path);
        return true;
    }

    public function drop_database(): bool
    {
        $this->rrmdir($this->basePath);
        $this->ensureDirectoryExists($this->basePath);
        return true;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = glob($dir . '/*');
        if ($items === false) return;
        foreach ($items as $it) {
            if (is_dir($it)) $this->rrmdir($it);
            else @unlink($it);
        }
        @rmdir($dir);
    }

    /* -------------------------
     * Index management
     * Index format: each line "id:offset:length\n"
     * ------------------------- */

    /**
     * Rebuild index by scanning datafile (useful after manual edits or compaction)
     */
    public function rebuildIndex(string $table): bool
    {
        $dataFile = $this->getDataFile($table);
        $idxFile = $this->getIndexFile($table);
        if (!file_exists($dataFile)) return false;

        $in = fopen($dataFile, 'rb');
        if (!$in) return false;

        $tmpIdx = $idxFile . '.tmp';
        $out = fopen($tmpIdx, 'wb');
        if (!$out) { fclose($in); return false; }

        // scan line by line, record offset and length
        $offset = 0;
        while (!feof($in)) {
            $line = fgets($in);
            if ($line === false) break;
            $len = strlen($line);
            $json = json_decode(trim($line), true);
            if (is_array($json) && isset($json['_id'])) {
                $id = (string)$json['_id'];
                fwrite($out, $id . ':' . $offset . ':' . $len . PHP_EOL);
            }
            $offset += $len;
        }

        fclose($in);
        fclose($out);
        // replace index atomically
        rename($tmpIdx, $idxFile);
        return true;
    }

    /**
     * Read index into associative array id => ['offset'=>..., 'len'=>...]
     */
    private function loadIndex(string $table): array
    {
        $idxFile = $this->getIndexFile($table);
        if (!file_exists($idxFile)) return [];

        $map = [];
        $fh = fopen($idxFile, 'rb');
        if (!$fh) return [];
        while (!feof($fh)) {
            $line = fgets($fh);
            if ($line === false) break;
            $line = trim($line);
            if ($line === '') continue;
            [$id, $offset, $len] = explode(':', $line) + [null, null, null];
            if ($id !== null) {
                $map[$id] = ['offset' => (int)$offset, 'len' => (int)$len];
            }
        }
        fclose($fh);
        return $map;
    }

    /**
     * Append an index entry (id:offset:length) to idx file (used at write time)
     * We do simple append; rebuildIndex can compact duplicates.
     */
    private function appendIndexEntry(string $table, string $id, int $offset, int $len): bool
    {
        $idxFile = $this->getIndexFile($table);
        $line = $id . ':' . $offset . ':' . $len . PHP_EOL;
        // append with lock
        $fh = fopen($idxFile, 'ab');
        if (!$fh) return false;
        if (!flock($fh, LOCK_EX)) { fclose($fh); return false; }
        $res = fwrite($fh, $line) !== false;
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        return $res;
    }

    /* -------------------------
     * CRUD operations
     * ------------------------- */

    /**
     * Insert record. If $record contains '_id', it will be used; otherwise auto-generate numeric id.
     * Returns inserted record (with _id) or false on failure.
     */
    public function insert(string $table, array $record)
    {
        $this->create_table($table);
        $dataFile = $this->getDataFile($table);

        // ensure _id
        if (!isset($record['_id'])) {
            // auto id: use timestamp + random to avoid collisions
            $record['_id'] = $this->generateId();
        } else {
            $record['_id'] = (string)$record['_id'];
        }

        $jsonLine = json_encode($record, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        $fh = fopen($dataFile, 'ab');
        if (!$fh) return false;
        if (!flock($fh, LOCK_EX)) { fclose($fh); return false; }
        $offset = (int)ftell($fh);
        $written = fwrite($fh, $jsonLine);
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        if ($written === false) return false;

        // update index (append)
        $this->appendIndexEntry($table, (string)$record['_id'], $offset, strlen($jsonLine));
        return $record;
    }

    private function generateId(): string
    {
        // microtime based unique id
        $mt = microtime(true);
        return (string)str_replace('.', '', (string)$mt) . bin2hex(random_bytes(3));
    }

    /**
     * Find by _id quickly (via index and fseek)
     */
    public function findById(string $table, $id): ?array
    {
        if (!is_string($id)) $id = (string)$id;
        $dataFile = $this->getDataFile($table);
        if (!file_exists($dataFile)) return null;

        // load index and pick latest entry for id (index may contain duplicates; last wins)
        $map = $this->loadIndex($table);
        if (!isset($map[$id])) {
            // index doesn't have it; try rebuildIndex and check again
            $this->rebuildIndex($table);
            $map = $this->loadIndex($table);
            if (!isset($map[$id])) return null;
        }

        $entry = $map[$id];
        $fh = fopen($dataFile, 'rb');
        if (!$fh) return null;
        if (!flock($fh, LOCK_SH)) { fclose($fh); return null; }
        fseek($fh, $entry['offset']);
        $raw = fread($fh, $entry['len']);
        flock($fh, LOCK_UN);
        fclose($fh);
        if ($raw === false) return null;
        $json = json_decode(trim($raw), true);
        if (!is_array($json)) return null;
        // if record has "_deleted":true -> treat as not found
        if (isset($json['_deleted']) && $json['_deleted']) return null;
        return $json;
    }

    /**
     * get() supports:
     *  - get($table) -> all rows (uses awk for speed)
     *  - get($table, ['_id'=>123]) -> fast id lookup
     *  - get($table, ['name' => 'a@b.com', 'type%' => 'foo']) -> AWK powered where (LIKE via key%)
     *
     * $join not implemented here (could call other table getOne)
     */
    public function get(string $table, $where = null, $join = false): array
    {
        $dataFile = $this->getDataFile($table);
        if (!file_exists($dataFile)) return [];

        // direct id lookup
        if (is_array($where) && isset($where['_id']) && count($where) === 1) {
            $row = $this->findById($table, (string)$where['_id']);
            return $row ? [$row] : [];
        }

        // If WHERE is null -> return all (AWK + decode)
        if ($where === null) {
            // Use awk to print all non-deleted lines quickly
            $cmd = "awk 'BEGIN{IGNORECASE=1} { print }' " . escapeshellarg($dataFile);
            $out = $this->runShell($cmd);
            if ($out === null) {
                // fallback to PHP scan
                return $this->phpScanAll($dataFile);
            }
            return $this->decodeLinesFilterDeleted($out);
        }

        // WHERE is array -> build AWK condition
        if (is_array($where)) {
            $awkCond = $this->buildAwkCondition($where);
            if ($awkCond === null) {
                // fallback to PHP scanning
                return $this->phpScanWithWhere($dataFile, $where);
            }
            $cmd = "awk 'BEGIN{IGNORECASE=1} $awkCond { print }' " . escapeshellarg($dataFile);
            $out = $this->runShell($cmd);
            if ($out === null) {
                return $this->phpScanWithWhere($dataFile, $where);
            }
            return $this->decodeLinesFilterDeleted($out);
        }

        // unknown where type -> return empty
        return [];
    }

    public function getOne(string $table, $where = null)
    {
        $rows = $this->get($table, $where);
        return count($rows) ? $rows[0] : null;
    }

    /**
     * Update: append new record version. $where should narrow rows (e.g. ['_id'=>x] or other conditions)
     * Returns number of updated rows.
     */
    public function update(string $table, array $data, $where = []): int
    {
        if (empty($data)) return 0;
        $rows = $this->get($table, $where);
        $count = 0;
        foreach ($rows as $row) {
            // merge (do not allow changing _id)
            $id = (string)$row['_id'];
            $new = $row;
            foreach ($data as $k => $v) {
                if ($k === '_id') continue;
                if ($v === null) {
                    unset($new[$k]);
                } else {
                    $new[$k] = $v;
                }
            }
            // append new version
            $this->insert($table, $new);
            $count++;
        }
        return $count;
    }

    /**
     * Delete: mark records as deleted by appending a tombstone version with "_deleted":true
     * Returns number of deleted rows.
     */
    public function delete(string $table, $where = []): int
    {
        $rows = $this->get($table, $where);
        $count = 0;
        foreach ($rows as $row) {
            $id = (string)$row['_id'];
            $tomb = ['_id' => $id, '_deleted' => true, '_deleted_ts' => time()];
            $this->insert($table, $tomb);
            $count++;
        }
        return $count;
    }

    /* -------------------------
     * AWK helpers & PHP fallback scanners
     * ------------------------- */

    /**
     * Build AWK condition from where array.
     * Supports:
     *   - exact: ['email' => 'a@b.com']
     *   - like: ['name%' => 'mih'] (case-insensitive substring)
     *
     * Returns awk snippet like: ($0 ~ /"email":"a@b.com"/) && (tolower($0) ~ /mih/)
     * or null if building not possible.
     */
    private function buildAwkCondition(array $where): ?string
    {
        $conds = [];
        foreach ($where as $k => $v) {
            if (!is_string($k)) return null;
            $isLike = substr($k, -1) === '%';
            $key = $isLike ? substr($k, 0, -1) : $k;
            $val = (string)$v;
            // escape slashes and single quotes for awk regex literal
            $valEsc = $this->awkRegexEscape($val);
            $keyEsc = $this->awkRegexEscape($key);
            if ($isLike) {
                // substring search anywhere in JSON line for the value (case-insensitive via IGNORECASE)
                // use tolower($0) ~ /.../ pattern or IGNORECASE=1 earlier
                $conds[] = "tolower(\$0) ~ /" . strtolower($valEsc) . "/";
            } else {
                // exact match for "key":"value" (simple, safe for primitive strings)
                // match either "key":"value" or "key": "value" with optional spaces
                $pattern = '"'.$keyEsc.'"[[:space:]]*:[[:space:]]*"' . $valEsc . '"';
                // convert to /.../ regex
                // AWK with IGNORECASE=1 will make it case-insensitive
                $conds[] = "\$0 ~ /" . $pattern . "/";
            }
        }
        if (empty($conds)) return null;
        return '(' . implode(' && ', $conds) . ')';
    }

    private function awkRegexEscape(string $s): string
    {
        // escape forward slash, backslash and double quotes for safe embedding
        $s = str_replace(['\\', '/', '"'], ['\\\\', '\/', '\"'], $s);
        // also escape regex meta-chars minimally
        $s = preg_replace('/([.^$*+?()[\]{}|])/','\\\\$1',$s);
        return $s;
    }

    /**
     * Run shell command and return raw output string or null if fails or shell_exec disabled.
     */
    private function runShell(string $cmd): ?string
    {
        // quick safety: if shell_exec disabled
        if (!function_exists('shell_exec')) return null;
        $out = @shell_exec($cmd . ' 2>/dev/null');
        if ($out === null) return null;
        return $out;
    }

    /**
     * Fallback PHP scan: read file line by line, decode JSON and apply where via matchesWhere
     */
    private function phpScanWithWhere(string $dataFile, array $where): array
    {
        $res = [];
        $fh = fopen($dataFile, 'rb');
        if (!$fh) return $res;
        while (!feof($fh)) {
            $line = fgets($fh);
            if ($line === false) break;
            $json = json_decode(trim($line), true);
            if (!is_array($json)) continue;
            if (isset($json['_deleted']) && $json['_deleted']) continue;
            if ($this->matchesWhere($json, $where)) {
                $res[] = $json;
            }
        }
        fclose($fh);
        return $res;
    }

    private function phpScanAll(string $dataFile): array
    {
        $res = [];
        $fh = fopen($dataFile, 'rb');
        if (!$fh) return $res;
        while (!feof($fh)) {
            $line = fgets($fh);
            if ($line === false) break;
            $json = json_decode(trim($line), true);
            if (!is_array($json)) continue;
            if (isset($json['_deleted']) && $json['_deleted']) continue;
            $res[] = $json;
        }
        fclose($fh);
        return $res;
    }

    private function decodeLinesFilterDeleted(string $raw): array
    {
        $out = [];
        $lines = explode("\n", trim($raw));
        foreach ($lines as $line) {
            if ($line === '') continue;
            $json = json_decode(trim($line), true);
            if (!is_array($json)) continue;
            if (isset($json['_deleted']) && $json['_deleted']) continue;
            $out[] = $json;
        }
        return $out;
    }

    /**
     * Basic matchesWhere logic (same as your previous class)
     */
    private function matchesWhere(array $record, array $where): bool
    {
        foreach ($where as $k => $v) {
            $isLike = substr($k, -1) === '%';
            $key = $isLike ? substr($k, 0, -1) : $k;
            $value = $record[$key] ?? null;
            if ($isLike) {
                if (!is_string($value) || stripos($value, (string)$v) === false) return false;
            } else {
                if ($value != $v) return false;
            }
        }
        return true;
    }

    /* -------------------------
     * Maintenance: compaction
     * ------------------------- */

    /**
     * Compact table: produce new datafile with only latest versions of records (skip tombstones),
     * rebuild index atomically.
     *
     * Warning: compact can be IO-heavy and should be run during maintenance window.
     */
    public function compactTable(string $table): bool
    {
        $dataFile = $this->getDataFile($table);
        $idxFile  = $this->getIndexFile($table);
        if (!file_exists($dataFile)) return false;

        // load index to get last offsets per id
        // BUT index might have duplicates; we will rebuild by scanning and keeping latest by offset (last wins)
        $in = fopen($dataFile, 'rb');
        if (!$in) return false;

        $tmpData = $dataFile . '.compact.tmp';
        $tmpIdx  = $idxFile . '.compact.tmp';

        $out = fopen($tmpData, 'wb');
        if (!$out) { fclose($in); return false; }

        $mapLatest = []; // id => json string (latest)
        // read file and keep last seen record for each id
        while (!feof($in)) {
            $pos = ftell($in);
            $line = fgets($in);
            if ($line === false) break;
            $json = json_decode(trim($line), true);
            if (!is_array($json) || !isset($json['_id'])) continue;
            $id = (string)$json['_id'];
            $mapLatest[$id] = $line; // keep last (overwrite previous)
        }
        fclose($in);

        // write compacted file and index
        $offset = 0;
        $idxOut = fopen($tmpIdx, 'wb');
        if (!$idxOut) { fclose($out); return false; }

        foreach ($mapLatest as $id => $line) {
            // skip tombstones
            $json = json_decode(trim($line), true);
            if (isset($json['_deleted']) && $json['_deleted']) continue;
            $len = strlen($line);
            fwrite($out, $line);
            fwrite($idxOut, $id . ':' . $offset . ':' . $len . PHP_EOL);
            $offset += $len;
        }

        fclose($out);
        fclose($idxOut);

        // atomic replace
        rename($tmpData, $dataFile);
        rename($tmpIdx, $idxFile);

        return true;
    }

    /* -------------------------
     * Utils: storage partition (like your save/read)
     * ------------------------- */
    public function ensureStoragePartition(string $partition): string
    {
        $storageDirectory = $this->basePath . DIRECTORY_SEPARATOR . $this->storageDirName;
        $this->ensureDirectoryExists($storageDirectory);
        $partitionDirectory = $storageDirectory . DIRECTORY_SEPARATOR . $partition;
        $this->ensureDirectoryExists($partitionDirectory);
        return $partitionDirectory;
    }

    public function save(string $partition, string $key, array $data): bool
    {
        $dir = $this->ensureStoragePartition($partition);
        $path = $dir . DIRECTORY_SEPARATOR . $key;
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json === false) return false;
        return (bool)file_put_contents($path, $json);
    }

    public function read(string $partition, string $key)
    {
        $dir = $this->getTablePath($partition); // reuse table path concept for storage
        $path = $this->ensureStoragePartition($partition) . DIRECTORY_SEPARATOR . $key;
        if (!file_exists($path)) return null;
        $json = file_get_contents($path);
        return json_decode($json, true);
    }
}