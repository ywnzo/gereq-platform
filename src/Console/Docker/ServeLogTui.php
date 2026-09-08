<?php

declare(strict_types=1);

namespace Gereq\Console\Docker;

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
    private bool $paused = false;
    private bool $showHelp = false;
    private bool $running = true;
    private int $cols = 100;
    private int $rows = 30;
    private string $stty = '';

    public function __construct(
        private readonly string $basePath,
        private readonly int $port,
        private readonly ?string $lanIp,
        private readonly bool $bringDownOnExit = true,
        private readonly bool $dashboard = false,
        private readonly bool $https = false,
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

                $status = proc_get_status($proc);
                if (!$status['running'] && $bufOut === '' && $bufErr === '') {
                    break;
                }
            }
        } finally {
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

                $status = proc_get_status($proc);
                if (!$status['running'] && $bufOut === '' && $bufErr === '') {
                    break;
                }

                $this->render();
            }
        } finally {
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
        if ($raw === '') {
            return;
        }
        if (str_contains($raw, "\x03") || str_contains(strtolower($raw), 'q')) {
            $this->running = false;
        }
        if (str_contains(strtolower($raw), 'a')) {
            $this->showAssets = !$this->showAssets;
            echo $this->c('dim', '  · assets ' . ($this->showAssets ? 'on' : 'off')) . "\n";
        }
        if (str_contains(strtolower($raw), 'p')) {
            $this->showPhpNoise = !$this->showPhpNoise;
            echo $this->c('dim', '  · php ' . ($this->showPhpNoise ? 'on' : 'off')) . "\n";
        }
        if (str_contains(strtolower($raw), 'n')) {
            $this->showNoise = !$this->showNoise;
            echo $this->c('dim', '  · noise ' . ($this->showNoise ? 'on' : 'off')) . "\n";
        }
        if (str_contains($raw, ' ')) {
            $this->paused = !$this->paused;
            echo $this->c('warn', $this->paused ? '  · paused' : '  · resumed') . "\n";
        }
    }

    private function handleDashboardInput(string $raw): void
    {
        if ($raw === '') {
            return;
        }
        if (str_contains($raw, "\x03")) {
            $this->running = false;

            return;
        }
        foreach (str_split($raw) as $ch) {
            match (strtolower($ch)) {
                'q' => $this->running = false,
                'c' => $this->clear(),
                'a' => $this->showAssets = !$this->showAssets,
                'p' => $this->showPhpNoise = !$this->showPhpNoise,
                'n' => $this->showNoise = !$this->showNoise,
                ' ' => $this->paused = !$this->paused,
                'h', '?' => $this->showHelp = !$this->showHelp,
                default => null,
            };
        }
        $this->render(force: true);
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
        echo $this->c('dim', 'filtr: assety+php+noise skryté · [q]uit [a]ssets [p]hp [n]oise [space] pause') . "\n\n";
    }

    private function formatStreamLine(AccessLogEntry $entry): string
    {
        if (!$entry->access) {
            $level = str_contains(strtolower($entry->path), 'warn') || str_contains(strtolower($entry->path), 'error')
                ? 'warn'
                : 'dim';
            return sprintf(
                '%s  %s  %s',
                $this->c('dim', '        '),
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
        $out[] = $this->boxRow($this->c('ok', '●') . ' nginx php redis queue   ' . $stats, $w);
        if ($this->lanIp !== null) {
            $out[] = $this->boxRow(sprintf('LAN http://%s:%d', $this->lanIp, $this->port), $w);
        }
        $out[] = $this->boxRow($this->c('dim', '[q]uit [c]lear [a]ssets [p]hp [n]oise [space] [h]elp'), $w);
        $out[] = $this->c('dim', '├' . str_repeat('─', $w - 2) . '┤');
        $out[] = $this->boxRow($this->c('dim', 'TIME      SVC    METHOD  CODE  SIZE   PATH'), $w);
        $out[] = $this->c('dim', '├' . str_repeat('─', $w - 2) . '┤');

        $used = count($out) + 1;
        $bodyRows = max(3, $h - $used);
        if ($this->showHelp) {
            foreach ([
                $this->c('lime', 'Ovládání'),
                '  q  ukončit (+ down stacku)',
                '  a  assety (/build, /media…/file)',
                '  p  php /index.php',
                '  n  nginx/redis systémový šum',
                '  c  clear · mezerník pause',
            ] as $line) {
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
