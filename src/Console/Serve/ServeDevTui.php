<?php

declare(strict_types=1);

namespace Gereq\Console\Serve;

use Gereq\Support\AdminPath;

/**
 * Interactive serve dashboard: Tab cycles app / db / queue / bun panes.
 * Ctrl+O toggles full request payload on the app pane.
 * Database pane is mouse-capable (SGR) Adminer-like browse + SQL + row CRUD.
 */
final class ServeDevTui
{
    private const MAX_LINES = 500;

    private readonly PhpBuiltinLogFormatter $formatter;

    /** @var list<string> */
    private array $tabs = ['app', 'db'];

    private int $tabIndex = 0;

    /** @var list<array{entry: PhpBuiltinLogEntry, count: int}> */
    private array $appRows = [];

    /** @var array<string, list<string>> */
    private array $sideLines = [
        'queue' => [],
        'bun' => [],
    ];

    private ?string $appSignature = null;

    private int $appCount = 0;

    private bool $payloadExpanded = false;

    private bool $running = true;

    private string $stty = '';

    private int $cols = 100;

    private int $rows = 30;

    /** @var resource|null */
    private $queueLog = null;

    private int $queuePos = 0;

    /** @var resource|null */
    private $bunLog = null;

    private int $bunPos = 0;

    private string $bufOut = '';

    private string $bufErr = '';

    private string $stdinBuf = '';

    private ?ServeDbPane $dbPane = null;

    /**
     * @param resource $serverProc
     * @param array{1?: resource, 2?: resource} $pipes
     */
    public function __construct(
        private readonly string $basePath,
        private $serverProc,
        private array $pipes,
        private readonly int $port,
        private readonly string $host,
        private readonly bool $hot,
        private readonly bool $queue,
        private readonly ?string $lanIp,
    ) {
        $this->formatter = new PhpBuiltinLogFormatter();
        if ($queue) {
            $this->tabs[] = 'queue';
        }
        if ($hot) {
            $this->tabs[] = 'bun';
        }
    }

    public function run(): int
    {
        $this->enterRawMode();
        $this->enterAltScreen();
        stream_set_blocking(STDIN, false);
        if (isset($this->pipes[1]) && is_resource($this->pipes[1])) {
            stream_set_blocking($this->pipes[1], false);
        }
        if (isset($this->pipes[2]) && is_resource($this->pipes[2])) {
            stream_set_blocking($this->pipes[2], false);
        }

        $this->openLog('queue', $this->basePath . '/storage/logs/queue-work.log');
        $this->openLog('bun', $this->basePath . '/storage/logs/bun-assets.log');
        $this->render(true);

        if (\function_exists('pcntl_async_signals') && \function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $stop = function (): void {
                $this->running = false;
            };
            pcntl_signal(SIGINT, $stop);
            pcntl_signal(SIGTERM, $stop);
        }

        try {
            while ($this->running) {
                $read = [];
                if (isset($this->pipes[1]) && is_resource($this->pipes[1])) {
                    $read[] = $this->pipes[1];
                }
                if (isset($this->pipes[2]) && is_resource($this->pipes[2])) {
                    $read[] = $this->pipes[2];
                }
                $read[] = STDIN;
                $write = null;
                $except = null;
                @stream_select($read, $write, $except, 0, 200_000);

                $stdinReady = false;
                foreach ($read as $stream) {
                    if ($stream === STDIN) {
                        $stdinReady = true;
                        $this->stdinBuf .= (string) fread(STDIN, 256);
                        $this->drainInput();
                        continue;
                    }
                    $chunk = fread($stream, 8192);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }
                    if (isset($this->pipes[1]) && $stream === $this->pipes[1]) {
                        $this->bufOut .= $chunk;
                        $this->consumeServerBuffer($this->bufOut);
                    } else {
                        $this->bufErr .= $chunk;
                        $this->consumeServerBuffer($this->bufErr);
                    }
                }

                // Lone ESC (no CSI follow-up after select timeout) → Escape in db pane.
                if (!$stdinReady && $this->stdinBuf === "\033" && $this->currentTab() === 'db') {
                    $this->stdinBuf = '';
                    $this->dbPane()->handleEscape();
                    $this->render(true);
                }

                $this->pollFileLog('queue');
                $this->pollFileLog('bun');

                $status = proc_get_status($this->serverProc);
                if (!$status['running']) {
                    $this->running = false;
                }

                $this->render(false);
            }
        } finally {
            $this->closeLogs();
            $this->restoreTerminal();
            ProcessKiller::resetSignals();
        }

