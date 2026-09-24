<?php

declare(strict_types=1);

namespace Gereq\Console\Docker;

use Gereq\Debug\NPlusOneLog;

/**
 * Compact Docker log viewer.
 * Default: pretty scrolling stream (Cursor / IDE friendly).
 * --dashboard: full-screen interactive TUI.
 */
final class ServeLogTui
{
    private const MAX_LINES = 400;
    private const MAX_COLS = 112;

    private AccessLogParser $parser;

    /** @var list<AccessLogEntry> */
    private array $lines = [];

    private int $req = 0;
    private int $err = 0;
    private int $hidden = 0;
    private bool $showAssets = false;
    private bool $showPhpNoise = false;
    private bool $showNoise = false;
    /** Nginx→Vite proxy access (304 spam); hidden by default. Vite log lines (svc hot) remain visible. */
    private bool $showHotProxy = false;
    private bool $paused = false;
    private bool $showHelp = false;
    private bool $running = true;
    private int $cols = 100;
    private int $rows = 30;
    private string $stty = '';
    /** Pending letter sequence (e.g. `r` → wait for `b` = rebuild, `n` → `nn` = N+1). */
    private string $keySeq = '';
    private float $keySeqAt = 0.0;
    /** @var resource|null */
    private $viteLogHandle = null;
    private int $viteLogPos = 0;

    public function __construct(
        private readonly string $basePath,
        private readonly int $port,
        private readonly ?string $lanIp,
        private readonly bool $bringDownOnExit = true,
        private readonly bool $dashboard = false,
        private readonly bool $https = false,
        private readonly bool $hot = false,
    ) {
        $this->parser = new AccessLogParser();
    }

    public function run(): int
    {
        if ($this->dashboard && $this->isInteractive()) {
            return $this->runDashboard();
        }

        return $this->runStream();
    }

    private function runStream(): int
    {
        $this->printStreamHeader();

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = array_merge(
            ['docker', 'compose'],
            $this->composeFileArgs(),
            ['--project-directory', $this->basePath, 'logs', '-f', '--tail=40'],
        );
        $proc = proc_open($cmd, $descriptors, $pipes, $this->basePath);
        if (!is_resource($proc)) {
            fwrite(STDERR, "Nelze spustit docker compose logs.\n");

            return 1;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        fclose($pipes[0]);

        $interactive = $this->isInteractive();
        if ($interactive) {
            $this->enterRawMode();
            stream_set_blocking(STDIN, false);
        }

        $bufOut = '';
        $bufErr = '';
        $this->openViteLog(seekEnd: true);

        try {
            while ($this->running) {
                $read = [$pipes[1], $pipes[2]];
                if ($interactive) {
                    $read[] = STDIN;
                }
                $write = null;
                $except = null;
                @stream_select($read, $write, $except, 0, 250_000);

                foreach ($read as $stream) {
                    if ($interactive && $stream === STDIN) {
                        $this->handleStreamInput((string) fread(STDIN, 64));
                        continue;
                    }
                    $chunk = fread($stream, 8192);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }
                    if ($stream === $pipes[1]) {
                        $bufOut .= $chunk;
                        $bufOut = $this->consumeStreamBuffer($bufOut);
                    } else {
                        $bufErr .= $chunk;
                        $bufErr = $this->consumeStreamBuffer($bufErr);
                    }
                }

                $this->pollViteLog(stream: true);
                if ($interactive) {
                    $this->flushPendingKeySeq(stream: true);
                }

                $status = proc_get_status($proc);
                if (!$status['running'] && $bufOut === '' && $bufErr === '') {
                    break;
                }
            }
        } finally {
            $this->closeViteLog();
            foreach ([1, 2] as $i) {
                if (isset($pipes[$i]) && is_resource($pipes[$i])) {
                    fclose($pipes[$i]);
                }
            }
            @proc_terminate($proc);
            @proc_close($proc);
            if ($interactive) {
                $this->restoreSttyOnly();
            }
        }

        echo "\n" . $this->c('dim', sprintf('— konec · req %d · err %d · skryto %d —', $this->req, $this->err, $this->hidden)) . "\n";

        if ($this->bringDownOnExit) {
            $this->composeDown();
        }

        return 0;
    }

