<?php

declare(strict_types=1);

namespace Gereq\Console\Serve;

use PDO;
use PDOException;
use Throwable;

/**
 * Lightweight DB browser for `serve` TUI (Adminer-like browse + SQL + row CRUD).
 */
final class ServeDbBrowser
{
    public const PAGE_SIZE = 50;

    private ?PDO $pdo = null;

    private string $driver = 'sqlite';

    private ?string $error = null;

    /** @var list<string>|null */
    private ?array $tablesCache = null;

    public function __construct(private readonly string $basePath)
    {
        $this->connect();
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function label(): string
    {
        if ($this->error !== null) {
            return 'db · error';
        }

        return match ($this->driver) {
            'mysql' => 'mysql',
            'pgsql' => 'pgsql',
            default => 'sqlite',
        };
    }

    /** @return list<string> */
    public function tables(): array
    {
        if ($this->tablesCache !== null) {
            return $this->tablesCache;
        }
        if ($this->pdo === null) {
            return [];
        }

        try {
            $this->tablesCache = match ($this->driver) {
                'mysql' => $this->fetchCol('SHOW TABLES'),
                'pgsql' => $this->fetchCol(
                    "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"
                ),
                default => $this->fetchCol(
                    "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
                ),
            };
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            $this->tablesCache = [];
        }

        return $this->tablesCache;
    }

    public function refreshTables(): void
    {
        $this->tablesCache = null;
    }

    /**
     * @return list<array{name: string, type: string, nullable: bool}>
     */
    public function columns(string $table): array
    {
        if ($this->pdo === null || !$this->isSafeIdent($table)) {
            return [];
        }

        try {
            return match ($this->driver) {
                'mysql' => $this->mysqlColumns($table),
                'pgsql' => $this->pgsqlColumns($table),
                default => $this->sqliteColumns($table),
            };
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return [];
        }
    }

    /** @return list<string> */
    public function primaryKeys(string $table): array
    {
        if ($this->pdo === null || !$this->isSafeIdent($table)) {
            return [];
        }

        try {
            return match ($this->driver) {
                'mysql' => $this->mysqlPrimaryKeys($table),
                'pgsql' => $this->pgsqlPrimaryKeys($table),
                default => $this->sqlitePrimaryKeys($table),
            };
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return [];
        }
    }

    /**
     * @return array{
     *   columns: list<string>,
     *   rows: list<list<string>>,
     *   raw: list<array<string, mixed>>,
     *   total: int
     * }
     */
    public function page(string $table, int $page): array
    {
        $empty = ['columns' => [], 'rows' => [], 'raw' => [], 'total' => 0];
        if ($this->pdo === null || !$this->isSafeIdent($table)) {
            return $empty;
        }

        $page = max(1, $page);
        $limit = self::PAGE_SIZE;
        $offset = ($page - 1) * $limit;
        $qTable = $this->quoteIdent($table);

        try {
            $total = (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $qTable)->fetchColumn();
            $sql = 'SELECT * FROM ' . $qTable . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
            $stmt = $this->pdo->query($sql);
            if ($stmt === false) {
                return $empty;
            }
            $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($raw === []) {
                $cols = array_map(
                    static fn (array $c): string => $c['name'],
                    $this->columns($table)
                );

                return ['columns' => $cols, 'rows' => [], 'raw' => [], 'total' => $total];
            }
            /** @var list<string> $columns */
            $columns = array_keys($raw[0]);
            $rows = [];
            /** @var list<array<string, mixed>> $rawOut */
            $rawOut = [];
            foreach ($raw as $row) {
                $line = [];
                foreach ($columns as $col) {
                    $line[] = $this->stringify($row[$col] ?? null, 120);
                }
                $rows[] = $line;
                $rawOut[] = $row;
            }

            return ['columns' => $columns, 'rows' => $rows, 'raw' => $rawOut, 'total' => $total];
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return $empty;
        }
    }

    /**
     * @param array<string, mixed> $rowAssoc
     *
     * @return array{ok: bool, message: string}
     */
    public function deleteRow(string $table, array $rowAssoc): array
    {
        if ($this->pdo === null || !$this->isSafeIdent($table)) {
            return ['ok' => false, 'message' => 'Invalid table'];
        }
        $pks = $this->primaryKeys($table);
        if ($pks === []) {
            return ['ok' => false, 'message' => 'No primary key — use SQL DELETE'];
        }
        $where = [];
        $params = [];
        foreach ($pks as $i => $pk) {
            if (!array_key_exists($pk, $rowAssoc)) {
                return ['ok' => false, 'message' => 'Missing PK column ' . $pk];
            }
            $where[] = $this->quoteIdent($pk) . ' = :p' . $i;
            $params['p' . $i] = $rowAssoc[$pk];
        }
        $sql = 'DELETE FROM ' . $this->quoteIdent($table) . ' WHERE ' . implode(' AND ', $where);
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return ['ok' => true, 'message' => 'Deleted ' . $stmt->rowCount() . ' row(s)'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, string|null> $values column => value (string "NULL" → SQL NULL)
     *
     * @return array{ok: bool, message: string}
     */
    public function insertRow(string $table, array $values): array
    {
        if ($this->pdo === null || !$this->isSafeIdent($table)) {
            return ['ok' => false, 'message' => 'Invalid table'];
        }
        if ($values === []) {
            return ['ok' => false, 'message' => 'No values'];
        }
        $cols = [];
        $placeholders = [];
        $params = [];
        $i = 0;
        foreach ($values as $col => $val) {
            if (!$this->isSafeIdent($col)) {
                continue;
            }
            $cols[] = $this->quoteIdent($col);
            if ($val === null || strtoupper((string) $val) === 'NULL') {
                $placeholders[] = 'NULL';
            } else {
                $ph = ':v' . $i;
                $placeholders[] = $ph;
                $params['v' . $i] = $val;
                ++$i;
            }
        }
        if ($cols === []) {
            return ['ok' => false, 'message' => 'No valid columns'];
        }
        $sql = 'INSERT INTO ' . $this->quoteIdent($table)
            . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return ['ok' => true, 'message' => 'Inserted (id ' . ($this->pdo->lastInsertId() ?: '?') . ')'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{
     *   ok: bool,
     *   message: string,
     *   columns: list<string>,
     *   rows: list<list<string>>,
     *   affected: int
     * }
     */
    public function execSql(string $sql): array
    {
        $empty = ['ok' => false, 'message' => '', 'columns' => [], 'rows' => [], 'affected' => 0];
        if ($this->pdo === null) {
            return $empty + ['message' => 'No connection'];
        }
        $sql = trim($sql);
        if ($sql === '') {
            return $empty + ['message' => 'Empty SQL'];
        }
        // Strip trailing semicolons; single-statement only.
        $sql = rtrim($sql, " \t\n\r;");
        if (str_contains($sql, ';')) {
            return $empty + ['message' => 'One statement only (no multi-query)'];
        }

        try {
            $isSelect = (bool) preg_match('/^\s*(SELECT|WITH|SHOW|PRAGMA|EXPLAIN|DESCRIBE|DESC)\b/i', $sql);
            if ($isSelect) {
                $stmt = $this->pdo->query($sql);
                if ($stmt === false) {
                    return $empty + ['message' => 'Query failed'];
                }
                $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if ($raw === []) {
                    return [
                        'ok' => true,
                        'message' => '0 rows',
                        'columns' => [],
                        'rows' => [],
                        'affected' => 0,
                    ];
                }
                /** @var list<string> $columns */
                $columns = array_keys($raw[0]);
                $rows = [];
                foreach (array_slice($raw, 0, 200) as $row) {
                    $line = [];
                    foreach ($columns as $col) {
                        $line[] = $this->stringify($row[$col] ?? null, 80);
                    }
                    $rows[] = $line;
                }
                $extra = count($raw) > 200 ? ' (showing 200/' . count($raw) . ')' : '';

                return [
                    'ok' => true,
                    'message' => count($raw) . ' row(s)' . $extra,
                    'columns' => $columns,
                    'rows' => $rows,
                    'affected' => count($raw),
                ];
            }

            $affected = $this->pdo->exec($sql);
            $this->refreshTables();

            return [
                'ok' => true,
                'message' => 'OK · affected ' . (int) $affected,
                'columns' => [],
                'rows' => [],
                'affected' => (int) $affected,
            ];
        } catch (Throwable $e) {
            return $empty + ['message' => $e->getMessage()];
        }
    }

    public function quoteIdent(string $ident): string
    {
        return match ($this->driver) {
            'mysql' => '`' . str_replace('`', '``', $ident) . '`',
            'pgsql' => '"' . str_replace('"', '""', $ident) . '"',
            default => '"' . str_replace('"', '""', $ident) . '"',
        };
    }

    public function isSafeIdent(string $ident): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $ident) === 1;
    }

    private function connect(): void
    {
        $driver = strtolower((string) (getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite')));
        $this->driver = match ($driver) {
            'mysql', 'pgsql', 'sqlite' => $driver,
            default => 'sqlite',
        };

        try {
            $this->pdo = match ($this->driver) {
                'mysql' => $this->pdoMysql(),
                'pgsql' => $this->pdoPgsql(),
                default => $this->pdoSqlite(),
            };
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->pdo = null;
            $this->error = $e->getMessage();
        }
    }

    private function pdoSqlite(): PDO
    {
        $path = (string) (getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? 'storage/database.sqlite'));
        if (!str_starts_with($path, '/')) {
            $path = rtrim($this->basePath, '/') . '/' . $path;
        }

        return new PDO('sqlite:' . $path);
    }

    private function pdoMysql(): PDO
    {
        $host = (string) (getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1'));
        $port = (string) (getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? '3306'));
        $name = (string) (getenv('DB_DATABASE_NAME') ?: ($_ENV['DB_DATABASE_NAME'] ?? 'gereq'));
        $user = (string) (getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? 'gereq'));
        $pass = (string) (getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? ''));
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

        return new PDO($dsn, $user, $pass, [PDO::ATTR_TIMEOUT => 3]);
    }

    private function pdoPgsql(): PDO
    {
        $host = (string) (getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1'));
        $port = (string) (getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? '5432'));
        $name = (string) (getenv('DB_DATABASE_NAME') ?: ($_ENV['DB_DATABASE_NAME'] ?? 'gereq'));
        $user = (string) (getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? 'gereq'));
        $pass = (string) (getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? ''));
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);

