<?php

declare(strict_types=1);

namespace Gereq\Http;

use Gereq\Setting\Settings;

/**
 * Builds the Vite storefront from a configured source path.
 *
 * Path formats:
 * - local absolute/relative: `/var/www/fe`, `../fe`
 * - remote SSH: `user@host:/var/www/fe`
 */
final class StorefrontRebuild
{
    public const SETTING_PATH = 'storefront.path';
    public const SETTING_COMMAND = 'storefront.build_command';

    public const DEFAULT_COMMAND = 'bun run build';

    private const TIMEOUT_SEC = 600;

    public function __construct(
        private readonly Settings $settings,
        private readonly string $basePath,
    ) {
    }

    /**
     * @return array{ok: bool, remote: bool, path: string, command: string, exit_code: int, output: string, error: ?string}
     */
    public function run(?string $pathOverride = null, ?string $commandOverride = null): array
    {
        $rawPath = trim($pathOverride ?? (string) $this->settings->get(self::SETTING_PATH, ''));
        $command = trim($commandOverride ?? (string) $this->settings->get(self::SETTING_COMMAND, self::DEFAULT_COMMAND));
        if ($command === '') {
            $command = self::DEFAULT_COMMAND;
        }

        if ($rawPath === '') {
            return $this->fail($rawPath, $command, false, 'Není nastavena cesta ke storefrontu (storefront.path).');
        }

        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $rawPath) === 1
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $command) === 1) {
            return $this->fail($rawPath, $command, false, 'Cesta nebo příkaz obsahuje nepovolené znaky.');
        }

        $remote = $this->parseRemote($rawPath);
        if ($remote !== null) {
            return $this->runRemote($remote['userHost'], $remote['path'], $command);
        }

        return $this->runLocal($rawPath, $command);
    }

    /**
     * @return array{userHost: string, path: string}|null
     */
    public function parseRemote(string $raw): ?array
    {
        // user@host:/absolute/path  (path must start with /)
        if (preg_match('/^([^\s@]+@[^\s@:]+):(\/.+)$/', $raw, $m) !== 1) {
            return null;
        }

        return [
            'userHost' => $m[1],
            'path' => $m[2],
        ];
    }

    public function resolveLocalPath(string $raw): ?string
    {
        $candidate = $raw;
        if (!str_starts_with($candidate, DIRECTORY_SEPARATOR)
            && !preg_match('/^[A-Za-z]:[\\\\\\/]/', $candidate)) {
            $candidate = $this->basePath . DIRECTORY_SEPARATOR . $candidate;
        }

        $real = realpath($candidate);
        if ($real === false || !is_dir($real)) {
            return null;
        }

        return $real;
    }

    /**
     * @return array{ok: bool, remote: bool, path: string, command: string, exit_code: int, output: string, error: ?string}
     */
    private function runLocal(string $rawPath, string $command): array
    {
        $path = $this->resolveLocalPath($rawPath);
        if ($path === null) {
            $hint = $rawPath;
            if (is_file('/.dockerenv')) {
                $hint .= ' (v Dockeru musí být FE namountovaný — např. ../fe → /var/www/fe — nebo použij SSH: user@host:/absolutní/cesta)';
            }

            return $this->fail($rawPath, $command, false, 'Lokální cesta ke storefrontu neexistuje: ' . $hint);
        }

        if (!is_file($path . '/package.json')) {
            return $this->fail($path, $command, false, 'V cestě chybí package.json — není to storefront projekt?');
        }

        $shell = 'cd ' . escapeshellarg($path) . ' && ' . $command . ' 2>&1';
        $result = $this->execShell($shell);

        return [
            'ok' => $result['code'] === 0,
            'remote' => false,
            'path' => $path,
            'command' => $command,
            'exit_code' => $result['code'],
            'output' => $result['output'],
            'error' => $result['code'] === 0 ? null : 'Rebuild skončil s kódem ' . $result['code'] . '.',
        ];
    }

    /**
     * @return array{ok: bool, remote: bool, path: string, command: string, exit_code: int, output: string, error: ?string}
     */
    private function runRemote(string $userHost, string $path, string $command): array
    {
        $display = $userHost . ':' . $path;
        $remoteInner = 'cd ' . escapeshellarg($path) . ' && ' . $command;
        $shell = 'ssh -o BatchMode=yes -o ConnectTimeout=20 '
            . escapeshellarg($userHost)
            . ' '
            . escapeshellarg($remoteInner)
            . ' 2>&1';
        $result = $this->execShell($shell);

        return [
            'ok' => $result['code'] === 0,
            'remote' => true,
            'path' => $display,
            'command' => $command,
            'exit_code' => $result['code'],
            'output' => $result['output'],
            'error' => $result['code'] === 0 ? null : 'Vzdálený rebuild skončil s kódem ' . $result['code'] . '.',
        ];
    }

    /**
     * @return array{code: int, output: string}
     */
    private function execShell(string $shell): array
    {
        $prev = (int) ini_get('max_execution_time');
        @set_time_limit(self::TIMEOUT_SEC);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($shell, $descriptors, $pipes, $this->basePath, null);
        if (!is_resource($proc)) {
            if ($prev > 0) {
                @set_time_limit($prev);
            }

            return ['code' => 127, 'output' => 'Nepodařilo se spustit proces rebuildu.'];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = microtime(true) + self::TIMEOUT_SEC;
        while (true) {
            $status = proc_get_status($proc);
            $chunkOut = stream_get_contents($pipes[1]);
            $chunkErr = stream_get_contents($pipes[2]);
            if (is_string($chunkOut) && $chunkOut !== '') {
                $output .= $chunkOut;
            }
            if (is_string($chunkErr) && $chunkErr !== '') {
                $output .= $chunkErr;
            }
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                $output .= "\n[timeout after " . self::TIMEOUT_SEC . "s]\n";
                break;
            }
            usleep(50_000);
        }

        $tailOut = stream_get_contents($pipes[1]);
        $tailErr = stream_get_contents($pipes[2]);
        if (is_string($tailOut) && $tailOut !== '') {
            $output .= $tailOut;
        }
        if (is_string($tailErr) && $tailErr !== '') {
            $output .= $tailErr;
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($prev > 0) {
            @set_time_limit($prev);
        }

        $output = trim($output);
        if (strlen($output) > 20_000) {
            $output = substr($output, -20_000);
        }

        return ['code' => (int) $code, 'output' => $output];
    }

    /**
     * @return array{ok: bool, remote: bool, path: string, command: string, exit_code: int, output: string, error: ?string}
     */
    private function fail(string $path, string $command, bool $remote, string $error): array
    {
        return [
            'ok' => false,
            'remote' => $remote,
            'path' => $path,
            'command' => $command,
            'exit_code' => 1,
            'output' => '',
            'error' => $error,
        ];
    }
}