    private function runDashboard(): int
    {
        $this->enterRawMode();
        $this->enterAltScreen();
        $this->hideCursor();
        $this->refreshSize();

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = array_merge(
            ['docker', 'compose'],
            $this->composeFileArgs(),
            ['--project-directory', $this->basePath, 'logs', '-f', '--tail=80'],
        );
        $proc = proc_open($cmd, $descriptors, $pipes, $this->basePath);
        if (!is_resource($proc)) {
            $this->restoreTerminal();
            fwrite(STDERR, "Nelze spustit docker compose logs.\n");

            return 1;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        stream_set_blocking(STDIN, false);
        fclose($pipes[0]);

        $bufOut = '';
        $bufErr = '';
        $this->openViteLog(seekEnd: true);
        $this->render(force: true);

        try {
            while ($this->running) {
                $read = [$pipes[1], $pipes[2], STDIN];
                $write = null;
                $except = null;
                @stream_select($read, $write, $except, 0, 200_000);

                foreach ($read as $stream) {
                    if ($stream === STDIN) {
                        $this->handleDashboardInput((string) fread(STDIN, 64));
                        continue;
                    }
                    $chunk = fread($stream, 8192);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }
                    if ($stream === $pipes[1]) {
                        $bufOut .= $chunk;
                        $bufOut = $this->consumeDashboardBuffer($bufOut);
                    } else {
                        $bufErr .= $chunk;
                        $bufErr = $this->consumeDashboardBuffer($bufErr);
                    }
                }

                $this->pollViteLog(stream: false);
                $this->flushPendingKeySeq(stream: false);

                $status = proc_get_status($proc);
                if (!$status['running'] && $bufOut === '' && $bufErr === '') {
                    break;
                }

                $this->render();
            }
        } finally {
            $this->closeViteLog();
            foreach ([1, 2] as $i) {
                if (isset($pipes[$i]) && is_resource($pipes[$i])) {
                    fclose($pipes[$i]);
                }
            }
            @proc_terminate($proc);
            @proc_close($proc);
            $this->restoreTerminal();
        }

        if ($this->bringDownOnExit) {
            $this->composeDown();
        }

        return 0;
    }

    private function consumeStreamBuffer(string $buf): string
    {
        while (($pos = strpos($buf, "\n")) !== false) {
            $line = substr($buf, 0, $pos);
            $buf = substr($buf, $pos + 1);
            if ($line === '' || $this->paused) {
                continue;
            }
            $entry = $this->parser->parse($line);
            if ($this->shouldHide($entry)) {
                $this->hidden++;
                continue;
            }
            if ($entry->access) {
                $this->req++;
                if ($entry->isError()) {
                    $this->err++;
                }
            }
            echo $this->formatStreamLine($entry) . "\n";
        }

        return $buf;
    }

    private function consumeDashboardBuffer(string $buf): string
    {
        while (($pos = strpos($buf, "\n")) !== false) {
            $line = substr($buf, 0, $pos);
            $buf = substr($buf, $pos + 1);
            if ($line === '' || $this->paused) {
                continue;
            }
            $entry = $this->parser->parse($line);
            if ($this->shouldHide($entry)) {
                $this->hidden++;
                continue;
            }
            if ($entry->access) {
                $this->req++;
                if ($entry->isError()) {
                    $this->err++;
                }
            }
            $this->lines[] = $entry;
            if (count($this->lines) > self::MAX_LINES) {
                $this->lines = array_slice($this->lines, -self::MAX_LINES);
            }
        }

        return $buf;
    }

