<?php

namespace BBS\Core;

use PDO;

class SQLiteCatalog
{
    private static ?self $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        // Store SQLite database in the persistent data volume (inside cache dir which is writable by www-data)
        $dbPath = '/var/bbs/cache/catalog.sqlite';
        $this->pdo = new PDO("sqlite:{$dbPath}");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Optimize SQLite for bulk inserts and fast reads
        $this->pdo->exec('PRAGMA journal_mode = WAL;');
        $this->pdo->exec('PRAGMA synchronous = NORMAL;');
        
        $this->initSchema();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function initSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS file_catalog (
                agent_id INTEGER,
                archive_id INTEGER,
                path TEXT,
                file_name TEXT,
                parent_dir TEXT,
                file_size INTEGER,
                status TEXT,
                mtime TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_file_catalog_agent_archive ON file_catalog(agent_id, archive_id);
            CREATE INDEX IF NOT EXISTS idx_file_catalog_parent_dir ON file_catalog(parent_dir);

            CREATE TABLE IF NOT EXISTS catalog_dirs (
                agent_id INTEGER,
                archive_id INTEGER,
                dir_path TEXT,
                parent_dir TEXT,
                name TEXT,
                file_count INTEGER,
                total_size INTEGER
            );
            CREATE INDEX IF NOT EXISTS idx_catalog_dirs_parent ON catalog_dirs(agent_id, archive_id, parent_dir);
        ");
    }

    /**
     * Translate ClickHouse SQL to SQLite SQL
     */
    private function translateSql(string $sql): string
    {
        // Translate aggregate functions
        $sql = str_ireplace('count() as cnt', 'COUNT(*) as cnt', $sql);
        $sql = str_ireplace('uniqExact(path) as cnt', 'COUNT(DISTINCT path) as cnt', $sql);
        
        // Translate date formatting (SQLite just returns the text string)
        $sql = preg_replace("/formatDateTime\\(mtime,\\s*'%Y-%m-%d %H:%i:%S'\\)/i", 'mtime', $sql);
        
        // Translate ALTER TABLE DELETE
        $trimmedSql = trim($sql);
        if (stripos($trimmedSql, 'ALTER TABLE') === 0 && stripos($trimmedSql, 'DELETE WHERE') !== false) {
            $sql = preg_replace('/^ALTER\s+TABLE\s+([a-zA-Z0-9_]+)\s+DELETE\s+WHERE\s+(.*?)(?:\s+SETTINGS.*)?$/i', 'DELETE FROM $1 WHERE $2', $trimmedSql);
        }
        
        return $sql;
    }

    public function exec(string $sql): string
    {
        $sql = $this->translateSql($sql);
        $this->pdo->exec($sql);
        return '';
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $sql = $this->translateSql($sql);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $sql = $this->translateSql($sql) . ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Read TSV string natively and insert to SQLite.
     * Replaces ClickHouse HTTP bulk load.
     */
    public function insertTsv(string $table, string $tsvFilePath, array $columns): void
    {
        if (!file_exists($tsvFilePath)) {
            throw new \RuntimeException("TSV file not found: {$tsvFilePath}");
        }

        $fh = fopen($tsvFilePath, 'r');
        if (!$fh) {
            throw new \RuntimeException("Cannot read TSV file: {$tsvFilePath}");
        }

        $cols = implode(', ', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        
        $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";
        
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare($sql);
            
            while (($line = fgets($fh)) !== false) {
                $line = trim($line, "\n\r");
                if ($line === '') continue;
                
                $values = explode("\t", $line);
                
                // Unescape ClickHouse-escaped TSV ('\t', '\n', '\\')
                $values = array_map(function($v) {
                    if ($v === '\\N') return null;
                    return str_replace(['\\t', '\\n', '\\\\'], ["\t", "\n", '\\'], $v);
                }, $values);
                
                $stmt->execute($values);
            }
            
            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            fclose($fh);
            throw clone $e;
        }
        
        fclose($fh);
    }
}
