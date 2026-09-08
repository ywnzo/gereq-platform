<?php

declare(strict_types=1);

namespace Gereq\Console\Docker;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Host-side Vite HMR (`bun run dev`) for Docker nginx → host.docker.internal:5173.
 */
final class ViteHotProcess
{
    public const PORT = 5173;

    private ?int $pid = null;
    private bool $owned = false;

    public function __construct(private readonly string $basePath)
    {
    }

    public function owned(): bool
    {
        return $this->owned && $this->pid !== null;
    }

    public function start(OutputInterface $output): bool
    {
        if ($this->isListening(self::PORT)) {
            if (!$this->hotMarkerPresent()) {
                $this->writeHotMarker();
            }
            $output->writeln(sprintf(
                '<comment>Vite HMR už běží na :%d — používám ho (nespoustím nový).</comment>',
                self::PORT
            ));

            return true;
        }

        if (!$this->bunAvailable()) {
            $output->writeln('<error>bun není v PATH. Nainstalujte Bun, nebo spusťte Vite ručně: bun run dev</error>');

            return false;
        }

        if (!is_dir($this->basePath . '/node_modules/vite')) {
            $output->writeln('<comment>Chybí node_modules — spouštím bun install…</comment>');
            $install = sprintf('cd %s && bun install', escapeshellarg($this->basePath));
            passthru($install, $installCode);
            if ($installCode !== 0 || !is_dir($this->basePath . '/node_modules/vite')) {
                $output->writeln('<error>bun install selhal. Spusťte ho ručně v kořeni projektu.</error>');

                return false;
            }
        }

        $tmpDir = $this->basePath . '/storage/tmp';
        $logDir = $this->basePath . '/storage/logs';
        foreach ([$tmpDir, $logDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                $output->writeln('<error>Nelze vytvořit ' . $dir . '</error>');

                return false;
            }
        }

        $logFile = $logDir . '/vite-dev.log';
        $pidFile = $this->pidFile();
        $launcher = $tmpDir . '/vite-hot-start.sh';
        @unlink($pidFile);

        $script = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
cd "$1"
export VITE_PORT="$2"
# nohup + background; script exits so PHP never blocks on Vite
nohup bun run dev >>"$3" 2>&1 </dev/null &
echo $! > "$4"
SH;
        file_put_contents($launcher, $script);
        chmod($launcher, 0755);

        $cmd = sprintf(
            '%s %s %d %s %s',
            escapeshellarg($launcher),
            escapeshellarg($this->basePath),
            self::PORT,
            escapeshellarg($logFile),
            escapeshellarg($pidFile)
        );
        exec($cmd, $out, $code);
        usleep(80_000);

        $pidRaw = is_file($pidFile) ? trim((string) file_get_contents($pidFile)) : '';
        if ($code !== 0 || $pidRaw === '' || !ctype_digit($pidRaw)) {
            $output->writeln('<error>Nepodařilo se spustit bun run dev.</error>');
            if (is_file($logFile)) {
                $output->writeln('<fg=gray>Log: storage/logs/vite-dev.log</>');
            }

            return false;
        }

        $this->pid = (int) $pidRaw;
        $this->owned = true;

        if (!$this->waitUntilReady(25.0)) {
            $output->writeln('<error>Vite se nespustil včas (port ' . self::PORT . ').</error>');
            $output->writeln('<fg=gray>Log: storage/logs/vite-dev.log</>');
            $this->stop($output);

            return false;
        }

        // Safety: PHP AssetManager needs public/hot (Vite plugin may race with buildStart).
        $this->writeHotMarker();

        $output->writeln(sprintf(
            '<info>Vite HMR</info> <fg=gray>·</> bun run dev na <info>:%d</info> (pid %d, log storage/logs/vite-dev.log)',
            self::PORT,
            $this->pid
        ));

        return true;
    }

    public function stop(OutputInterface $output): void
    {
        if (!$this->owned) {
            return;
        }

        $pid = $this->pid ?? $this->readPidFile();
        if ($pid !== null && $this->processAlive($pid)) {
            $output->writeln(sprintf('<fg=gray>Zastavuji Vite (pid %d)…</>', $pid));
            $this->killTree($pid);
        }

        $this->clearPidFile();
        $this->clearHotMarker();
        $this->pid = null;
        $this->owned = false;
    }

    /** Stop Vite started earlier via --hot -d (pid file). */
    public function stopFromPidFile(OutputInterface $output): void
    {
        $pid = $this->readPidFile();
        if ($pid === null) {
            return;
        }
        $this->pid = $pid;
        $this->owned = true;
        $this->stop($output);
    }

    private function killTree(int $pid): void
    {
        exec('pkill -TERM -P ' . $pid . ' 2>/dev/null');
        $this->signal($pid, 'TERM');

        $deadline = microtime(true) + 4.0;
        while (microtime(true) < $deadline && $this->processAlive($pid)) {
            usleep(100_000);
        }

        if ($this->processAlive($pid)) {
            exec('pkill -KILL -P ' . $pid . ' 2>/dev/null');
            $this->signal($pid, 'KILL');
        }

        if ($this->isListening(self::PORT)) {
            $vitePids = [];
            exec(
                'pgrep -f ' . escapeshellarg($this->basePath . '/node_modules/.bin/vite') . ' 2>/dev/null',
                $vitePids
            );
            foreach ($vitePids as $raw) {
                if (ctype_digit(trim($raw))) {
                    $this->signal((int) $raw, 'TERM');
                }
            }
            usleep(200_000);
            if ($this->isListening(self::PORT)) {
                foreach ($vitePids as $raw) {
                    if (ctype_digit(trim($raw))) {
                        $this->signal((int) $raw, 'KILL');
                    }
                }
            }
        }
    }

    private function waitUntilReady(float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($this->isListening(self::PORT)) {
                return true;
            }
            if ($this->pid !== null && !$this->processAlive($this->pid)) {
                return false;
            }
            usleep(150_000);
        }

        return $this->isListening(self::PORT);
    }

    private function bunAvailable(): bool
    {
        $code = 0;
        exec('command -v bun >/dev/null 2>&1', $out, $code);

        return $code === 0;
    }

    private function isListening(int $port): bool
    {
        $errno = 0;
        $errstr = '';
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($connection)) {
            fclose($connection);

            return true;
        }

        return false;
    }

    private function hotMarkerPresent(): bool
    {
        return is_file($this->basePath . '/public/hot');
    }

    private function writeHotMarker(): void
    {
        file_put_contents($this->basePath . '/public/hot', (string) self::PORT);
    }

    private function clearHotMarker(): void
    {
        $hot = $this->basePath . '/public/hot';
        if (is_file($hot)) {
            @unlink($hot);
        }
    }

    private function pidFile(): string
    {
        return $this->basePath . '/storage/tmp/vite-dev.pid';
    }

    private function readPidFile(): ?int
    {
        $file = $this->pidFile();
        if (!is_file($file)) {
            return null;
        }
        $raw = trim((string) file_get_contents($file));
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        return (int) $raw;
    }

    private function clearPidFile(): void
    {
        $file = $this->pidFile();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function processAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (\function_exists('posix_kill')) {
            return @\posix_kill($pid, 0);
        }
        exec('kill -0 ' . $pid . ' 2>/dev/null', $out, $code);

        return $code === 0;
    }

    private function signal(int $pid, string $sig): void
    {
        if (\function_exists('posix_kill')) {
            $no = $sig === 'KILL' ? 9 : 15;
            @\posix_kill($pid, $no);

            return;
        }
        exec('kill -' . $sig . ' ' . $pid . ' 2>/dev/null');
    }
}
