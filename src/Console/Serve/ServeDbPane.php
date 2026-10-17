<?php

declare(strict_types=1);

namespace Gereq\Console\Serve;

/**
 * Interactive Adminer-like DB pane for ServeDevTui (browse → enter table → CRUD / SQL).
 */
final class ServeDbPane
{
    private const SIDE_W = 28;

    private ServeDbBrowser $db;

    private string $table = '';

    private int $focus = 0;

    private int $tableScroll = 0;

    private bool $entered = false;

    /** browse focus: tables | data mode when entered: data|structure|sql|insert */
    private string $mode = 'data';

    private int $page = 1;

    private int $rowFocus = 0;

    /** @var array{columns: list<string>, rows: list<list<string>>, raw: list<array<string, mixed>>, total: int}|null */
    private ?array $pageCache = null;

    private string $cacheKey = '';

    private string $sql = '';

    private int $sqlCursor = 0;

    /** @var list<string> */
    private array $sqlSuggestions = [];

    /** @var array{ok: bool, message: string, columns: list<string>, rows: list<list<string>>, affected: int}|null */
    private ?array $sqlResult = null;

    /** @var list<string> insert column names */
    private array $insertCols = [];

    /** @var array<string, string> */
    private array $insertValues = [];

    private int $insertIdx = 0;

    private string $insertBuf = '';

    private ?string $status = null;

    private bool $confirmDelete = false;

    private string $lastClickTable = '';

    private float $lastClickAt = 0.0;

    /**
     * @var list<array{x1: int, y1: int, x2: int, y2: int, action: string, payload: string}>
     */
    public array $hits = [];

    public function __construct(string $basePath)
    {
        $this->db = new ServeDbBrowser($basePath);
        $this->sql = 'SELECT * FROM ';
        $this->sqlCursor = strlen($this->sql);
    }

    public function label(): string
    {
        return $this->db->label();
    }

    public function capturingInput(): bool
    {
        return $this->entered && ($this->mode === 'sql' || $this->mode === 'insert');
    }

    public function footerHint(): string
    {
        if (!$this->entered) {
            return ' Enter open · click/↑↓ table · Tab panes · Ctrl+C ';
        }
        if ($this->confirmDelete) {
            return ' Delete row? y confirm · n cancel ';
        }
        if ($this->mode === 'sql') {
            return ' SQL · Tab complete · Ctrl+Enter run · Esc back · Ctrl+C ';
        }
        if ($this->mode === 'insert') {
            return ' INSERT · type value · Enter next · Esc cancel · Ctrl+C ';
        }
        if ($this->mode === 'structure') {
            return ' structure · d data · / sql · Esc tables · Ctrl+C ';
        }

        return ' ↑↓ row · x del · i insert · / sql · ←→ page · Esc · Ctrl+C ';
    }

    public function handleArrow(string $dir): void
    {
        if ($this->confirmDelete) {
            return;
        }
        if ($this->mode === 'sql' && $this->entered) {
            if ($dir === 'D') {
                $this->sqlCursor = max(0, $this->sqlCursor - 1);
            } elseif ($dir === 'C') {
                $this->sqlCursor = min(strlen($this->sql), $this->sqlCursor + 1);
            }
            $this->refreshSuggestions();

            return;
        }
        if ($this->mode === 'insert' && $this->entered) {
            return;
        }
        if (!$this->entered) {
            $tables = $this->db->tables();
            if ($dir === 'A') {
                $this->focus = max(0, $this->focus - 1);
            } elseif ($dir === 'B') {
                $this->focus = min(max(0, count($tables) - 1), $this->focus + 1);
            }
            $this->syncTableFromFocus();

            return;
        }
        if ($this->mode === 'data') {
            if ($dir === 'A') {
                $this->rowFocus = max(0, $this->rowFocus - 1);
            } elseif ($dir === 'B') {
                $page = $this->ensurePage();
                $this->rowFocus = min(max(0, count($page['rows']) - 1), $this->rowFocus + 1);
            } elseif ($dir === 'D') {
                $this->page = max(1, $this->page - 1);
                $this->cacheKey = '';
                $this->rowFocus = 0;
            } elseif ($dir === 'C') {
                ++$this->page;
                $this->cacheKey = '';
                $this->rowFocus = 0;
            }
        }
    }