        return new PDO($dsn, $user, $pass, [PDO::ATTR_TIMEOUT => 3]);
    }

    /** @return list<string> */
    private function fetchCol(string $sql): array
    {
        if ($this->pdo === null) {
            return [];
        }
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            return [];
        }
        $out = [];
        while (($v = $stmt->fetchColumn()) !== false) {
            $out[] = (string) $v;
        }

        return $out;
    }

    /**
     * @return list<array{name: string, type: string, nullable: bool}>
     */
    private function sqliteColumns(string $table): array
    {
        $stmt = $this->pdo?->query('PRAGMA table_info(' . $this->quoteIdent($table) . ')');
        if ($stmt === false || $stmt === null) {
            return [];
        }
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'name' => (string) ($row['name'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'nullable' => !((int) ($row['notnull'] ?? 0)),
            ];
        }

        return $out;
    }

    /** @return list<string> */
    private function sqlitePrimaryKeys(string $table): array
    {
        $stmt = $this->pdo?->query('PRAGMA table_info(' . $this->quoteIdent($table) . ')');
        if ($stmt === false || $stmt === null) {
            return [];
        }
        $pks = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ((int) ($row['pk'] ?? 0) > 0) {
                $pks[(int) $row['pk']] = (string) ($row['name'] ?? '');
            }
        }
        ksort($pks);

        return array_values($pks);
    }

    /**
     * @return list<array{name: string, type: string, nullable: bool}>
     */
    private function mysqlColumns(string $table): array
    {
        $stmt = $this->pdo?->query('SHOW COLUMNS FROM ' . $this->quoteIdent($table));
        if ($stmt === false || $stmt === null) {
            return [];
        }
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'name' => (string) ($row['Field'] ?? ''),
                'type' => (string) ($row['Type'] ?? ''),
                'nullable' => strtoupper((string) ($row['Null'] ?? '')) === 'YES',
            ];
        }

        return $out;
    }

    /** @return list<string> */
    private function mysqlPrimaryKeys(string $table): array
    {
        $stmt = $this->pdo?->query('SHOW COLUMNS FROM ' . $this->quoteIdent($table));
        if ($stmt === false || $stmt === null) {
            return [];
        }
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (strtoupper((string) ($row['Key'] ?? '')) === 'PRI') {
                $out[] = (string) ($row['Field'] ?? '');
            }
        }

        return $out;
    }

    /**
     * @return list<array{name: string, type: string, nullable: bool}>
     */
    private function pgsqlColumns(string $table): array
    {
        $sql = <<<'SQL'
SELECT column_name, data_type, is_nullable
FROM information_schema.columns
WHERE table_schema = 'public' AND table_name = :t
ORDER BY ordinal_position
SQL;
        $stmt = $this->pdo?->prepare($sql);
        if ($stmt === false || $stmt === null) {
            return [];
        }
        $stmt->execute(['t' => $table]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'name' => (string) ($row['column_name'] ?? ''),
                'type' => (string) ($row['data_type'] ?? ''),
                'nullable' => strtoupper((string) ($row['is_nullable'] ?? '')) === 'YES',
            ];
        }

        return $out;
    }

    /** @return list<string> */
    private function pgsqlPrimaryKeys(string $table): array
    {
        $sql = <<<'SQL'
SELECT a.attname
FROM pg_index i
JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY (i.indkey)
WHERE i.indrelid = :t::regclass AND i.indisprimary
ORDER BY a.attnum
SQL;
        $stmt = $this->pdo?->prepare($sql);
        if ($stmt === false || $stmt === null) {
            return [];
        }
        $stmt->execute(['t' => 'public.' . $table]);
        $out = [];
        while (($v = $stmt->fetchColumn()) !== false) {
            $out[] = (string) $v;
        }

        return $out;
    }

    private function stringify(mixed $value, int $maxLen = 80): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            $s = (string) $value;
            $s = str_replace(["\r", "\n", "\t"], ['\\r', '\\n', '\\t'], $s);
            if (mb_strlen($s) > $maxLen) {
                return mb_substr($s, 0, $maxLen - 1) . '…';
            }

            return $s;
        }

        return '[…]';
    }
}
