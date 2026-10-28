<?php

declare(strict_types=1);

namespace Gereq\Module;

/**
 * Lazily builds per-module admin CSS/JS into public/build via Bun.
 * Invoked on enable, module update, migrate, and `assets:ensure`.
 * Removed from disk + manifest on disable.
 */
final class ModuleAssetBuilder
{
    private const TIMEOUT_SEC = 300;

    /** @var callable(string, list<string>): array{code: int, output: string}|null */
    private $runner;

    /**
     * @param (callable(string, list<string>): array{code: int, output: string})|null $runner
     */
    public function __construct(
        private readonly string $basePath,
        ?callable $runner = null,
        private readonly bool $prebuilt = false,
    ) {
        $this->runner = $runner;
    }

    public function hasSources(string $id): bool
    {
        $paths = $this->sourcePaths($id);

        return $paths['js'] !== null || $paths['css'] !== null;
    }

    public function isBuilt(string $id): bool
    {
        $record = $this->manifestRecord($id);
        if ($record === null) {
            return false;
        }
        $file = $record['file'] ?? '';
        if (!is_string($file) || $file === '') {
            return false;
        }
        $abs = $this->basePath . '/public/build/' . ltrim($file, '/');

        return is_file($abs);
    }

    /**
     * True when sources exist but the built output is missing or older than sources.
     */
    public function isStale(string $id): bool
    {
        $id = strtolower(trim($id));
        if ($id === '' || !$this->hasSources($id)) {
            return false;
        }
        if ($this->prebuilt) {
            return !$this->validPrebuilt($id);
        }
        if (!$this->isBuilt($id)) {
            return true;
        }

        $sourceMtime = $this->sourcesMtime($id);
        $builtMtime = $this->builtMtime($id);
        if ($sourceMtime === null || $builtMtime === null) {
            return true;
        }

        return $sourceMtime > $builtMtime;
    }

    /**
     * Build module assets when missing/stale (or always when $force).
     * No-op when the module has no admin.ts / admin.css.
     * Throws when Bun is required but unavailable (enable / update / assets:ensure).
     * Prefer {@see tryEnsure()} from migrate paths that must soft-fail without Bun.
     */
    public function ensure(string $id, bool $force = false): void
    {
        $id = strtolower(trim($id));
        if ($id === '' || !$this->hasSources($id)) {
            return;
        }
        if ($this->prebuilt) {
            if (!$this->validPrebuilt($id)) {
                throw new \RuntimeException('Prebuilt module assets do not match source: ' . $id);
            }

            return;
        }
        if (!$force && !$this->isStale($id)) {
            return;
        }

        if (!$this->bunAvailable()) {
            throw new \RuntimeException(
                'Module assets for "' . $id . '" are missing or stale and bun is not available in PATH. '
                . 'Install Bun, or run: bun run build:module -- ' . $id,
            );
        }

        $script = $this->basePath . '/scripts/build-module.ts';
        if (!is_file($script)) {
            throw new \RuntimeException('Missing scripts/build-module.ts — cannot build module assets.');
        }

        $result = $this->run($this->basePath, ['bun', $script, $id]);
        if ($result['code'] !== 0) {
            $tail = trim($result['output']);
            if (strlen($tail) > 2000) {
                $tail = substr($tail, -2000);
            }
            throw new \RuntimeException(
                'Failed to build assets for module "' . $id . '"'
                . ($tail !== '' ? ': ' . $tail : ' (exit ' . $result['code'] . ').'),
            );
        }
    }