    public function handleWheel(int $delta): void
    {
        if ($this->entered && $this->mode === 'data') {
            $this->page = max(1, $this->page + $delta);
            $this->cacheKey = '';
            $this->rowFocus = 0;

            return;
        }
        if (!$this->entered) {
            $tables = $this->db->tables();
            $this->focus = max(0, min(count($tables) - 1, $this->focus + $delta));
            $this->syncTableFromFocus();
        }
    }

    public function handleKey(string $ch): bool
    {
        if ($ch === "\n" || $ch === "\r") {
            return $this->handleEnter();
        }
        if ($ch === "\x12" && $this->entered && $this->mode === 'sql') { // Ctrl+R
            $this->runSql();

            return true;
        }

        if ($this->confirmDelete) {
            if ($ch === 'y' || $ch === 'Y') {
                $this->doDelete();
                $this->confirmDelete = false;
            } elseif ($ch === 'n' || $ch === 'N') {
                $this->confirmDelete = false;
                $this->status = 'Delete cancelled';
            }

            return true;
        }

        if ($this->entered && $this->mode === 'insert') {
            return $this->handleInsertKey($ch);
        }

        if ($this->entered && $this->mode === 'sql') {
            return $this->handleSqlKey($ch);
        }

        if (!$this->entered) {
            if ($ch === 'r' || $ch === 'R') {
                $this->db->refreshTables();
                $this->cacheKey = '';
                $this->status = 'Tables refreshed';

                return true;
            }

            return false;
        }

        if ($ch === 's' || $ch === 'S') {
            $this->mode = $this->mode === 'structure' ? 'data' : 'structure';

            return true;
        }
        if ($ch === 'd' || $ch === 'D') {
            $this->mode = 'data';

            return true;
        }
        if ($ch === '/') {
            $this->openSql();

            return true;
        }
        if ($ch === 'i' || $ch === 'I') {
            $this->startInsert();

            return true;
        }
        if ($ch === 'x' || $ch === 'X' || $ch === "\x7f") {
            $this->confirmDelete = true;

            return true;
        }
        if ($ch === 'r' || $ch === 'R') {
            $this->cacheKey = '';
            $this->status = 'Reloaded';

            return true;
        }

        return false;
    }

    /** Esc key (when CSI not matched). */
    public function handleEscape(): void
    {
        if ($this->confirmDelete) {
            $this->confirmDelete = false;
            $this->status = 'Delete cancelled';

            return;
        }
        if ($this->mode === 'insert') {
            $this->mode = 'data';
            $this->status = 'Insert cancelled';

            return;
        }
        if ($this->mode === 'sql') {
            $this->mode = 'data';

            return;
        }
        if ($this->entered) {
            $this->entered = false;
            $this->status = null;
        }
    }

    public function handleTabComplete(): bool
    {
        if (!($this->entered && $this->mode === 'sql')) {
            return false;
        }
        $cols = array_map(
            static fn (array $c): string => $c['name'],
            $this->table !== '' ? $this->db->columns($this->table) : []
        );
        $applied = ServeDbSql::applyAutocomplete(
            $this->sql,
            $this->sqlCursor,
            $this->db->tables(),
            $cols
        );
        if ($applied === null) {
            return true;
        }
        [$this->sql, $this->sqlCursor] = $applied;
        $this->refreshSuggestions();

        return true;
    }

    public function dispatchHit(string $action, string $payload): void
    {
        if ($action === 'table') {
            $now = microtime(true);
            $tables = $this->db->tables();
            $idx = array_search($payload, $tables, true);
            if ($idx === false) {
                return;
            }
            $this->focus = (int) $idx;
            $this->syncTableFromFocus(true);
            if ($payload === $this->lastClickTable && ($now - $this->lastClickAt) < 0.45) {
                $this->enterTable();
            }
            $this->lastClickTable = $payload;
            $this->lastClickAt = $now;

            return;
        }
        if ($action === 'enter') {
            $this->enterTable();

            return;
        }
        if ($action === 'page') {
            $this->page = max(1, (int) $payload);
            $this->cacheKey = '';
            $this->rowFocus = 0;

            return;
        }
        if ($action === 'mode') {
            if ($payload === 'sql') {
                $this->openSql();
            } elseif (in_array($payload, ['data', 'structure'], true)) {
                $this->mode = $payload;
            }

            return;
        }
        if ($action === 'row') {
            $this->rowFocus = max(0, (int) $payload);

            return;
        }
        if ($action === 'back') {
            $this->entered = false;
            $this->mode = 'data';
        }
    }