    private function shouldHide(AccessLogEntry $entry): bool
    {
        // Hidden by default: static /build traffic. Bun watch lines (svc hot) always shown.
        if ($entry->access && $entry->isHotProxy() && !$this->showHotProxy) {
            return true;
        }
        if ($entry->access && !$this->showPhpNoise && $entry->isPhpIndexNoise()) {
            return true;
        }
        if ($entry->access && !$this->showAssets && $entry->isAsset()) {
            return true;
        }
        if (!$entry->access && !$this->showNoise && $entry->isSystemNoise()) {
            return true;
        }

        return false;
    }

    private function handleStreamInput(string $raw): void
    {
        $this->feedKeys($raw, stream: true);
    }

    private function handleDashboardInput(string $raw): void
    {
        if ($raw === '') {
            return;
        }
        $this->feedKeys($raw, stream: false);
        $this->render(force: true);
    }

    private function feedKeys(string $raw, bool $stream): void
    {
        if ($raw === '') {
            return;
        }
        foreach (str_split($raw) as $ch) {
            if ($ch === "\x03") {
                $this->running = false;
                $this->keySeq = '';

                continue;
            }
            if ($ch === ' ') {
                $this->keySeq = '';
                $this->paused = !$this->paused;
                if ($stream) {
                    echo $this->c('warn', $this->paused ? '  · paused' : '  · resumed') . "\n";
                }

                continue;
            }

            $key = strtolower($ch);
            if ($key === 'q') {
                $this->running = false;
                $this->keySeq = '';

                continue;
            }

            if (!ctype_alpha($key) && $key !== '?') {
                $this->keySeq = '';

                continue;
            }

            if (ctype_alpha($key)) {
                $this->keySeq .= $key;
                if (strlen($this->keySeq) > 4) {
                    $this->keySeq = substr($this->keySeq, -4);
                }
                if (str_ends_with($this->keySeq, 'nn')) {
                    $this->keySeq = '';
                    $this->dumpNPlusOnes($stream);

                    continue;
                }
                if (str_ends_with($this->keySeq, 'rb')) {
                    $this->keySeq = '';
                    $this->rebuildStyles($stream);

                    continue;
                }
                // Wait for second letter of [nn] / [rb].
                if (str_ends_with($this->keySeq, 'n') || str_ends_with($this->keySeq, 'r')) {
                    $this->keySeq = str_ends_with($this->keySeq, 'n') ? 'n' : 'r';
                    $this->keySeqAt = microtime(true);

                    continue;
                }
                $this->keySeq = '';
            }

            if ($key === 'a') {
                $this->showAssets = !$this->showAssets;
                if ($stream) {
                    echo $this->c('dim', '  · assets ' . ($this->showAssets ? 'on' : 'off')) . "\n";
                }
            } elseif ($key === 'p') {
                $this->showPhpNoise = !$this->showPhpNoise;
                if ($stream) {
                    echo $this->c('dim', '  · php ' . ($this->showPhpNoise ? 'on' : 'off')) . "\n";
                }
            } elseif ($key === 'h' && $this->hot) {
                $this->showHotProxy = !$this->showHotProxy;
                if ($stream) {
                    echo $this->c('dim', '  · hot proxy ' . ($this->showHotProxy ? 'on' : 'off')) . "\n";
                }
            } elseif ($key === 'c' && !$stream) {
                $this->clear();
            } elseif ($key === '?' || ($key === 'h' && !$this->hot && !$stream)) {
                $this->showHelp = !$this->showHelp;
            }
        }
    }

    /** After ~450 ms without a second key: lone `n` = toggle noise, lone `r` is discarded. */
    private function flushPendingKeySeq(bool $stream): void
    {
        if ($this->keySeq !== 'n' && $this->keySeq !== 'r') {
            return;
        }
        if ((microtime(true) - $this->keySeqAt) < 0.45) {
            return;
        }

        $pending = $this->keySeq;
        $this->keySeq = '';
        if ($pending !== 'n') {
            return;
        }

        $this->showNoise = !$this->showNoise;
        if ($stream) {
            echo $this->c('dim', '  · noise ' . ($this->showNoise ? 'on' : 'off')) . "\n";
        } else {
            $this->render(force: true);
        }
    }

