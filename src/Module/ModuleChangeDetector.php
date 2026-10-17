<?php

declare(strict_types=1);

namespace Gereq\Module;

/**
 * File-level change set for the module update gate.
 *
 * Compares the live module tree to the last installed baseline (snapshot or
 * version-cache), never a full git worktree dump against HEAD.
 */
final class ModuleChangeDetector
{
    private const MAX_FILES = 80;

    public function __construct(
        private readonly ModuleManager $modules,
        private readonly ModuleInstalledSnapshot $snapshots,
        private readonly ModuleVersionStore $versions,
    ) {
    }

    public function detect(string $moduleId): ?ModuleChangeSet
    {
        $manifest = $this->modules->manifest($moduleId);
        if ($manifest === null) {
            return null;
        }

        $row = $this->modules->installed($moduleId);
        $installedVersion = $row?->version() ?? '';
        $fileVersion = $manifest->version;
        $sameVersion = $installedVersion !== '' && $installedVersion === $fileVersion;

        $liveFootprint = ModuleFootprint::ofDirectory($manifest->path);
        $installedFootprint = $row?->footprint();
        $footprintDrift = $liveFootprint !== ''
            && $installedFootprint !== null
            && $installedFootprint !== ''
            && $installedFootprint !== $liveFootprint
            && $installedFootprint !== ModuleFootprint::ofDirectory($manifest->path, legacy: true);

        $live = ModuleFootprint::inventory($manifest->path);
        $baseline = $this->resolveBaseline($moduleId, $installedVersion, $installedFootprint);

        if ($baseline === null) {
            return $this->buildSet(
                $moduleId,
                $fileVersion,
                $installedVersion,
                $sameVersion,
                $footprintDrift,
                [],
                0,
                0,
                'none',
            );
        }

        $rows = $this->diffInventories(
            $live,
            $baseline['files'],
            $manifest->path,
            $baseline['tree'],
        );

        if ($baseline['tree'] === null && $rows !== []) {
            $rows = $this->enrichLineStatsFromGit($manifest->path, $rows);
        }

        $additions = 0;
        $deletions = 0;
        foreach ($rows as $rowFile) {
            $additions += $rowFile['additions'];
            $deletions += $rowFile['deletions'];
        }

        return $this->buildSet(
            $moduleId,
            $fileVersion,
            $installedVersion,
            $sameVersion,
            $footprintDrift,
            $rows,
            $additions,
            $deletions,
            $baseline['source'],
        );
    }

    /**
     * @return array{files: array<string, string>, tree: ?string, source: string}|null
     */
    private function resolveBaseline(string $moduleId, string $installedVersion, ?string $installedFootprint): ?array
    {
        $cachePath = $this->baselineTreePath($moduleId, $installedVersion);
        if ($cachePath !== null) {
            $files = ModuleFootprint::inventory($cachePath);
            if ($files !== []) {
                return ['files' => $files, 'tree' => $cachePath, 'source' => 'version_cache'];
            }
        }

        $snap = $this->snapshots->read($moduleId);
        if ($snap === null || $snap['files'] === []) {
            return null;
        }
        // Only trust a snapshot that describes the installed DB footprint.
        if (
            $installedFootprint !== null
            && $installedFootprint !== ''
            && $snap['footprint'] !== ''
            && $snap['footprint'] !== $installedFootprint
        ) {
            return null;
        }

        return ['files' => $snap['files'], 'tree' => null, 'source' => 'snapshot'];
    }

    private function baselineTreePath(string $moduleId, string $installedVersion): ?string
    {
        if ($installedVersion === '' || !$this->versions->has($moduleId, $installedVersion)) {
            return null;
        }
        $path = $this->versions->pathFor($moduleId, $installedVersion);

        return is_dir($path) ? $path : null;
    }