    /**
     * @return list<string> body lines (without outer box borders)
     */
    public function render(int $width, int $bodyRows, int $screenY0): array
    {
        $this->hits = [];
        $err = $this->db->error();
        if ($err !== null && $this->db->tables() === []) {
            return [
                $this->ansi('31', 'DB connection failed'),
                $this->ansi('90', $this->clip($err, $width)),
            ];
        }

        $tables = $this->db->tables();
        if ($tables !== [] && $this->table === '') {
            $this->focus = 0;
            $this->syncTableFromFocus();
        }

        if ($this->entered) {
            return $this->renderEntered($width, $bodyRows, $screenY0);
        }

        return $this->renderBrowse($width, $bodyRows, $screenY0, $tables);
    }

    private function handleEnter(): bool
    {
        if ($this->confirmDelete) {
            $this->doDelete();
            $this->confirmDelete = false;

            return true;
        }
        if ($this->entered && $this->mode === 'sql') {
            $this->runSql();

            return true;
        }
        if ($this->entered && $this->mode === 'insert') {
            $this->commitInsertField();

            return true;
        }
        if (!$this->entered) {
            $this->enterTable();

            return true;
        }

        return false;
    }

    private function handleSqlKey(string $ch): bool
    {
        if ($ch === "\x1b") {
            $this->mode = 'data';

            return true;
        }
        if ($ch === "\t") {
            $this->handleTabComplete();

            return true;
        }
        // Ctrl+Enter alternatives: \x0a already handled; also accept empty with F5-like
        if ($ch === "\x7f" || $ch === "\x08") {
            if ($this->sqlCursor > 0) {
                $this->sql = substr($this->sql, 0, $this->sqlCursor - 1) . substr($this->sql, $this->sqlCursor);
                --$this->sqlCursor;
                $this->refreshSuggestions();
            }

            return true;
        }
        if ($ch === "\n" || $ch === "\r") {
            $this->runSql();

            return true;
        }
        if (ord($ch) < 32) {
            return true;
        }
        // Insert printable
        $this->sql = substr($this->sql, 0, $this->sqlCursor) . $ch . substr($this->sql, $this->sqlCursor);
        ++$this->sqlCursor;
        $this->refreshSuggestions();

        return true;
    }

    private function handleInsertKey(string $ch): bool
    {
        if ($ch === "\x1b") {
            $this->mode = 'data';
            $this->status = 'Insert cancelled';

            return true;
        }
        if ($ch === "\x7f" || $ch === "\x08") {
            $this->insertBuf = mb_substr($this->insertBuf, 0, max(0, mb_strlen($this->insertBuf) - 1));

            return true;
        }
        if ($ch === "\n" || $ch === "\r") {
            $this->commitInsertField();

            return true;
        }
        if (ord($ch) < 32) {
            return true;
        }
        $this->insertBuf .= $ch;

        return true;
    }

    private function commitInsertField(): void
    {
        if ($this->insertCols === []) {
            $this->mode = 'data';

            return;
        }
        $col = $this->insertCols[$this->insertIdx];
        $this->insertValues[$col] = $this->insertBuf;
        $this->insertBuf = '';
        if ($this->insertIdx >= count($this->insertCols) - 1) {
            $result = $this->db->insertRow($this->table, $this->insertValues);
            $this->status = ($result['ok'] ? '✓ ' : '✗ ') . $result['message'];
            $this->mode = 'data';
            $this->cacheKey = '';

            return;
        }
        ++$this->insertIdx;
    }

    private function startInsert(): void
    {
        if ($this->table === '') {
            return;
        }
        $this->entered = true;
        $this->mode = 'insert';
        $this->insertCols = array_map(
            static fn (array $c): string => $c['name'],
            $this->db->columns($this->table)
        );
        $this->insertValues = [];
        $this->insertIdx = 0;
        $this->insertBuf = '';
        $this->status = 'Insert into ' . $this->table . ' (empty = NULL for nullable)';
    }

