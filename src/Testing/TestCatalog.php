<?php

declare(strict_types=1);

namespace Gereq\Testing;

/**
 * Discovers core, per-module and per-integration test locations on disk.
 *
 * Module / integration tests live only inside the package:
 *   modules/<Dir>/Testing/
 *   integrations/<Dir>/Testing/
 *
 * Cross-module manifest checks stay under tests/Unit/Modules/ModuleStructure.
 */
final class TestCatalog
{
    private readonly string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
    }

    /**
     * @return list<TestTarget>
     */
    public function all(): array
    {
        return [
            $this->allTarget(),
            $this->core(),
            $this->file(),
            $this->modules(),
            ...$this->moduleTargets(),
            $this->integrations(),
            ...$this->integrationTargets(),
        ];
    }

    /**
     * Targets shown by `test --list` (skips the synthetic "all" row).
     *
     * @return list<TestTarget>
     */
    public function listable(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (TestTarget $t): bool => $t->kind !== TestTargetKind::All,
        ));
    }

    public function resolve(string $query): TestTarget
    {
        $query = trim($query);
        if ($query === '' || strcasecmp($query, 'all') === 0) {
            return $this->allTarget();
        }

        $lower = strtolower($query);

        if ($lower === 'core') {
            return $this->core();
        }
        if ($lower === 'file') {
            return $this->file();
        }
        if ($lower === 'modules') {
            return $this->modules();
        }
        if ($lower === 'integrations') {
            return $this->integrations();
        }

        if (preg_match('/^modules?:(.+)$/i', $query, $m) === 1) {
            return $this->module(trim($m[1]));
        }
        if (preg_match('/^integrations?:(.+)$/i', $query, $m) === 1) {
            return $this->integration(trim($m[1]));
        }

        try {
            return $this->module($query);
        } catch (\InvalidArgumentException) {
            // fall through
        }

        try {
            return $this->integration($query);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown test target "%s". Use: all, core, file, modules, integrations, module:<id>, or a package id.',
                $query,
            ));
        }
    }

    public function allTarget(): TestTarget
    {
        $paths = array_values(array_unique([
            ...$this->corePaths(),
            ...$this->file()->paths,
            ...$this->modules()->paths,
            ...$this->integrations()->paths,
        ]));

        return new TestTarget('all', TestTargetKind::All, 'All suites', $paths);
    }

    public function core(): TestTarget
    {
        return new TestTarget(
            'core',
            TestTargetKind::Core,
            'Core (platform unit tests)',
            $this->corePaths(),
        );
    }

    public function file(): TestTarget
    {
        return new TestTarget(
            'file',
            TestTargetKind::File,
            'File (GD / ImageConverter)',
            $this->existingDirs([$this->basePath . '/tests/File']),
        );
    }

    public function modules(): TestTarget
    {
        $paths = [];
        foreach ($this->packageDirs('modules') as $dir) {
            $testing = $dir . '/Testing';
            if (is_dir($testing)) {
                $paths[] = $testing;
            }
        }
        $structure = $this->basePath . '/tests/Unit/Modules/ModuleStructure';
        if (is_dir($structure)) {
            $paths[] = $structure;
        }

        return new TestTarget(
            'modules',
            TestTargetKind::Modules,
            'All modules',
            $paths,
        );
    }

    public function module(string $idOrName): TestTarget
    {
        $pkg = $this->findPackage('modules', $idOrName);
        if ($pkg === null) {
            throw new \InvalidArgumentException(sprintf('Module "%s" not found under modules/.', $idOrName));
        }

        [$id, $dirName, $path] = $pkg;
        $paths = [];
        $testing = $path . '/Testing';
        if (is_dir($testing)) {
            $paths[] = $testing;
        }

        return new TestTarget(
            'module:' . $id,
            TestTargetKind::Module,
            'Module ' . $dirName,
            $paths,
            $id,
        );
    }

    public function integrations(): TestTarget
    {
        $paths = [];
        foreach ($this->packageDirs('integrations') as $dir) {
            $testing = $dir . '/Testing';
            if (is_dir($testing)) {
                $paths[] = $testing;
            }
        }

        return new TestTarget(
            'integrations',
            TestTargetKind::Integrations,
            'All integrations',
            $paths,
        );
    }

    public function integration(string $idOrName): TestTarget
    {
        $pkg = $this->findPackage('integrations', $idOrName);
        if ($pkg === null) {
            throw new \InvalidArgumentException(sprintf(
                'Integration "%s" not found under integrations/.',
                $idOrName,
            ));
        }

        [$id, $dirName, $path] = $pkg;
        $paths = [];
        $testing = $path . '/Testing';
        if (is_dir($testing)) {
            $paths[] = $testing;
        }

        return new TestTarget(
            'integration:' . $id,
            TestTargetKind::Integration,
            'Integration ' . $dirName,
            $paths,
            $id,
        );
    }

    /**
     * @return list<TestTarget>
     */
    public function moduleTargets(): array
    {
        $targets = [];
        foreach ($this->packageDirs('modules') as $dir) {
            $targets[] = $this->module(basename($dir));
        }

        usort($targets, static fn (TestTarget $a, TestTarget $b): int => strcmp($a->id, $b->id));

        return $targets;
    }

    /**
     * @return list<TestTarget>
     */
    public function integrationTargets(): array
    {
        $targets = [];
        foreach ($this->packageDirs('integrations') as $dir) {
            $targets[] = $this->integration(basename($dir));
        }

        usort($targets, static fn (TestTarget $a, TestTarget $b): int => strcmp($a->id, $b->id));

        return $targets;
    }

    /**
     * Absolute paths passed to PHPUnit for the given target.
     *
     * @return list<string>
     */
    public function phpUnitPaths(TestTarget $target): array
    {
        return array_values(array_filter(
            $target->paths,
            static fn (string $p): bool => is_dir($p) || is_file($p),
        ));
    }

    /**
     * Core unit paths: everything under tests/Unit except Modules/.
     *
     * @return list<string>
     */
    private function corePaths(): array
    {
        $unit = $this->basePath . '/tests/Unit';
        if (!is_dir($unit)) {
            return [];
        }

        $paths = [];
        foreach (scandir($unit) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'Modules') {
                continue;
            }
            $full = $unit . '/' . $entry;
            if (is_dir($full) || (is_file($full) && str_ends_with($entry, 'Test.php'))) {
                $paths[] = $full;
            }
        }

        return $paths;
    }

    /**
     * @param list<string> $dirs
     * @return list<string>
     */
    private function existingDirs(array $dirs): array
    {
        return array_values(array_filter($dirs, static fn (string $d): bool => is_dir($d)));
    }

    /**
     * @return list<string> Absolute package directories.
     */
    private function packageDirs(string $rootName): array
    {
        $root = $this->basePath . '/' . $rootName;
        if (!is_dir($root)) {
            return [];
        }

        $dirs = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $root . '/' . $entry;
            if (is_dir($path) && is_file($path . '/module.php')) {
                $dirs[] = $path;
            }
        }

        sort($dirs);

        return $dirs;
    }

    /**
     * @return array{0: string, 1: string, 2: string}|null [id, dirName, absolutePath]
     */
    private function findPackage(string $rootName, string $idOrName): ?array
    {
        $needle = strtolower(trim($idOrName));
        foreach ($this->packageDirs($rootName) as $path) {
            $dirName = basename($path);
            /** @var mixed $manifest */
            $manifest = require $path . '/module.php';
            $id = is_array($manifest) && isset($manifest['id']) && is_string($manifest['id'])
                ? strtolower($manifest['id'])
                : strtolower($dirName);

            if ($id === $needle || strtolower($dirName) === $needle) {
                return [$id, $dirName, $path];
            }
        }

        return null;
    }
}