    /**
     * @param array<string, string> $live
     * @param array<string, string> $baseline
     * @return list<array{path: string, status: 'added'|'modified'|'deleted', additions: int, deletions: int}>
     */
    private function diffInventories(array $live, array $baseline, string $liveRoot, ?string $baselineRoot): array
    {
        $paths = array_unique(array_merge(array_keys($live), array_keys($baseline)));
        sort($paths, SORT_STRING);
        $out = [];
        foreach ($paths as $rel) {
            $liveHash = $live[$rel] ?? null;
            $baseHash = $baseline[$rel] ?? null;
            if ($liveHash === $baseHash) {
                continue;
            }
            if ($liveHash !== null && $baseHash === null) {
                $lines = $this->countLines($liveRoot . '/' . $rel);
                $out[] = ['path' => $rel, 'status' => 'added', 'additions' => $lines, 'deletions' => 0];
            } elseif ($liveHash === null && $baseHash !== null) {
                $lines = $baselineRoot !== null ? $this->countLines($baselineRoot . '/' . $rel) : 0;
                $out[] = ['path' => $rel, 'status' => 'deleted', 'additions' => 0, 'deletions' => $lines];
            } elseif ($baselineRoot !== null) {
                [$add, $del] = $this->fileLineDiff(
                    $baselineRoot . '/' . $rel,
                    $liveRoot . '/' . $rel,
                );
                $out[] = ['path' => $rel, 'status' => 'modified', 'additions' => $add, 'deletions' => $del];
            } else {
                // Digest-only baseline — line stats filled later from scoped git, if available.
                $out[] = ['path' => $rel, 'status' => 'modified', 'additions' => 0, 'deletions' => 0];
            }
            if (count($out) >= self::MAX_FILES) {
                break;
            }
        }

        return $out;
    }

    /**
     * Fill +/- for digest-only diffs using git, limited to the already-detected paths.
     *
     * @param list<array{path: string, status: 'added'|'modified'|'deleted', additions: int, deletions: int}> $rows
     * @return list<array{path: string, status: 'added'|'modified'|'deleted', additions: int, deletions: int}>
     */
    private function enrichLineStatsFromGit(string $modulePath, array $rows): array
    {
        if (!$this->isGitWorkTree($modulePath) || $rows === []) {
            return $rows;
        }

        $paths = array_values(array_map(static fn (array $row): string => $row['path'], $rows));
        $stats = $this->gitNumstatForPaths($modulePath, $paths);
        if ($stats === []) {
            return $rows;
        }

        foreach ($rows as $i => $row) {
            $hit = $stats[$row['path']] ?? null;
            if ($hit === null) {
                continue;
            }
            $rows[$i]['additions'] = $hit['additions'];
            $rows[$i]['deletions'] = $hit['deletions'];
            if ($row['status'] === 'modified' && $hit['additions'] > 0 && $hit['deletions'] === 0 && $this->gitIsUntrackedOrNew($modulePath, $row['path'])) {
                $rows[$i]['status'] = 'added';
            }
        }

        return $rows;
    }

    /**
     * @param list<string> $paths
     * @return array<string, array{additions: int, deletions: int}>
     */
    private function gitNumstatForPaths(string $modulePath, array $paths): array
    {
        if ($paths === []) {
            return [];
        }
        $args = array_merge(['diff', '--numstat', 'HEAD', '--'], $paths);
        $numstat = $this->gitOutput($modulePath, $args);
        $cached = $this->gitOutput($modulePath, array_merge(['diff', '--numstat', '--cached', 'HEAD', '--'], $paths));

        /** @var array<string, array{additions: int, deletions: int}> $byPath */
        $byPath = [];
        foreach ([$numstat ?? '', $cached ?? ''] as $blob) {
            foreach (preg_split("/\r\n|\n|\r/", $blob) ?: [] as $line) {
                if ($line === '') {
                    continue;
                }
                $parts = preg_split('/\t+/', $line) ?: [];
                if (count($parts) < 3) {
                    continue;
                }
                $path = $parts[2];
                if ($path === '' || !in_array($path, $paths, true)) {
                    continue;
                }
                $add = $parts[0] === '-' ? 0 : max(0, (int) $parts[0]);
                $del = $parts[1] === '-' ? 0 : max(0, (int) $parts[1]);
                if (!isset($byPath[$path])) {
                    $byPath[$path] = ['additions' => 0, 'deletions' => 0];
                }
                $byPath[$path]['additions'] += $add;
                $byPath[$path]['deletions'] += $del;
            }
        }

        return $byPath;
    }