    private function openSql(): void
    {
        $this->entered = true;
        $this->mode = 'sql';
        if ($this->table !== '' && !str_contains($this->sql, $this->table)) {
            $this->sql = 'SELECT * FROM ' . $this->table . ' LIMIT 50';
            $this->sqlCursor = strlen($this->sql);
        }
        $this->refreshSuggestions();
    }

    private function runSql(): void
    {
        $this->sqlResult = $this->db->execSql($this->sql);
        $this->status = ($this->sqlResult['ok'] ? '✓ ' : '✗ ') . $this->sqlResult['message'];
        $this->cacheKey = '';
        $this->db->refreshTables();
    }

    private function doDelete(): void
    {
        $page = $this->ensurePage();
        $raw = $page['raw'][$this->rowFocus] ?? null;
        if ($raw === null) {
            $this->status = 'No row selected';

            return;
        }
        $result = $this->db->deleteRow($this->table, $raw);
        $this->status = ($result['ok'] ? '✓ ' : '✗ ') . $result['message'];
        $this->cacheKey = '';
        $this->rowFocus = max(0, $this->rowFocus - 1);
    }

    private function enterTable(): void
    {
        if ($this->table === '') {
            $this->syncTableFromFocus();
        }
        if ($this->table === '') {
            return;
        }
        $this->entered = true;
        $this->mode = 'data';
        $this->page = 1;
        $this->rowFocus = 0;
        $this->cacheKey = '';
        $this->status = 'Opened ' . $this->table;
    }

    private function syncTableFromFocus(bool $resetPage = false): void
    {
        $tables = $this->db->tables();
        if ($tables === []) {
            $this->table = '';

            return;
        }
        $name = $tables[$this->focus] ?? $tables[0];
        if ($name !== $this->table || $resetPage) {
            $this->table = $name;
            $this->page = 1;
            $this->rowFocus = 0;
            $this->cacheKey = '';
        }
    }

    private function refreshSuggestions(): void
    {
        $cols = array_map(
            static fn (array $c): string => $c['name'],
            $this->table !== '' ? $this->db->columns($this->table) : []
        );
        $this->sqlSuggestions = ServeDbSql::suggestions(
            $this->sql,
            $this->sqlCursor,
            $this->db->tables(),
            $cols
        );
    }

