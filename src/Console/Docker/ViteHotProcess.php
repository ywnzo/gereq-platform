<?php

declare(strict_types=1);

namespace Gereq\Console\Docker;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Host-side Bun watch rebuild (`bun run dev` → public/build) for Docker --hot.
 * No Vite HMR / public/hot — AssetManager serves hashed /build assets.
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
        if ($this->watchAlreadyRunning()) {
            $output->writeln('<comment>Bun asset watch už běží — používám ho (nespoustím nový).</comment>');

            return true;
        }

        if (!$this->bunAvailable()) {
            $output->writeln('<error>bun není v PATH. Nainstalujte Bun, nebo spusťte: bun run build</error>');

            return false;
        }

        if (!is_file($this->basePath . '/scripts/build.ts')) {
            $output->writeln('<error>Chybí scripts/build.ts (Bun bundler).</error>');

            return false;
        }

        if (!is_dir($this->basePath . '/node_modules')) {
            $output->writeln('<comment>Chybí node_modules — spouštím bun install…</comment>');
            $install = sprintf('cd %s && bun install', escapeshellarg($this->basePath));
            passthru($install, $installCode);
            if ($installCode !== 0 || !is_dir($this->basePath . '/node_modules')) {
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

        // Never leave a Vite-era hot marker — PHP would request /@vite/client.
        $this->clearHotMarker();

        $logFile = $logDir . '/bun-assets.log';
        $pidFile = $this->pidFile();
        $launcher = $tmpDir . '/bun-assets-start.sh';
        @unlink($pidFile);

        $script = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
cd "$1"
# nohup + background; script exits so PHP never blocks on the watch process
nohup bun run dev >>"$2" 2>&1 </dev/null &
echo $! > "$3"
SH;
        file_put_contents($launcher, $script);
        chmod($launcher, 0755);

        $cmd = sprintf(
            '%s %s %s %s',
            escapeshellarg($launcher),
            escapeshellarg($this->basePath),
            escapeshellarg($logFile),
            escapeshellarg($pidFile)
        );
        exec($cmd, $out, $code);
        usleep(80_000);

        $pidRaw = is_file($pidFile) ? trim((string) file_get_contents($pidFile)) : '';
        if ($code !== 0 || $pidRaw === '' || !ctype_digit($pidRaw)) {
            $output->writeln('<error>Nepodařilo se spustit bun run dev.</error>');
            if (is_file($logFile)) {
                $output->writeln('<fg=gray>Log: storage/logs/bun-assets.log</>');
            }

            return false;
        }

        $this->pid = (int) $pidRaw;
        $this->owned = true;

        if (!$this->waitUntilReady(45.0)) {
            $output->writeln('<error>Bun build watch nevytvořil public/build včas.</error>');
            $output->writeln('<fg=gray>Log: storage/logs/bun-assets.log</>');
            $this->stop($output);

            return false;
        }

        $output->writeln(sprintf(
            '<info>Bun watch</info> <fg=gray>·</> bun run dev (pid %d, log storage/logs/bun-assets.log)',
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
            $output->writeln(sprintf('<fg=gray>Zastavuji Bun watch (pid %d)…</>', $pid));
            $this->killTree($pid);
        }

        $this->clearPidFile();
        $this->clearHotMarker();
        $this->pid = null;
        $this->owned = false;
    }

    /** Stop watch started earlier via --hot -d (pid file). */
    public function stopFromPidFile(OutputInterface $output): void
    {
        $pid = $this->readPidFile();
        if ($pid === null) {
            // Legacy Vite-era pid file name.
            $legacy = $this->basePath . '/storage/tmp/vite-dev.pid';
            if (is_file($legacy)) {
                $raw = trim((string) file_get_contents($legacy));
                if ($raw !== '' && ctype_digit($raw)) {
                    $pid = (int) $raw;
                    @unlink($legacy);
                }
            }
        }
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
    }

    private function waitUntilReady(float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($this->manifestReady()) {
                return true;
            }
            if ($this->pid !== null && !$this->processAlive($this->pid)) {
                return false;
            }
            usleep(150_000);
        }

        return $this->manifestReady();
    }

    private function watchAlreadyRunning(): bool
    {
        $pid = $this->readPidFile();

        return $pid !== null && $this->processAlive($pid) && $this->manifestReady();
    }

    private function manifestReady(): bool
    {
        $candidates = [
            $this->basePath . '/public/build/.vite/manifest.json',
            $this->basePath . '/public/build/manifest.json',
        ];
        foreach ($candidates as $file) {
            if (is_file($file) && filesize($file) > 2) {
                return true;
            }
        }

        return false;
    }

    private function bunAvailable(): bool
    {
        $code = 0;
        exec('command -v bun >/dev/null 2>&1', $out, $code);

        return $code === 0;
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
        return $this->basePath . '/storage/tmp/bun-assets.pid';
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