    private function gitIsUntrackedOrNew(string $modulePath, string $rel): bool
    {
        return $this->gitOutput($modulePath, ['ls-files', '--error-unmatch', $rel]) === null;
    }

    private function isGitWorkTree(string $modulePath): bool
    {
        $git = $modulePath . '/.git';

        return is_dir($git) || is_file($git);
    }

    /**
     * @param list<string> $args
     */
    private function gitOutput(string $cwd, array $args): ?string
    {
        if (!is_dir($cwd)) {
            return null;
        }
        $cmd = array_merge(['git', '-C', $cwd], $args);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0 || !is_string($stdout)) {
            return null;
        }

        return $stdout;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function fileLineDiff(?string $oldPath, string $newPath): array
    {
        $old = ($oldPath !== null && is_file($oldPath)) ? (string) file_get_contents($oldPath) : '';
        $new = is_file($newPath) ? (string) file_get_contents($newPath) : '';
        if ($old === '' && $new === '') {
            return [0, 0];
        }
        if (strlen($old) > 2_000_000 || strlen($new) > 2_000_000) {
            return [
                $this->countLines($newPath),
                $oldPath !== null ? $this->countLines($oldPath) : 0,
            ];
        }

        return self::multisetLineDiff($old, $new);
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function multisetLineDiff(string $old, string $new): array
    {
        $oldCounts = self::lineCounts($old);
        $newCounts = self::lineCounts($new);
        $additions = 0;
        $deletions = 0;
        foreach (array_unique(array_merge(array_keys($oldCounts), array_keys($newCounts))) as $line) {
            $o = $oldCounts[$line] ?? 0;
            $n = $newCounts[$line] ?? 0;
            if ($n > $o) {
                $additions += $n - $o;
            }
            if ($o > $n) {
                $deletions += $o - $n;
            }
        }

        return [$additions, $deletions];
    }

    /**
     * @return array<string, int>
     */
    private static function lineCounts(string $content): array
    {
        if ($content === '') {
            return [];
        }
        $parts = preg_split("/\r\n|\n|\r/", $content);
        if ($parts === false) {
            return [];
        }
        $counts = [];
        foreach ($parts as $line) {
            $counts[$line] = ($counts[$line] ?? 0) + 1;
        }

        return $counts;
    }

    private function countLines(string $path): int
    {
        if (!is_file($path)) {
            return 0;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return 0;
        }
        if (!str_contains($raw, "\n") && !str_contains($raw, "\r")) {
            return 1;
        }

        return substr_count(str_replace(["\r\n", "\r"], "\n", $raw), "\n")
            + (str_ends_with(str_replace(["\r\n", "\r"], "\n", $raw), "\n") ? 0 : 1);
    }

    /**
     * @param list<array{path: string, status: 'added'|'modified'|'deleted', additions: int, deletions: int}> $files
     */
    private function buildSet(
        string $moduleId,
        string $fileVersion,
        string $installedVersion,
        bool $sameVersion,
        bool $footprintDrift,
        array $files,
        int $additions,
        int $deletions,
        string $source,
    ): ModuleChangeSet {
        return new ModuleChangeSet(
            $moduleId,
            $fileVersion,
            $installedVersion,
            $sameVersion,
            $footprintDrift,
            $files,
            $additions,
            $deletions,
            $source,
        );
    }
}
