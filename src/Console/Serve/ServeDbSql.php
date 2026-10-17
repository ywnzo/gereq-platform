<?php

declare(strict_types=1);

namespace Gereq\Console\Serve;

/**
 * SQL syntax highlighting + autocomplete for the serve DB pane.
 */
final class ServeDbSql
{
    private const KEYWORDS = [
        'SELECT', 'FROM', 'WHERE', 'AND', 'OR', 'NOT', 'IN', 'IS', 'NULL', 'AS',
        'JOIN', 'LEFT', 'RIGHT', 'INNER', 'OUTER', 'ON', 'GROUP', 'BY', 'ORDER',
        'ASC', 'DESC', 'LIMIT', 'OFFSET', 'INSERT', 'INTO', 'VALUES', 'UPDATE',
        'SET', 'DELETE', 'CREATE', 'TABLE', 'DROP', 'ALTER', 'INDEX', 'UNIQUE',
        'PRIMARY', 'KEY', 'FOREIGN', 'REFERENCES', 'DEFAULT', 'TRUE', 'FALSE',
        'DISTINCT', 'HAVING', 'UNION', 'ALL', 'EXISTS', 'BETWEEN', 'LIKE', 'ILIKE',
        'CASE', 'WHEN', 'THEN', 'ELSE', 'END', 'WITH', 'COUNT', 'SUM', 'AVG',
        'MIN', 'MAX', 'CAST', 'SHOW', 'PRAGMA', 'EXPLAIN', 'DESCRIBE',
    ];

    /**
     * Highlight SQL with ANSI colors (keywords/strings/numbers/comments).
     */
    public static function highlight(string $sql): string
    {
        if ($sql === '') {
            return '';
        }
        $out = '';
        $len = strlen($sql);
        $i = 0;
        while ($i < $len) {
            // Line comment
            if ($sql[$i] === '-' && ($sql[$i + 1] ?? '') === '-') {
                $end = strpos($sql, "\n", $i);
                if ($end === false) {
                    $end = $len;
                }
                $out .= "\033[90m" . substr($sql, $i, $end - $i) . "\033[0m";
                $i = $end;
                continue;
            }
            // Block comment
            if ($sql[$i] === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $len : $end + 2;
                $out .= "\033[90m" . substr($sql, $i, $end - $i) . "\033[0m";
                $i = $end;
                continue;
            }
            // String
            if ($sql[$i] === '\'' || $sql[$i] === '"') {
                $q = $sql[$i];
                $j = $i + 1;
                while ($j < $len) {
                    if ($sql[$j] === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($sql[$j] === $q) {
                        if (($sql[$j + 1] ?? '') === $q) {
                            $j += 2;
                            continue;
                        }
                        ++$j;
                        break;
                    }
                    ++$j;
                }
                $out .= "\033[32m" . substr($sql, $i, $j - $i) . "\033[0m";
                $i = $j;
                continue;
            }
            // Number
            if (ctype_digit($sql[$i]) || ($sql[$i] === '.' && isset($sql[$i + 1]) && ctype_digit($sql[$i + 1]))) {
                $j = $i;
                while ($j < $len && (ctype_digit($sql[$j]) || $sql[$j] === '.')) {
                    ++$j;
                }
                $out .= "\033[33m" . substr($sql, $i, $j - $i) . "\033[0m";
                $i = $j;
                continue;
            }
            // Word
            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $sql, $m, 0, $i) === 1) {
                $word = $m[0];
                $upper = strtoupper($word);
                if (in_array($upper, self::KEYWORDS, true)) {
                    $out .= "\033[1;36m" . $word . "\033[0m";
                } else {
                    $out .= "\033[37m" . $word . "\033[0m";
                }
                $i += strlen($word);
                continue;
            }
            $out .= "\033[90m" . $sql[$i] . "\033[0m";
            ++$i;
        }

        return $out;
    }

    /**
     * @param list<string> $tables
     * @param list<string> $columns
     *
     * @return list<string>
     */
    public static function suggestions(string $sql, int $cursor, array $tables, array $columns): array
    {
        $cursor = max(0, min($cursor, strlen($sql)));
        $before = substr($sql, 0, $cursor);
        if (preg_match('/([A-Za-z_][A-Za-z0-9_]*)$/', $before, $m) !== 1) {
            return [];
        }
        $prefix = $m[1];
        $lower = strtolower($prefix);
        $pool = array_merge(
            array_map(static fn (string $k): string => strtolower($k) === $lower ? $k : $k, self::KEYWORDS),
            $tables,
            $columns
        );
        $hits = [];
        foreach ($pool as $item) {
            if ($item === '') {
                continue;
            }
            if (str_starts_with(strtolower($item), $lower) && strtolower($item) !== $lower) {
                $hits[] = $item;
            }
        }
        $hits = array_values(array_unique($hits));
        usort($hits, static function (string $a, string $b) use ($lower): int {
            $ak = str_starts_with(strtolower($a), $lower) ? 0 : 1;
            $bk = str_starts_with(strtolower($b), $lower) ? 0 : 1;
            if ($ak !== $bk) {
                return $ak <=> $bk;
            }

            return strlen($a) <=> strlen($b);
        });

        return array_slice($hits, 0, 12);
    }

    /**
     * Apply first suggestion at cursor; returns [newSql, newCursor].
     *
     * @param list<string> $tables
     * @param list<string> $columns
     *
     * @return array{0: string, 1: int}|null
     */
    public static function applyAutocomplete(string $sql, int $cursor, array $tables, array $columns): ?array
    {
        $suggestions = self::suggestions($sql, $cursor, $tables, $columns);
        if ($suggestions === []) {
            return null;
        }
        $pick = $suggestions[0];
        $before = substr($sql, 0, $cursor);
        if (preg_match('/([A-Za-z_][A-Za-z0-9_]*)$/', $before, $m) !== 1) {
            return null;
        }
        $prefix = $m[1];
        $start = $cursor - strlen($prefix);
        $new = substr($sql, 0, $start) . $pick . substr($sql, $cursor);

        return [$new, $start + strlen($pick)];
    }

    /** @return list<string> */
    public static function keywords(): array
    {
        return self::KEYWORDS;
    }
}
