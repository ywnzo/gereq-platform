<?php

declare(strict_types=1);

namespace Gereq\Module;

use Gereq\Config\Repository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * On-disk cache of downloaded module versions: storage/modules/{id}/{version}/
 */
final class ModuleVersionStore
{
    public function __construct(
        private readonly string $basePath,
        private readonly Repository $config,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function root(): string
    {
        $rel = trim((string) $this->config->get('modules.versions_path', 'storage/modules'), '/');

        return $this->basePath . '/' . $rel;
    }

    public function pathFor(string $moduleId, string $version): string
    {
        $version = GitLabModuleClient::normalizeVersion($version);

        return $this->root() . '/' . $this->safeSegment($moduleId) . '/' . $this->safeSegment($version);
    }

    public function has(string $moduleId, string $version): bool
    {
        return is_file($this->pathFor($moduleId, $version) . '/module.php');
    }

    /**
     * @return list<string> semver versions present in cache (desc)
     */
    public function cachedVersions(string $moduleId): array
    {
        $dir = $this->root() . '/' . $this->safeSegment($moduleId);
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!is_file($dir . '/' . $entry . '/module.php')) {
                continue;
            }
            if (!GitLabModuleClient::isSemver($entry)) {
                continue;
            }
            $out[] = $entry;
        }
        usort($out, static fn (string $a, string $b): int => version_compare($b, $a));

        return $out;
    }

    /**
     * Extract ZIP archive into cache path for module/version. Returns absolute path.
     */
    public function installFromZip(string $moduleId, string $version, string $zipPath): string
    {
        $version = GitLabModuleClient::normalizeVersion($version);
        if (!GitLabModuleClient::isSemver($version)) {
            throw new \InvalidArgumentException($this->translator->trans('admin.modules.versions.error.bad_version'));
        }
        if (!is_file($zipPath)) {
            throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.missing_zip'));
        }

        $target = $this->pathFor($moduleId, $version);
        $staging = $target . '.__staging_' . bin2hex(random_bytes(4));
        if (!mkdir($staging, 0775, true) && !is_dir($staging)) {
            throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.mkdir'));
        }

        try {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.zip'));
            }

            $extractRoot = $staging . '/__extract';
            mkdir($extractRoot, 0775, true);
            if (!$zip->extractTo($extractRoot)) {
                $zip->close();
                throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.zip'));
            }
            $zip->close();

            $packageRoot = $this->findPackageRoot($extractRoot);
            $this->assertSafeTree($packageRoot);
            $manifestFile = $packageRoot . '/module.php';
            if (!is_file($manifestFile)) {
                throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.no_manifest'));
            }

            /** @var mixed $data */
            $data = require $manifestFile;
            if (!is_array($data)) {
                throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.no_manifest'));
            }
            $id = (string) ($data['id'] ?? '');
            $fileVersion = GitLabModuleClient::normalizeVersion((string) ($data['version'] ?? ''));
            if ($id !== $moduleId) {
                throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.id_mismatch', [
                    '%expected%' => $moduleId,
                    '%got%' => $id,
                ]));
            }
            if ($fileVersion !== $version) {
                throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.version_mismatch', [
                    '%expected%' => $version,
                    '%got%' => $fileVersion,
                ]));
            }

            // Move package contents into staging root (strip GitLab archive prefix folder).
            foreach (scandir($packageRoot) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $from = $packageRoot . '/' . $entry;
                $to = $staging . '/' . $entry;
                if (!rename($from, $to)) {
                    throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.write'));
                }
            }
            $this->deleteTree($extractRoot);

            if (is_dir($target)) {
                $this->deleteTree($target);
            }
            if (!rename($staging, $target)) {
                throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.write'));
            }
            $staging = '';

            file_put_contents($target . '/.gereq-version.json', json_encode([
                'id' => $moduleId,
                'version' => $version,
                'installed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $target;
        } finally {
            if ($staging !== '' && is_dir($staging)) {
                $this->deleteTree($staging);
            }
        }
    }

    /** Copy live module tree into cache (backup before swap). */
    public function snapshotLive(ModuleManifest $manifest): string
    {
        $version = GitLabModuleClient::normalizeVersion($manifest->version);
        if (!GitLabModuleClient::isSemver($version)) {
            $version = '0.0.0-live';
        }
        $target = $this->pathFor($manifest->id, $version);
        if ($this->has($manifest->id, $version)) {
            return $target;
        }

        $staging = $target . '.__staging_' . bin2hex(random_bytes(4));
        $this->copyTree($manifest->path, $staging);
        if (is_dir($target)) {
            $this->deleteTree($target);
        }
        if (!rename($staging, $target)) {
            $this->deleteTree($staging);
            throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.write'));
        }

        return $target;
    }

    private function findPackageRoot(string $extractRoot): string
    {
        if (is_file($extractRoot . '/module.php')) {
            return $extractRoot;
        }
        foreach (scandir($extractRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $candidate = $extractRoot . '/' . $entry;
            if (is_dir($candidate) && is_file($candidate . '/module.php')) {
                return $candidate;
            }
        }

        throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.no_manifest'));
    }

    private function assertSafeTree(string $root): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $name = $file->getFilename();
            if ($name === '..' || str_contains($file->getPathname(), "\0")) {
                throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.unsafe_zip'));
            }
            $real = realpath($file->getPathname());
            $rootReal = realpath($root);
            if ($real === false || $rootReal === false || !str_starts_with($real, $rootReal)) {
                throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.unsafe_zip'));
            }
        }
    }

    private function safeSegment(string $value): string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, '..') || str_contains($value, '/') || str_contains($value, '\\')) {
            throw new \InvalidArgumentException($this->translator->trans('admin.modules.versions.error.bad_path'));
        }

        return $value;
    }

    private function copyTree(string $from, string $to): void
    {
        if (!mkdir($to, 0775, true) && !is_dir($to)) {
            throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.mkdir'));
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $target = $to . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.mkdir'));
                }
            } else {
                if (!copy($item->getPathname(), $target)) {
                    throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.write'));
                }
            }
        }
    }

    public function deleteTree(string $path): void
    {
        if (!is_dir($path) && !is_file($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
