<?php

declare(strict_types=1);

namespace Gereq\Console\Serve;

/**
 * Best-effort process termination that never blocks for long.
 * Used by serve --hot/--queue workers so Ctrl+C cannot hang the CLI.
 */
final class ProcessKiller
{
    /** Kill $pid (and descendants / process group). Returns within ~200ms. */
    public static function force(int $pid): void
    {
        if ($pid <= 1 || !self::alive($pid)) {
            return;
        }

        // Dev workers: SIGKILL immediately (bun can ignore TERM for a long time).
        self::killTree($pid, 9);
        usleep(30_000);

        if (self::alive($pid)) {
            self::killTree($pid, 9);
            usleep(30_000);
        }
    }

    /**
     * Kill a proc_open handle. Avoids hanging forever on proc_close(php -S).
     *
     * @param resource $proc
     * @param array<int, resource|null> $pipes
     */
    public static function forceProc($proc, array $pipes = []): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }

        if (!is_resource($proc)) {
            return;
        }

        $status = @proc_get_status($proc);
        $pid = is_array($status) ? (int) ($status['pid'] ?? 0) : 0;
        if ($pid > 1) {
            self::killTree($pid, 9);
        } else {
            @proc_terminate($proc, 9);
        }

        // Reap so the resource destructor does not block on waitpid.
        $deadline = microtime(true) + 0.25;
        do {
            $status = @proc_get_status($proc);
            $running = is_array($status) && !empty($status['running']);
            if (!$running) {
                break;
            }
            if ($pid > 1) {
                self::killTree($pid, 9);
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        if (\function_exists('pcntl_waitpid') && $pid > 1) {
            $statusCode = 0;
            @pcntl_waitpid($pid, $statusCode, WNOHANG);
        }

        // Safe when the child is dead/zombie; if still stuck, skip and let
        // the OS reap after this CLI exits (avoid infinite waitpid).
        $status = @proc_get_status($proc);
        if (is_array($status) && empty($status['running'])) {
            @proc_close($proc);
        }
    }

    /**
     * True only for a live (non-zombie) process.
     * posix_kill(pid, 0) returns true for zombies — that made stop() retry forever
     * and confused callers; treat zombies as not alive.
     */
    public static function alive(int $pid): bool
    {
        if ($pid <= 1) {
            return false;
        }

        if (is_dir('/proc/' . $pid)) {
            $state = self::procState($pid);
            if ($state === 'Z' || $state === '') {
                return false;
            }

            return true;
        }

        if (\function_exists('posix_kill')) {
            if (!@\posix_kill($pid, 0)) {
                return false;
            }
            // Fallback platforms without /proc: assume alive if signal 0 works.
            return true;
        }

        exec('kill -0 ' . $pid . ' 2>/dev/null', $out, $code);

        return $code === 0;
    }

    public static function resetSignals(): void
    {
        if (!\function_exists('pcntl_signal')) {
            return;
        }
        @pcntl_signal(SIGINT, SIG_DFL);
        @pcntl_signal(SIGTERM, SIG_DFL);
        if (\defined('SIGALRM')) {
            @pcntl_signal(SIGALRM, SIG_DFL);
        }
    }

    /** Arm a hard deadline so a stuck cleanup cannot freeze the CLI. */
    public static function armCleanupAlarm(int $seconds = 2): void
    {
        if (!\function_exists('pcntl_alarm') || !\function_exists('pcntl_signal')) {
            return;
        }
        @pcntl_async_signals(true);
        @pcntl_signal(SIGALRM, static function (): void {
            fwrite(STDERR, "\nCleanup timed out — force exit.\n");
            exit(130);
        });
        @pcntl_alarm(max(1, $seconds));
    }

    public static function clearCleanupAlarm(): void
    {
        if (\function_exists('pcntl_alarm')) {
            @pcntl_alarm(0);
        }
    }

    /** Recursively signal $pid, its descendants, and its process group. */
    private static function killTree(int $pid, int $sig): void
    {
        foreach (self::descendantPids($pid) as $child) {
            if (\function_exists('posix_kill')) {
                @\posix_kill($child, $sig);
            } else {
                exec('kill -' . $sig . ' ' . $child . ' 2>/dev/null');
            }
        }

        if (\function_exists('posix_kill')) {
            @\posix_kill($pid, $sig);
            // Process group (setsid leaders share pgid == pid).
            @\posix_kill(-$pid, $sig);
        } else {
            exec('kill -' . $sig . ' ' . $pid . ' 2>/dev/null');
            exec('kill -' . $sig . ' -' . $pid . ' 2>/dev/null');
        }
    }

    /** @return list<int> */
    private static function descendantPids(int $pid): array
    {
        $found = [];
        $queue = [$pid];
        $seen = [$pid => true];

        while ($queue !== []) {
            $current = array_pop($queue);
            foreach (self::childPids($current) as $child) {
                if (isset($seen[$child])) {
                    continue;
                }
                $seen[$child] = true;
                $found[] = $child;
                $queue[] = $child;
            }
        }

        // Kill deepest children first.
        return array_reverse($found);
    }

    /** @return list<int> */
    private static function childPids(int $ppid): array
    {
        // Fast path (Linux): space-separated child PIDs.
        $file = '/proc/' . $ppid . '/task/' . $ppid . '/children';
        if (is_file($file)) {
            $raw = trim((string) @file_get_contents($file));
            if ($raw === '') {
                return [];
            }
            $out = [];
            foreach (preg_split('/\s+/', $raw) ?: [] as $part) {
                if (ctype_digit($part)) {
                    $out[] = (int) $part;
                }
            }

            return $out;
        }

        $children = [];
        $dirs = @scandir('/proc');
        if ($dirs === false) {
            return [];
        }
        foreach ($dirs as $entry) {
            if ($entry === '.' || $entry === '..' || !ctype_digit($entry)) {
                continue;
            }
            $child = (int) $entry;
            if ($child <= 1) {
                continue;
            }
            $stat = @file_get_contents('/proc/' . $child . '/stat');
            if ($stat === false) {
                continue;
            }
            // comm may contain parentheses — parse after the last ')'.
            $rp = strrpos($stat, ')');
            if ($rp === false) {
                continue;
            }
            $parts = explode(' ', substr($stat, $rp + 2));
            // parts[0]=state, parts[1]=ppid
            if (!isset($parts[1]) || (int) $parts[1] !== $ppid) {
                continue;
            }
            $children[] = $child;
        }

        return $children;
    }

    private static function procState(int $pid): string
    {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if ($stat === false) {
            return '';
        }
        if (preg_match('/\)\s+(\S+)/', $stat, $m) !== 1) {
            return '';
        }

        return $m[1];
    }
}