    private function dumpNPlusOnes(bool $stream): void
    {
        NPlusOneLog::setPath($this->basePath . '/storage/logs/nplusone.log');
        $lines = NPlusOneLog::formatRecent(20);

        if ($stream) {
            echo $this->c('warn', '  · nn N+1 (20 min)') . "\n";
            foreach ($lines as $line) {
                echo $this->c('lime', $line) . "\n";
            }

            return;
        }

        $this->pushBuildLine('nn N+1 (20 min)');
        foreach ($lines as $line) {
            $this->pushBuildLine($line);
        }
        $this->render(force: true);
    }

    private function rebuildStyles(bool $stream): void
    {
        if ($stream) {
            echo $this->c('lime', '  · rebuild bun run build…') . "\n";
        } else {
            $this->pushBuildLine('bun run build…');
            $this->render(force: true);
        }

        $cmd = 'cd ' . escapeshellarg($this->basePath) . ' && bun run build 2>&1';
        $lines = [];
        $code = 0;
        exec($cmd, $lines, $code);

        if ($stream) {
            foreach ($lines as $line) {
                $trim = trim($line);
                if ($trim === '') {
                    continue;
                }
                echo $this->c('dim', '  ' . $this->truncate($trim, 100)) . "\n";
            }
            echo $code === 0
                ? $this->c('ok', '  · rebuild ok') . "\n"
                : $this->c('err', '  · rebuild failed (' . $code . ')') . "\n";

            return;
        }

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            $this->pushBuildLine($trim);
        }
        $this->pushBuildLine($code === 0 ? 'rebuild ok' : 'rebuild failed (' . $code . ')');
    }

    private function pushBuildLine(string $message): void
    {
        $this->lines[] = new AccessLogEntry(
            service: 'build',
            method: '',
            path: $message,
            status: 0,
            bytes: null,
            time: date('H:i:s'),
            raw: $message,
            access: false,
        );
        if (count($this->lines) > self::MAX_LINES) {
            $this->lines = array_slice($this->lines, -self::MAX_LINES);
        }
    }

    private function clear(): void
    {
        $this->lines = [];
        $this->req = 0;
        $this->err = 0;
        $this->hidden = 0;
    }

    private function printStreamHeader(): void
    {
        $w = 72;
        echo $this->c('lime', 'GEREQ') . $this->c('dim', ' · Docker logs') . "\n";
        if ($this->https) {
            echo $this->c('dim', 'https  ') . "https://127.0.0.1/admin\n";
            if ($this->lanIp !== null) {
                echo $this->c('dim', 'lan    ') . sprintf("https://%s/admin\n", $this->lanIp);
            }
            echo $this->c('dim', 'http   ') . sprintf("http://127.0.0.1:%d/admin\n", $this->port);
        } else {
            echo $this->c('dim', 'local  ') . sprintf("http://127.0.0.1:%d/admin\n", $this->port);
            if ($this->lanIp !== null) {
                echo $this->c('dim', 'lan    ') . sprintf("http://%s:%d/admin\n", $this->lanIp, $this->port);
            }
        }
        echo $this->c('dim', str_repeat('─', $w)) . "\n";
        echo $this->c('dim', 'TIME      SVC    METHOD  CODE  SIZE   PATH') . "\n";
        echo $this->c('dim', str_repeat('─', $w)) . "\n";
        $keys = '[q]uit [a]ssets [p]hp [n]oise [nn] N+1 [rb]uild';
        if ($this->hot) {
            $keys .= ' [h]ot';
        }
        $keys .= ' [space] pause';
        $filtr = 'assety+php+noise';
        if ($this->hot) {
            $filtr .= '+hot-proxy';
        }
        echo $this->c('dim', 'filtr: ' . $filtr . ' skryté · ' . $keys) . "\n\n";
    }

    private function formatStreamLine(AccessLogEntry $entry): string
    {
        if (!$entry->access) {
            $level = str_contains(strtolower($entry->path), 'warn') || str_contains(strtolower($entry->path), 'error')
                ? 'warn'
                : ($entry->service === 'hot' || $entry->service === 'build' ? 'lime' : 'dim');
            $time = $entry->time ?? '        ';

            return sprintf(
                '%s  %s  %s',
                $this->c('dim', str_pad($time, 8)),
                $this->c('svc', str_pad($entry->service, 5)),
                $this->c($level, $this->truncate($entry->path, 90))
            );
        }

        $code = str_pad((string) $entry->status, 4);
        $codeColored = match (true) {
            $entry->status >= 500 => $this->c('err', $code),
            $entry->status >= 400 => $this->c('warn', $code),
            $entry->status >= 300 => $this->c('cyan', $code),
            default => $this->c('ok', $code),
        };
        $method = str_pad($entry->method, 6);
        $methodColored = in_array($entry->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            ? $this->c('lime', $method)
            : $method;

        return sprintf(
            '%s  %s  %s  %s  %s  %s',
            $this->c('dim', str_pad($entry->time ?? '--:--:--', 8)),
            $this->c('svc', str_pad($entry->service, 5)),
            $methodColored,
            $codeColored,
            $this->c('dim', str_pad($entry->sizeLabel(), 5)),
            $entry->path
        );
    }

    private function render(bool $force = false): void
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
        $w = $this->cols;
        $h = max(12, $this->rows);
        $out = [];

        $title = ' GEREQ · Docker ';
        $url = $this->https
            ? ' https://127.0.0.1 '
            : sprintf(' http://127.0.0.1:%d ', $this->port);
        $mid = max(0, $w - mb_strlen($title) - mb_strlen($url) - 2);
        $out[] = $this->c('dim', '┌' . $title . str_repeat('─', $mid) . $url . '┐');

        $filters = [
            $this->showAssets ? $this->c('lime', 'assets') : $this->c('dim', 'assets'),
            $this->showPhpNoise ? $this->c('lime', 'php') : $this->c('dim', 'php'),
            $this->showNoise ? $this->c('lime', 'noise') : $this->c('dim', 'noise'),
        ];
        if ($this->hot) {
            $filters[] = $this->showHotProxy ? $this->c('lime', 'hot') : $this->c('dim', 'hot');
        }
        if ($this->paused) {
            $filters[] = $this->c('warn', 'PAUSED');
        }
        $stats = sprintf(
            'req %s  err %s  hide %s  %s',
            $this->c('text', (string) $this->req),
            $this->err > 0 ? $this->c('err', (string) $this->err) : $this->c('dim', '0'),
            $this->c('dim', (string) $this->hidden),
            implode(' ', $filters)
        );
        $svcLine = $this->hot
            ? 'nginx php redis queue hot'
            : 'nginx php redis queue';
        $out[] = $this->boxRow($this->c('ok', '●') . ' ' . $svcLine . '   ' . $stats, $w);
        if ($this->lanIp !== null) {
            $out[] = $this->boxRow(sprintf('LAN http://%s:%d', $this->lanIp, $this->port), $w);
        }
        $keys = '[q]uit [c]lear [a]ssets [p]hp [n]oise [nn] N+1 [rb]uild';
        if ($this->hot) {
            $keys .= ' [h]ot';
        }
        $keys .= ' [space] [?]help';
        $out[] = $this->boxRow($this->c('dim', $keys), $w);
        $out[] = $this->c('dim', '├' . str_repeat('─', $w - 2) . '┤');
        $out[] = $this->boxRow($this->c('dim', 'TIME      SVC    METHOD  CODE  SIZE   PATH'), $w);
        $out[] = $this->c('dim', '├' . str_repeat('─', $w - 2) . '┤');

        $used = count($out) + 1;
        $bodyRows = max(3, $h - $used);
        if ($this->showHelp) {
            $help = [
                $this->c('lime', 'Ovládání'),
                '  q  ukončit (+ down stacku)',
                '  a  assety (/build, /media…/file)',
                '  p  php /index.php',
                '  n  nginx/redis systémový šum',
                '  nn N+1 za 20 min (plné SQL)',
                '  rb bun run build (rebuild stylů)',
            ];
            if ($this->hot) {
                $help[] = '  h  Bun asset watch log';
            }
            $help[] = '  c  clear · mezerník pause';
            foreach ($help as $line) {
                $out[] = $this->boxRow($line, $w);
                $bodyRows--;
            }
            $out[] = $this->c('dim', '├' . str_repeat('─', $w - 2) . '┤');
            $bodyRows = max(1, $bodyRows - 1);
        }

        $visible = array_slice($this->lines, -max(1, $bodyRows));
        $pad = max(0, $bodyRows - count($visible));
        for ($i = 0; $i < $pad; $i++) {
            $out[] = $this->boxRow('', $w);
        }
        foreach ($visible as $entry) {
            $out[] = $this->boxRow($this->formatStreamLine($entry), $w);
        }
        $out[] = $this->c('dim', '└' . str_repeat('─', $w - 2) . '┘');

        return implode("\n", $out);
    }

    private function boxRow(string $content, int $w): string
    {
        $innerW = $w - 2;
        $vis = $this->visibleLen($content);
        if ($vis > $innerW) {
            $content = $this->c('dim', $this->truncate($this->stripAnsi($content), $innerW));
            $vis = $this->visibleLen($content);
        }

        return '│' . $content . str_repeat(' ', max(0, $innerW - $vis)) . '│';
    }

    private function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        if ($max <= 1) {
            return '…';
        }

        return mb_substr($text, 0, $max - 1) . '…';
    }

    private function c(string $role, string $text): string
    {
        $code = match ($role) {
            'dim' => '90',
            'text' => '37',
            'ok' => '32',
            'err' => '31',
            'warn' => '33',
            'cyan' => '36',
            'lime' => '92',
            'svc' => '96',
            default => '0',
        };

        return "\033[{$code}m{$text}\033[0m";
    }

    private function visibleLen(string $s): int
    {
        return mb_strlen($this->stripAnsi($s));
    }

    private function stripAnsi(string $s): string
    {
        return (string) preg_replace('/\033\[[0-9;]*m/', '', $s);
    }

    private function refreshSize(): void
    {
        $raw = trim((string) @shell_exec('stty size 2>/dev/null'));
        if (preg_match('/^(\d+)\s+(\d+)$/', $raw, $m) === 1) {
            $this->rows = max(12, min(60, (int) $m[1]));
            $this->cols = max(60, min(self::MAX_COLS, (int) $m[2]));

            return;
        }
        $this->cols = min(self::MAX_COLS, (int) (getenv('COLUMNS') ?: 100));
        $this->rows = min(40, (int) (getenv('LINES') ?: 30));
    }

    private function isInteractive(): bool
    {
        return stream_isatty(STDOUT) && stream_isatty(STDIN);
    }

    private function enterRawMode(): void
    {
        $this->stty = trim((string) @shell_exec('stty -g 2>/dev/null'));
        @shell_exec('stty -icanon -echo min 0 time 0 2>/dev/null');
    }

    private function enterAltScreen(): void
    {
        echo "\033[?1049h\033[H\033[J";
    }

    private function hideCursor(): void
    {
        echo "\033[?25l";
    }

    private function restoreSttyOnly(): void
    {
        if ($this->stty !== '') {
            @shell_exec('stty ' . escapeshellarg($this->stty) . ' 2>/dev/null');
        } else {
            @shell_exec('stty sane 2>/dev/null');
        }
    }

    private function restoreTerminal(): void
    {
        echo "\033[?25h\033[?1049l";
        $this->restoreSttyOnly();
    }

    private function composeDown(): void
    {
        $parts = array_merge(
            ['docker', 'compose'],
            $this->composeFileArgs(),
            ['--project-directory', $this->basePath, 'down'],
        );
        $cmd = implode(' ', array_map(static fn (string $p): string => escapeshellarg($p), $parts));
        passthru($cmd);
    }

    private function viteLogPath(): string
    {
        return $this->basePath . '/storage/logs/bun-assets.log';
    }

    private function openViteLog(bool $seekEnd): void
    {
        if (!$this->hot) {
            return;
        }
        $path = $this->viteLogPath();
        if (!is_file($path)) {
            return;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return;
        }
        $this->viteLogHandle = $handle;
        if ($seekEnd) {
            fseek($handle, 0, SEEK_END);
            $this->viteLogPos = (int) ftell($handle);
        }
    }

    private function closeViteLog(): void
    {
        if (is_resource($this->viteLogHandle)) {
            fclose($this->viteLogHandle);
        }
        $this->viteLogHandle = null;
        $this->viteLogPos = 0;
    }

    private function pollViteLog(bool $stream): void
    {
        if (!$this->hot || $this->paused) {
            return;
        }

        $path = $this->viteLogPath();
        if (!is_file($path)) {
            return;
        }

        if (!is_resource($this->viteLogHandle)) {
            $this->openViteLog(seekEnd: true);

            return;
        }

        clearstatcache(true, $path);
        $size = (int) filesize($path);
        if ($size < $this->viteLogPos) {
            // log rotated / truncated
            fseek($this->viteLogHandle, 0, SEEK_SET);
            $this->viteLogPos = 0;
        }
        if ($size === $this->viteLogPos) {
            return;
        }

        fseek($this->viteLogHandle, $this->viteLogPos, SEEK_SET);
        $chunk = stream_get_contents($this->viteLogHandle);
        if ($chunk === false || $chunk === '') {
            return;
        }
        $this->viteLogPos = (int) ftell($this->viteLogHandle);

        foreach (preg_split("/\r\n|\n|\r/", $chunk) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $entry = $this->parseViteLogLine($line);
            if ($stream) {
                echo $this->formatStreamLine($entry) . "\n";
            } else {
                $this->lines[] = $entry;
                if (count($this->lines) > self::MAX_LINES) {
                    $this->lines = array_slice($this->lines, -self::MAX_LINES);
                }
            }
        }
    }

    private function parseViteLogLine(string $line): AccessLogEntry
    {
        $line = (string) preg_replace('/\033\[[0-9;]*m/', '', $line);
        $line = trim($line);

        $time = date('H:i:s');
        $message = $line;

        // `10:07:19 AM [vite] (client) hmr update …` or `9:15:08 AM [vite] …`
        if (preg_match(
            '/^(?P<h>\d{1,2}):(?P<m>\d{2}):(?P<s>\d{2})\s*(?P<ampm>AM|PM)?\s*\[vite\]\s*(?P<msg>.+)$/i',
            $line,
            $m
        ) === 1) {
            $h = (int) $m['h'];
            $ampm = strtoupper((string) ($m['ampm'] ?? ''));
            if ($ampm === 'PM' && $h < 12) {
                $h += 12;
            } elseif ($ampm === 'AM' && $h === 12) {
                $h = 0;
            }
            $time = sprintf('%02d:%s:%s', $h, $m['m'], $m['s']);
            $message = trim((string) $m['msg']);
        } elseif (preg_match('/^\[vite\]\s*(?P<msg>.+)$/i', $line, $m) === 1) {
            $message = trim((string) $m['msg']);
        }

        return new AccessLogEntry(
            service: 'hot',
            method: '',
            path: $message,
            status: 0,
            bytes: null,
            time: $time,
            raw: $line,
            access: false,
        );
    }

    /** @return list<string> */
    private function composeFileArgs(): array
    {
        $args = ['-f', $this->basePath . '/docker-compose.yml'];
        if ($this->https) {
            $override = $this->basePath . '/docker-compose.https.yml';
            if (is_file($override)) {
                $args[] = '-f';
                $args[] = $override;
            }
        }

        return $args;
    }
}
