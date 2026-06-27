<?php
require_once __DIR__ . '/../config/database.php';

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST, DB_NAME, DB_CHARSET
            );
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ];
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                error_log('DB Connection failed: ' . $e->getMessage());
                die(json_encode(['success' => false, 'message' => 'Database connection failed.']));
            }
        }
        return self::$instance;
    }

    public static function query(string $sql, array $params = []): PDOStatement {
        try {
            $stmt = self::getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            // 2006 = MySQL server has gone away, 2013 = Lost connection during query
            // Both mean the TCP connection dropped. Reset the singleton and retry once.
            $code = (int)($e->errorInfo[1] ?? 0);
            if (in_array($code, [2006, 2013])) {
                error_log("DB: connection lost ({$code}), reconnecting and retrying.");
                self::$instance = null;
                $stmt = self::getConnection()->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            }
            throw $e;
        }
    }

    public static function fetchOne(string $sql, array $params = []): ?array {
        $row = self::query($sql, $params)->fetch();
        return $row ?: null;
    }

    public static function fetchAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    public static function insert(string $table, array $data): int {
        $cols = implode(', ', array_keys($data));
        $plac = implode(', ', array_fill(0, count($data), '?'));
        self::query("INSERT INTO {$table} ({$cols}) VALUES ({$plac})", array_values($data));
        return (int) self::getConnection()->lastInsertId();
    }

    /**
     * Insert multiple rows in a single statement.
     * $rows must all have the same keys (column names).
     * Splits automatically into chunks to avoid packet-size limits.
     */
    public static function bulkInsert(string $table, array $rows, int $chunkSize = 500): void {
        if (empty($rows)) return;
        $cols    = array_keys($rows[0]);
        $colsSql = implode(', ', $cols);
        $rowPlac = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $values = implode(', ', array_fill(0, count($chunk), $rowPlac));
            $params = [];
            foreach ($chunk as $row) {
                foreach ($cols as $col) {
                    $params[] = $row[$col];
                }
            }
            self::query("INSERT INTO {$table} ({$colsSql}) VALUES {$values}", $params);
        }
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        $set  = implode(' = ?, ', array_keys($data)) . ' = ?';
        $stmt = self::query(
            "UPDATE {$table} SET {$set} WHERE {$where}",
            array_merge(array_values($data), $whereParams)
        );
        return $stmt->rowCount();
    }

    public static function count(string $sql, array $params = []): int {
        return (int) self::query($sql, $params)->fetchColumn();
    }

    public static function lastInsertId(): int {
        return (int) self::getConnection()->lastInsertId();
    }

    public static function beginTransaction(): void { self::getConnection()->beginTransaction(); }
    public static function commit(): void           { self::getConnection()->commit(); }
    public static function rollback(): void         { self::getConnection()->rollBack(); }
}