    /**
     * @param list<string> $tables
     *
     * @return list<string>
     */
    private function renderBrowse(int $width, int $bodyRows, int $screenY0, array $tables): array
    {
        $sideW = min(self::SIDE_W, max(16, (int) floor($width * 0.22)));
        $rightW = max(24, $width - $sideW - 3);
        $listH = max(1, $bodyRows - 2);
        $this->ensureTableVisible($listH);

        $lines = [];
        $leftH = $this->ansi('1;90', $this->padPlain('tables · Enter to open', $sideW));
        $rightH = $this->ansi('90', $this->padPlain(
            $this->table !== '' ? $this->table . ' · preview (Enter)' : 'select a table',
            $rightW
        ));
        $lines[] = $leftH . $this->ansi('90', ' │ ') . $rightH;

        $slice = array_slice($tables, $this->tableScroll, $listH);
        $preview = $this->previewLines($rightW, $listH);

        for ($i = 0; $i < $listH; $i++) {
            $name = $slice[$i] ?? null;
            if ($name !== null) {
                $selected = $name === $this->table;
                $marker = $selected ? '▸ ' : '  ';
                $label = $marker . $this->clip($name, $sideW - 2);
                $left = $selected
                    ? $this->ansi('1;36', $this->padPlain($label, $sideW))
                    : $this->ansi('37', $this->padPlain($label, $sideW));
                $sy = $screenY0 + 1 + $i;
                $this->hits[] = ['x1' => 3, 'y1' => $sy, 'x2' => 2 + $sideW, 'y2' => $sy, 'action' => 'table', 'payload' => $name];
            } else {
                $left = str_repeat(' ', $sideW);
            }
            $right = $preview[$i] ?? str_repeat(' ', $rightW);
            $lines[] = $left . $this->ansi('90', ' │ ') . $right;
        }

        if ($this->status !== null && $lines !== []) {
            $lines[count($lines) - 1] = $this->ansi('33', $this->padPlain($this->clip($this->status, $width), $width));
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function renderEntered(int $width, int $bodyRows, int $screenY0): array
    {
        $lines = [];
        $toolbar = $this->toolbar($width);
        $lines[] = $toolbar;
        $this->addToolbarHits($width, $screenY0);

        $remain = $bodyRows - 1;
        if ($this->status !== null) {
            $lines[] = $this->ansi(
                str_starts_with($this->status, '✗') ? '31' : '32',
                $this->padPlain($this->clip($this->status, $width), $width)
            );
            --$remain;
        }

        if ($this->mode === 'sql') {
            return array_merge($lines, $this->renderSql($width, max(1, $remain)));
        }
        if ($this->mode === 'insert') {
            return array_merge($lines, $this->renderInsert($width, max(1, $remain)));
        }
        if ($this->mode === 'structure') {
            return array_merge($lines, $this->renderStructure($width, max(1, $remain)));
        }

        return array_merge($lines, $this->renderData($width, max(1, $remain), $screenY0 + count($lines)));
    }

    private function toolbar(int $width): string
    {
        $modes = [
            'data' => 'data',
            'structure' => 'structure',
            'sql' => 'sql',
        ];
        $parts = [];
        foreach ($modes as $key => $label) {
            $parts[] = $this->mode === $key
                ? $this->ansi('1;36', '[' . $label . ']')
                : $this->ansi('90', ' ' . $label . ' ');
        }
        $meta = sprintf(' %s', $this->table);
        if ($this->mode === 'data') {
            $page = $this->ensurePage();
            $pages = max(1, (int) ceil($page['total'] / ServeDbBrowser::PAGE_SIZE));
            if ($this->page > $pages) {
                $this->page = $pages;
                $this->cacheKey = '';
                $page = $this->ensurePage();
            }
            $meta .= sprintf(' · %d rows · %d/%d · row %d · Esc back', $page['total'], $this->page, $pages, $this->rowFocus + 1);
        } else {
            $meta .= ' · Esc back';
        }
        $plain = preg_replace('/\033\[[0-9;]*m/', '', implode('', $parts) . $meta) ?? '';
        $pad = max(0, $width - mb_strlen($plain));

        return implode('', $parts) . $this->ansi('90', $meta) . str_repeat(' ', $pad);
    }

    private function addToolbarHits(int $width, int $y): void
    {
        $this->hits[] = ['x1' => 3, 'y1' => $y, 'x2' => 10, 'y2' => $y, 'action' => 'mode', 'payload' => 'data'];
        $this->hits[] = ['x1' => 11, 'y1' => $y, 'x2' => 24, 'y2' => $y, 'action' => 'mode', 'payload' => 'structure'];
        $this->hits[] = ['x1' => 25, 'y1' => $y, 'x2' => 32, 'y2' => $y, 'action' => 'mode', 'payload' => 'sql'];
        $this->hits[] = ['x1' => max(3, $width - 6), 'y1' => $y, 'x2' => max(3, $width), 'y2' => $y, 'action' => 'back', 'payload' => ''];
    }

    /**
     * @return list<string>
     */
    private function renderSql(int $width, int $rows): array
    {
        $out = [];
        $out[] = $this->ansi('90', $this->padPlain('SQL editor · Tab autocomplete · Enter run', $width));
        $hl = ServeDbSql::highlight($this->sql);
        // Cursor marker
        $plain = $this->sql;
        $cur = $this->sqlCursor;
        $shown = $this->clip($plain, max(8, $width - 2));
        // Re-highlight clipped? simpler: show highlighted full then pad
        $line = $hl;
        $vis = $this->visibleLen($line);
        if ($vis > $width) {
            $line = $this->ansi('37', $shown);
            $vis = mb_strlen($shown);
        }
        $cursorHint = $this->ansi('1;47;30', ' ');
        // Approximate cursor in plain display line
        $prefix = $this->ansi('90', '› ');
        $out[] = $prefix . $line . ($cur >= strlen($plain) ? $cursorHint : '') . str_repeat(' ', max(0, $width - 2 - $vis - 1));

        if ($this->sqlSuggestions !== []) {
            $sug = '  ' . implode(' · ', array_slice($this->sqlSuggestions, 0, 8));
            $out[] = $this->ansi('35', $this->padPlain($this->clip($sug, $width), $width));
        }

        if ($this->sqlResult !== null) {
            $out[] = $this->ansi(
                $this->sqlResult['ok'] ? '32' : '31',
                $this->padPlain($this->clip($this->sqlResult['message'], $width), $width)
            );
            if ($this->sqlResult['columns'] !== []) {
                $colW = max(6, (int) floor(($width - 1) / min(8, count($this->sqlResult['columns']))));
                $showCols = array_slice($this->sqlResult['columns'], 0, (int) max(1, floor($width / $colW)));
                $header = [];
                foreach ($showCols as $c) {
                    $header[] = $this->padPlain($this->clip($c, $colW - 1), $colW - 1);
                }
                $out[] = $this->ansi('1;90', $this->padPlain(implode('│', $header), $width));
                foreach ($this->sqlResult['rows'] as $row) {
                    if (count($out) >= $rows) {
                        break;
                    }
                    $cells = [];
                    foreach ($showCols as $i => $_) {
                        $cells[] = $this->padPlain($this->clip($row[$i] ?? '', $colW - 1), $colW - 1);
                    }
                    $out[] = $this->ansi('37', $this->padPlain(implode('│', $cells), $width));
                }
            }
        }

        while (count($out) < $rows) {
            $out[] = str_repeat(' ', $width);
        }

        return array_slice($out, 0, $rows);
    }

    /**
     * @return list<string>
     */
    private function renderInsert(int $width, int $rows): array
    {
        $out = [];
        $out[] = $this->ansi('1;36', $this->padPlain(
            sprintf('INSERT %s · field %d/%d', $this->table, $this->insertIdx + 1, max(1, count($this->insertCols))),
            $width
        ));
        $col = $this->insertCols[$this->insertIdx] ?? '?';
        $meta = $this->db->columns($this->table);
        $type = '';
        foreach ($meta as $c) {
            if ($c['name'] === $col) {
                $type = $c['type'] . ($c['nullable'] ? ' NULL' : ' NOT NULL');
                break;
            }
        }
        $out[] = $this->ansi('90', $this->padPlain($col . ' (' . $type . ')', $width));
        $out[] = $this->ansi('37', $this->padPlain('› ' . $this->insertBuf . '█', $width));
        $out[] = $this->ansi('90', $this->padPlain('Enter = next / save · type NULL for null · Esc cancel', $width));
        while (count($out) < $rows) {
            $out[] = str_repeat(' ', $width);
        }

        return array_slice($out, 0, $rows);
    }

    /**
     * @return list<string>
     */
    private function renderStructure(int $width, int $rows): array
    {
        $cols = $this->db->columns($this->table);
        $pks = $this->db->primaryKeys($this->table);
        $out = [];
        $out[] = $this->ansi('1;90', $this->padPlain(
            $this->clip('name', 28) . ' ' . $this->clip('type', 24) . ' null  pk',
            $width
        ));
        foreach ($cols as $c) {
            if (count($out) >= $rows) {
                break;
            }
            $pk = in_array($c['name'], $pks, true) ? 'PK' : '';
            $line = sprintf(
                '%s %s %s  %s',
                $this->padPlain($this->clip($c['name'], 28), 28),
                $this->padPlain($this->clip($c['type'], 24), 24),
                $c['nullable'] ? 'YES' : 'NO ',
                $pk
            );
            $out[] = $this->ansi('37', $this->padPlain($this->clip($line, $width), $width));
        }
        while (count($out) < $rows) {
            $out[] = str_repeat(' ', $width);
        }

        return array_slice($out, 0, $rows);
    }

    /**
     * @return list<string>
     */
    private function renderData(int $width, int $rows, int $screenY0): array
    {
        $page = $this->ensurePage();
        $columns = $page['columns'];
        $data = $page['rows'];
        if ($columns === []) {
            $out = [$this->ansi('90', $this->padPlain('(empty table) · i insert · / sql', $width))];
            while (count($out) < $rows) {
                $out[] = str_repeat(' ', $width);
            }

            return $out;
        }

        $colW = max(8, (int) floor(($width - 1) / min(10, count($columns))));
        $showCols = array_slice($columns, 0, (int) max(1, floor($width / $colW)));
        $out = [];
        $headerCells = [];
        foreach ($showCols as $c) {
            $headerCells[] = $this->padPlain($this->clip($c, $colW - 1), $colW - 1);
        }
        $out[] = $this->ansi('1;90', $this->padPlain(implode('│', $headerCells), $width));

        foreach ($data as $ri => $row) {
            if (count($out) >= $rows) {
                break;
            }
            $cells = [];
            foreach ($showCols as $i => $_) {
                $cells[] = $this->padPlain($this->clip($row[$i] ?? '', $colW - 1), $colW - 1);
            }
            $line = implode('│', $cells);
            $selected = $ri === $this->rowFocus;
            $out[] = $selected
                ? $this->ansi('1;36', $this->padPlain($this->clip($line, $width), $width))
                : $this->ansi('37', $this->padPlain($this->clip($line, $width), $width));
            $sy = $screenY0 + count($out) - 1;
            $this->hits[] = [
                'x1' => 3,
                'y1' => $sy,
                'x2' => max(3, $width),
                'y2' => $sy,
                'action' => 'row',
                'payload' => (string) $ri,
            ];
        }
        while (count($out) < $rows) {
            $out[] = str_repeat(' ', $width);
        }

        return array_slice($out, 0, $rows);
    }

    /**
     * @return list<string>
     */
    private function previewLines(int $width, int $rows): array
    {
        if ($this->table === '') {
            return array_fill(0, $rows, $this->ansi('90', '← select · Enter open'));
        }
        $page = $this->ensurePage();
        $out = [];
        $out[] = $this->ansi('90', $this->padPlain(
            sprintf('%d rows · Enter to open · / sql', $page['total']),
            $width
        ));
        $columns = $page['columns'];
        if ($columns === []) {
            while (count($out) < $rows) {
                $out[] = str_repeat(' ', $width);
            }

            return $out;
        }
        $colW = max(6, (int) floor(($width - 1) / min(5, count($columns))));
        $showCols = array_slice($columns, 0, (int) max(1, floor($width / $colW)));
        $header = [];
        foreach ($showCols as $c) {
            $header[] = $this->padPlain($this->clip($c, $colW - 1), $colW - 1);
        }
        $out[] = $this->ansi('1;90', $this->padPlain(implode('│', $header), $width));
        foreach ($page['rows'] as $row) {
            if (count($out) >= $rows) {
                break;
            }
            $cells = [];
            foreach ($showCols as $i => $_) {
                $cells[] = $this->padPlain($this->clip($row[$i] ?? '', $colW - 1), $colW - 1);
            }
            $out[] = $this->ansi('37', $this->padPlain(implode('│', $cells), $width));
        }
        while (count($out) < $rows) {
            $out[] = str_repeat(' ', $width);
        }

        return array_slice($out, 0, $rows);
    }

    /**
     * @return array{columns: list<string>, rows: list<list<string>>, raw: list<array<string, mixed>>, total: int}
     */
    private function ensurePage(): array
    {
        $key = $this->table . '|' . $this->page;
        if ($this->cacheKey === $key && $this->pageCache !== null) {
            return $this->pageCache;
        }
        $this->cacheKey = $key;
        $this->pageCache = $this->db->page($this->table, $this->page);

        return $this->pageCache;
    }

    private function ensureTableVisible(int $listH): void
    {
        if ($this->focus < $this->tableScroll) {
            $this->tableScroll = $this->focus;
        }
        if ($this->focus >= $this->tableScroll + $listH) {
            $this->tableScroll = $this->focus - $listH + 1;
        }
        $this->tableScroll = max(0, $this->tableScroll);
    }

    private function padPlain(string $s, int $width): string
    {
        $len = mb_strlen($s);
        if ($len >= $width) {
            return mb_substr($s, 0, $width);
        }

        return $s . str_repeat(' ', $width - $len);
    }

    private function clip(string $s, int $width): string
    {
        if ($width <= 1) {
            return '…';
        }
        if (mb_strlen($s) <= $width) {
            return $s;
        }

        return mb_substr($s, 0, $width - 1) . '…';
    }

    private function visibleLen(string $s): int
    {
        return mb_strlen(preg_replace('/\033\[[0-9;]*m/', '', $s) ?? $s);
    }

    private function ansi(string $code, string $text): string
    {
        return "\033[{$code}m{$text}\033[0m";
    }
}