        return 0;
    }

    private function drainInput(): void
    {
        while ($this->stdinBuf !== '') {
            if (str_starts_with($this->stdinBuf, "\x03")) {
                $this->stdinBuf = substr($this->stdinBuf, 1);
                $this->running = false;

                return;
            }

            // SGR mouse
            if (str_starts_with($this->stdinBuf, "\033[<")) {
                if (preg_match('/^\033\[<(\d+);(\d+);(\d+)([Mm])/', $this->stdinBuf, $m) !== 1) {
                    if (strlen($this->stdinBuf) < 32) {
                        return;
                    }
                    $this->stdinBuf = substr($this->stdinBuf, 1);
                    continue;
                }
                $this->stdinBuf = substr($this->stdinBuf, strlen($m[0]));
                if ($m[4] === 'M') {
                    $btn = (int) $m[1];
                    $x = (int) $m[2];
                    $y = (int) $m[3];
                    if ($btn === 0) {
                        $this->handleClick($x, $y);
                    } elseif (($btn === 64 || $btn === 65) && $this->currentTab() === 'db') {
                        $this->dbPane()->handleWheel($btn === 64 ? -1 : 1);
                        $this->render(true);
                    }
                }
                continue;
            }

            if (str_starts_with($this->stdinBuf, "\033")) {
                if (strlen($this->stdinBuf) < 2) {
                    // Lone ESC — wait for CSI or select-timeout flush in run().
                    return;
                }
                if ($this->stdinBuf[1] !== '[') {
                    $this->stdinBuf = substr($this->stdinBuf, 1);
                    if ($this->currentTab() === 'db') {
                        $this->dbPane()->handleEscape();
                        $this->render(true);
                    }
                    continue;
                }
                if (str_starts_with($this->stdinBuf, "\033[Z")) {
                    $this->stdinBuf = substr($this->stdinBuf, 3);
                    if (!$this->dbCapturing()) {
                        $this->tabIndex = ($this->tabIndex - 1 + count($this->tabs)) % count($this->tabs);
                        $this->render(true);
                    }
                    continue;
                }
                if (preg_match('/^\033\[([ABCD])/', $this->stdinBuf, $m) === 1) {
                    $this->stdinBuf = substr($this->stdinBuf, strlen($m[0]));
                    if ($this->currentTab() === 'db') {
                        $this->dbPane()->handleArrow($m[1]);
                        $this->render(true);
                    }
                    continue;
                }
                // Delete key
                if (str_starts_with($this->stdinBuf, "\033[3~")) {
                    $this->stdinBuf = substr($this->stdinBuf, 4);
                    if ($this->currentTab() === 'db') {
                        $this->dbPane()->handleKey("\x7f");
                        $this->render(true);
                    }
                    continue;
                }
                if (strlen($this->stdinBuf) < 8 && preg_match('/^\033\[[0-9;]*$/', $this->stdinBuf) === 1) {
                    return;
                }
                // Bare ESC[
                if ($this->stdinBuf === "\033[") {
                    return;
                }
                $this->stdinBuf = substr($this->stdinBuf, 1);
                continue;
            }

            $ch = $this->stdinBuf[0];
            $this->stdinBuf = substr($this->stdinBuf, 1);

            // When waiting on lone ESC that arrived as empty follow-up — handled above.

            if ($ch === "\t") {
                if ($this->currentTab() === 'db' && $this->dbPane()->handleTabComplete()) {
                    $this->render(true);
                    continue;
                }
                $this->tabIndex = ($this->tabIndex + 1) % count($this->tabs);
                $this->render(true);
                continue;
            }
            if ($ch === "\x0f") {
                $this->payloadExpanded = !$this->payloadExpanded;
                $this->render(true);
                continue;
            }

            if ($this->currentTab() === 'db') {
                // Enter
                if ($ch === "\n" || $ch === "\r") {
                    $this->dbPane()->handleKey($ch);
                    $this->render(true);
                    continue;
                }
                if ($this->dbCapturing()) {
                    $this->dbPane()->handleKey($ch);
                    $this->render(true);
                    continue;
                }
                if ($ch >= '1' && $ch <= '9') {
                    $i = (int) $ch - 1;
                    if (isset($this->tabs[$i])) {
                        $this->tabIndex = $i;
                        $this->render(true);
                    }
                    continue;
                }
                if ($ch === 'q' || $ch === 'Q') {
                    $this->running = false;

                    return;
                }
                if ($this->dbPane()->handleKey($ch)) {
                    $this->render(true);
                }
                continue;
            }

            if ($ch >= '1' && $ch <= '9') {
                $i = (int) $ch - 1;
                if (isset($this->tabs[$i])) {
                    $this->tabIndex = $i;
                    $this->render(true);
                }
                continue;
            }
            if ($ch === 'q' || $ch === 'Q') {
                $this->running = false;

                return;
            }
        }
    }

    private function dbCapturing(): bool
    {
        return $this->currentTab() === 'db' && $this->dbPane()->capturingInput();
    }

    private function handleClick(int $x, int $y): void
    {
        if ($y === 2) {
            $col = 3;
            foreach ($this->tabs as $i => $name) {
                $label = ($i + 1) . ':' . $name;
                $width = mb_strlen($label) + 2;
                if ($x >= $col && $x < $col + $width) {
                    $this->tabIndex = $i;
                    $this->render(true);

                    return;
                }
                $col += $width + 1;
            }

            return;
        }

        if ($this->currentTab() !== 'db') {
            return;
        }
        foreach ($this->dbPane()->hits as $hit) {
            if ($x >= $hit['x1'] && $x <= $hit['x2'] && $y >= $hit['y1'] && $y <= $hit['y2']) {
                $this->dbPane()->dispatchHit($hit['action'], $hit['payload']);
                $this->render(true);

                return;
            }
        }
    }

    private function currentTab(): string
    {
        return $this->tabs[$this->tabIndex] ?? 'app';
    }

    private function dbPane(): ServeDbPane
    {
        return $this->dbPane ??= new ServeDbPane($this->basePath);
    }

    private function consumeServerBuffer(string &$buffer): void
    {
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = rtrim(substr($buffer, 0, $pos), "\r");
            $buffer = substr($buffer, $pos + 1);
            if ($line === '') {
                continue;
            }
            $this->pushAppLine($line);
        }
    }

    private function pushAppLine(string $raw): void
    {
        $entry = $this->formatter->parse($raw);
        if ($entry === null || $entry->noise) {
            return;
        }

        $signature = $entry->access
            ? $entry->method . '|' . $entry->status . '|' . $entry->path
            : null;

        if ($signature !== null && $signature === $this->appSignature) {
            ++$this->appCount;
            $idx = count($this->appRows) - 1;
            if ($idx >= 0) {
                $this->appRows[$idx]['count'] = $this->appCount;
            }

            return;
        }

        $this->appSignature = $signature;
        $this->appCount = 1;
        $this->appRows[] = ['entry' => $entry, 'count' => 1];
        if (count($this->appRows) > self::MAX_LINES) {
            array_shift($this->appRows);
        }
    }

    private function openLog(string $tab, string $path): void
    {
        if ($tab === 'queue' && !$this->queue) {
            return;
        }
        if ($tab === 'bun' && !$this->hot) {
            return;
        }
        if (!is_file($path)) {
            @touch($path);
        }
        $h = @fopen($path, 'rb');
        if (!is_resource($h)) {
            return;
        }
        fseek($h, 0, SEEK_END);
        if ($tab === 'queue') {
            $this->queueLog = $h;
            $this->queuePos = (int) ftell($h);
        } else {
            $this->bunLog = $h;
            $this->bunPos = (int) ftell($h);
        }
    }

    private function pollFileLog(string $tab): void
    {
        $h = $tab === 'queue' ? $this->queueLog : $this->bunLog;
        if (!is_resource($h)) {
            return;
        }
        clearstatcache(false, $tab === 'queue'
            ? $this->basePath . '/storage/logs/queue-work.log'
            : $this->basePath . '/storage/logs/bun-assets.log');
        $pos = $tab === 'queue' ? $this->queuePos : $this->bunPos;
        fseek($h, $pos);
        $chunk = stream_get_contents($h);
        if ($chunk === false || $chunk === '') {
            return;
        }
        if ($tab === 'queue') {
            $this->queuePos = (int) ftell($h);
        } else {
            $this->bunPos = (int) ftell($h);
        }
        foreach (preg_split("/\r\n|\n|\r/", $chunk) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $this->sideLines[$tab][] = $this->ansi('37', $line);
            if (count($this->sideLines[$tab]) > self::MAX_LINES) {
                array_shift($this->sideLines[$tab]);
            }
        }
    }

    private function closeLogs(): void
    {
        if (is_resource($this->queueLog)) {
            fclose($this->queueLog);
        }
        if (is_resource($this->bunLog)) {
            fclose($this->bunLog);
        }
    }

    private function render(bool $force): void
    {
        static $last = '';
        $this->refreshSize();
        $frame = $this->buildFrame();
        if (!$force && $frame === $last) {
            return;
        }
        $last = $frame;
        echo "\033[H\033[J" . $frame;
    }

    private function buildFrame(): string
    {
        $w = max(40, $this->cols);
        $h = max(12, $this->rows);
        $out = [];

        $admin = AdminPath::base();
        $title = ' GEREQ serve ';
        $url = sprintf(' http://127.0.0.1:%d%s ', $this->port, $admin);
        $mid = max(0, $w - mb_strlen($title) - mb_strlen($url) - 2);
        $out[] = $this->ansi('90', '┌' . $title . str_repeat('─', $mid) . $url . '┐');

        $tabParts = [];
        foreach ($this->tabs as $i => $name) {
            $label = ($i + 1) . ':' . $name;
            if ($i === $this->tabIndex) {
                $tabParts[] = $this->ansi('1;36', '[' . $label . ']');
            } else {
                $tabParts[] = $this->ansi('90', ' ' . $label . ' ');
            }
        }
        $rightParts = [];
        if ($this->hot) {
            $rightParts[] = $this->ansi('90', 'hot');
        }
        if ($this->queue) {
            $rightParts[] = $this->ansi('90', 'queue');
        }
        if ($this->payloadExpanded && $this->currentTab() === 'app') {
            $rightParts[] = $this->ansi('95', 'payload open');
        }
        if ($this->currentTab() === 'db') {
            $rightParts[] = $this->ansi('90', $this->dbPane()->label());
        }
        $right = $rightParts !== [] ? implode(' · ', $rightParts) : '';

        $tabsLine = '│ ' . implode(' ', $tabParts);
        $pad = max(1, $w - 2 - $this->visibleLen($tabsLine) - $this->visibleLen($right));
        $out[] = $tabsLine . str_repeat(' ', $pad) . $right . $this->ansi('90', '│');
        $out[] = $this->ansi('90', '├' . str_repeat('─', max(0, $w - 2)) . '┤');

        $bodyRows = max(3, $h - 5);
        $tab = $this->currentTab();
        if ($tab === 'db') {
            $body = $this->dbPane()->render($w - 4, $bodyRows, 4);
            foreach ($body as $line) {
                $out[] = $this->padBodyLine($line, $w);
            }
            $missing = $bodyRows - count($body);
            for ($i = 0; $i < $missing; $i++) {
                $out[] = $this->ansi('90', '│') . str_repeat(' ', max(0, $w - 2)) . $this->ansi('90', '│');
            }
        } else {
            $body = $tab === 'app' ? $this->renderAppBody($w - 4) : ($this->sideLines[$tab] ?? []);
            $slice = array_slice($body, -$bodyRows);
            $padRows = $bodyRows - count($slice);
            for ($i = 0; $i < $padRows; $i++) {
                $out[] = $this->ansi('90', '│') . str_repeat(' ', max(0, $w - 2)) . $this->ansi('90', '│');
            }
            foreach ($slice as $line) {
                $out[] = $this->padBodyLine($line, $w);
            }
        }

        $hint = $tab === 'db'
            ? $this->dbPane()->footerHint()
            : ' Tab · click tabs · 1–' . count($this->tabs) . ' · Ctrl+O payload · Ctrl+C quit ';
        if ($this->lanIp !== null && $tab !== 'db') {
            $hint .= sprintf('· LAN http://%s:%d%s ', $this->lanIp, $this->port, $admin);
        }
        $footMid = max(0, $w - mb_strlen($hint) - 2);
        $out[] = $this->ansi('90', '└' . $hint . str_repeat('─', $footMid) . '┘');

        return implode("\n", $out);
    }

    private function padBodyLine(string $line, int $w): string
    {
        $vis = $this->visibleLen($line);
        if ($vis > $w - 4) {
            $plain = preg_replace('/\033\[[0-9;]*m/', '', $line) ?? $line;
            $line = $this->ansi('37', mb_substr($plain, 0, $w - 5) . '…');
            $vis = $this->visibleLen($line);
        }
        $padRight = max(0, $w - 4 - $vis);

        return $this->ansi('90', '│') . ' ' . $line . str_repeat(' ', $padRight) . ' ' . $this->ansi('90', '│');
    }

    /** @return list<string> */
    private function renderAppBody(int $width): array
    {
        $rows = [];
        foreach ($this->appRows as $row) {
            $formatted = $this->formatter->formatAnsiRows(
                $row['entry'],
                $row['count'],
                $this->payloadExpanded,
                $width
            );
            if ($formatted === null) {
                continue;
            }
            foreach ($formatted as $line) {
                $rows[] = $line;
            }
        }

        return $rows;
    }

    private function visibleLen(string $s): int
    {
        return mb_strlen(preg_replace('/\033\[[0-9;]*m/', '', $s) ?? $s);
    }

    private function ansi(string $code, string $text): string
    {
        return "\033[{$code}m{$text}\033[0m";
    }

    private function refreshSize(): void
    {
        // Use the full terminal — no artificial 160×60 cap.
        $this->cols = max(40, (int) (getenv('COLUMNS') ?: 100));
        $this->rows = max(12, (int) (getenv('LINES') ?: 30));
        if (\function_exists('shell_exec')) {
            $sz = trim((string) @shell_exec('stty size 2>/dev/null'));
            if (preg_match('/^(\d+)\s+(\d+)$/', $sz, $m) === 1) {
                $this->rows = max(12, (int) $m[1]);
                $this->cols = max(40, (int) $m[2]);
            }
        }
    }

    private function enterRawMode(): void
    {
        $this->stty = trim((string) @shell_exec('stty -g 2>/dev/null'));
        @shell_exec('stty -icanon -echo min 0 time 0 2>/dev/null');
    }

    private function enterAltScreen(): void
    {
        echo "\033[?1049h\033[?25l\033[?1000h\033[?1006h\033[H\033[J";
    }

    private function restoreTerminal(): void
    {
        echo "\033[?1006l\033[?1000l\033[?25h\033[?1049l";
        if ($this->stty !== '') {
            @shell_exec('stty ' . escapeshellarg($this->stty) . ' 2>/dev/null');
        } else {
            @shell_exec('stty sane 2>/dev/null');
        }
    }
}
