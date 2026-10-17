<?php

declare(strict_types=1);

namespace Gereq\Console\Docker;

use Gereq\Console\Serve\ProcessKiller;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Host-side Bun watch rebuild (`bun run dev` → public/build) for Docker --hot.
 */
final class AssetWatchProcess
{
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

        $this->clearLegacyViteHotMarker();

        $logFile = $logDir . '/bun-assets.log';
        $pidFile = $this->pidFile();
        $launcher = $tmpDir . '/bun-assets-start.sh';
        @unlink($pidFile);

        $script = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
cd "$1"
# setsid: own process group so Ctrl+C on `serve` does not SIGINT bun
setsid nohup bun run dev >>"$2" 2>&1 </dev/null &
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
        if ($pid !== null && ProcessKiller::alive($pid)) {
            $output->writeln(sprintf('<fg=gray>Zastavuji Bun watch (pid %d)…</>', $pid));
            ProcessKiller::force($pid);
        }

        $this->clearPidFile();
        $this->clearLegacyViteHotMarker();
        $this->pid = null;
        $this->owned = false;
    }

    /** Stop watch started earlier via --hot -d (pid file). */
    public function stopFromPidFile(OutputInterface $output): void
    {
        $pid = $this->readPidFile();
        if ($pid === null) {
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

    private function waitUntilReady(float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($this->manifestReady()) {
                return true;
            }
            if ($this->pid !== null && !ProcessKiller::alive($this->pid)) {
                return false;
            }
            usleep(150_000);
        }

        return $this->manifestReady();
    }

    private function watchAlreadyRunning(): bool
    {
        $pid = $this->readPidFile();

        return $pid !== null && ProcessKiller::alive($pid) && $this->manifestReady();
    }

    private function manifestReady(): bool
    {
        $file = $this->basePath . '/public/build/manifest.json';

        return is_file($file) && filesize($file) > 2;
    }

    private function bunAvailable(): bool
    {
        $code = 0;
        exec('command -v bun >/dev/null 2>&1', $out, $code);

        return $code === 0;
    }

    private function clearLegacyViteHotMarker(): void
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
}
