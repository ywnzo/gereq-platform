<?php

declare(strict_types=1);

namespace Gereq\Console\Docker;

use Gereq\Console\Serve\ProcessKiller;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Host-side queue worker (`php gereq queue:work`) for built-in serve --queue.
 */
final class QueueWorkProcess
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
        if ($this->alreadyRunning()) {
            $output->writeln('<comment>Queue worker už běží — používám ho (nespouštím nový).</comment>');

            return true;
        }

        $gereq = $this->basePath . '/gereq';
        if (!is_file($gereq)) {
            $output->writeln('<error>Chybí gereq entry script v kořeni projektu.</error>');

            return false;
        }

        $tmpDir = $this->basePath . '/storage/tmp';
        $logDir = $this->basePath . '/storage/logs';
        foreach ([$tmpDir, $logDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                $output->writeln('<error>Nelze vytvořit ' . $dir . '</error>');

                return false;
            }
        }

        $logFile = $logDir . '/queue-work.log';
        $pidFile = $this->pidFile();
        $launcher = $tmpDir . '/queue-work-start.sh';
        @unlink($pidFile);

        // setsid: own process group so Ctrl+C on `serve` does not SIGINT the worker.
        $script = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
cd "$1"
setsid nohup php gereq queue:work >>"$2" 2>&1 </dev/null &
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
            $output->writeln('<error>Nepodařilo se spustit php gereq queue:work.</error>');
            if (is_file($logFile)) {
                $output->writeln('<fg=gray>Log: storage/logs/queue-work.log</>');
            }

            return false;
        }

        $this->pid = (int) $pidRaw;
        $this->owned = true;

        if (!ProcessKiller::alive($this->pid)) {
            $output->writeln('<error>Queue worker ihned skončil.</error>');
            $output->writeln('<fg=gray>Log: storage/logs/queue-work.log</>');
            $this->clearPidFile();
            $this->pid = null;
            $this->owned = false;

            return false;
        }

        $output->writeln(sprintf(
            '<info>Queue</info> <fg=gray>·</> php gereq queue:work (pid %d, log storage/logs/queue-work.log)',
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
            $output->writeln(sprintf('<fg=gray>Zastavuji queue worker (pid %d)…</>', $pid));
            ProcessKiller::force($pid);
        }

        $this->clearPidFile();
        $this->pid = null;
        $this->owned = false;
    }

    private function alreadyRunning(): bool
    {
        $pid = $this->readPidFile();

        return $pid !== null && ProcessKiller::alive($pid);
    }

    private function pidFile(): string
    {
        return $this->basePath . '/storage/tmp/queue-work.pid';
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