    private function validPrebuilt(string $id): bool
    {
        $file = $this->basePath . '/public/build/module-source-hashes.json';
        if (!$this->isBuilt($id) || !is_file($file)) {
            return false;
        }
        $hashes = json_decode((string) file_get_contents($file), true);
        $path = $this->sourcePaths($id)['path'];
        if (!is_array($hashes) || !is_string($hashes[$id] ?? null) || $path === null
            || !hash_equals($hashes[$id], ModuleFootprint::ofDirectory($path))) {
            return false;
        }
        foreach (($this->manifestRecord($id)['css'] ?? []) as $css) {
            if (!is_string($css) || !is_file($this->basePath . '/public/build/' . $css)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Soft-fail variant for migrate / deploy loops: returns null on success or skip,
     * or an error message when build fails (schema migrate must still succeed).
     */
    public function tryEnsure(string $id, bool $force = false): ?string
    {
        try {
            $this->ensure($id, $force);

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Ensure assets for every module id that has sources.
     *
     * @param list<string> $ids
     * @return list<string> built or skipped ids that have sources
     */
    public function ensureMany(array $ids, bool $force = false): array
    {
        $done = [];
        foreach ($ids as $id) {
            if (!is_string($id) || $id === '') {
                continue;
            }
            if (!$this->hasSources($id)) {
                continue;
            }
            $this->ensure($id, $force);
            $done[] = strtolower($id);
        }

        return $done;
    }

    /**
     * Delete built files for a module and drop its manifest entry.
     * Safe no-op when nothing is built. Also sweeps leftover module-{id}-* hashes.
     */
    public function remove(string $id): void
    {
        $id = strtolower(trim($id));
        if ($id === '' || !preg_match('/^[a-z0-9_]+$/', $id)) {
            return;
        }

        $manifestFile = $this->basePath . '/public/build/manifest.json';
        $stale = [];
        $decoded = [];
        $manifestChanged = false;
        if (is_file($manifestFile)) {
            $raw = json_decode((string) file_get_contents($manifestFile), true);
            if (is_array($raw)) {
                $decoded = $raw;
                $key = 'module:' . $id;
                $record = $decoded[$key] ?? null;
                if (is_array($record)) {
                    $file = $record['file'] ?? null;
                    if (is_string($file) && $file !== '') {
                        $stale[] = $file;
                    }
                    if (isset($record['css']) && is_array($record['css'])) {
                        foreach ($record['css'] as $css) {
                            if (is_string($css) && $css !== '') {
                                $stale[] = $css;
                            }
                        }
                    }
                    unset($decoded[$key]);
                    $manifestChanged = true;
                }
            }
        }

        foreach (array_unique($stale) as $rel) {
            $abs = $this->basePath . '/public/build/' . ltrim(str_replace('\\', '/', $rel), '/');
            if (is_file($abs)) {
                @unlink($abs);
            }
            $map = $abs . '.map';
            if (is_file($map)) {
                @unlink($map);
            }
        }

        // Leftover hashed / watch outputs not listed in the current record.
        $assetsDir = $this->basePath . '/public/build/assets';
        if (is_dir($assetsDir)) {
            foreach (glob($assetsDir . '/module-' . $id . '-*') ?: [] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            foreach (glob($assetsDir . '/module-' . $id . '.*') ?: [] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        if ($manifestChanged) {
            file_put_contents(
                $manifestFile,
                json_encode($decoded, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            );
        }
    }

    /**
     * @param list<string> $ids
     */
    public function removeMany(array $ids): void
    {
        foreach ($ids as $id) {
            if (is_string($id) && $id !== '') {
                $this->remove($id);
            }
        }
    }

    /**
     * Resolve asset paths without ModuleDiscovery class validation (works in unit tests).
     *
     * @return array{js: ?string, css: ?string, path: ?string}
     */
    private function sourcePaths(string $id): array
    {
        foreach (['modules', 'integrations'] as $directory) {
            foreach (glob($this->basePath . '/' . $directory . '/*/module.php') ?: [] as $file) {
                /** @var mixed $data */
                $data = require $file;
                if (!is_array($data) || strtolower((string) ($data['id'] ?? '')) !== $id) {
                    continue;
                }
                $modPath = dirname($file);
                $js = $modPath . '/Resources/assets/js/admin.ts';
                $css = $modPath . '/Resources/assets/css/admin.css';

                return [
                    'path' => $modPath,
                    'js' => is_file($js) ? $js : null,
                    'css' => is_file($css) ? $css : null,
                ];
            }
            // Bundled submodules: modules/<Parent>/Submodules/<Child>/module.php
            foreach (glob($this->basePath . '/' . $directory . '/*/Submodules/*/module.php') ?: [] as $file) {
                /** @var mixed $data */
                $data = require $file;
                if (!is_array($data) || strtolower((string) ($data['id'] ?? '')) !== $id) {
                    continue;
                }
                $modPath = dirname($file);
                $js = $modPath . '/Resources/assets/js/admin.ts';
                $css = $modPath . '/Resources/assets/css/admin.css';

                return [
                    'path' => $modPath,
                    'js' => is_file($js) ? $js : null,
                    'css' => is_file($css) ? $css : null,
                ];
            }
        }

        return ['path' => null, 'js' => null, 'css' => null];
    }

    /** @return array<string, mixed>|null */
    private function manifestRecord(string $id): ?array
    {
        $file = $this->basePath . '/public/build/manifest.json';
        if (!is_file($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded)) {
            return null;
        }
        $key = 'module:' . $id;
        $raw = $decoded[$key] ?? null;

        return is_array($raw) ? $raw : null;
    }

    private function sourcesMtime(string $id): ?int
    {
        $paths = $this->sourcePaths($id);
        $mtime = 0;
        $found = false;
        foreach ([$paths['js'], $paths['css']] as $file) {
            if (!is_string($file) || $file === '' || !is_file($file)) {
                continue;
            }
            $found = true;
            $mtime = max($mtime, (int) filemtime($file));
        }

        return $found ? $mtime : null;
    }

    private function builtMtime(string $id): ?int
    {
        $record = $this->manifestRecord($id);
        if ($record === null) {
            return null;
        }

        $mtime = null;
        $rels = [];
        $file = $record['file'] ?? null;
        if (is_string($file) && $file !== '') {
            $rels[] = $file;
        }
        if (isset($record['css']) && is_array($record['css'])) {
            foreach ($record['css'] as $css) {
                if (is_string($css) && $css !== '') {
                    $rels[] = $css;
                }
            }
        }

        foreach ($rels as $rel) {
            $abs = $this->basePath . '/public/build/' . ltrim(str_replace('\\', '/', $rel), '/');
            if (!is_file($abs)) {
                return null;
            }
            $t = (int) filemtime($abs);
            $mtime = $mtime === null ? $t : min($mtime, $t);
        }

        return $mtime;
    }

    private function bunAvailable(): bool
    {
        $result = $this->run($this->basePath, ['bash', '-lc', 'command -v bun >/dev/null 2>&1']);

        return $result['code'] === 0;
    }

    /**
     * @param list<string> $command
     * @return array{code: int, output: string}
     */
    private function run(string $cwd, array $command): array
    {
        if ($this->runner !== null) {
            return ($this->runner)($cwd, $command);
        }

        $prev = (int) ini_get('max_execution_time');
        @set_time_limit(self::TIMEOUT_SEC);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($command, $descriptors, $pipes, $cwd, null);
        if (!is_resource($proc)) {
            if ($prev > 0) {
                @set_time_limit($prev);
            }

            return ['code' => 127, 'output' => 'Failed to start process.'];
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

        return ['code' => (int) $code, 'output' => trim($output)];
    }
}
