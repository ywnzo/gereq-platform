<?php

declare(strict_types=1);

namespace Gereq\Module;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Audit\Auditor;
use Gereq\Database\Migrator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Swap live modules/{Dir} from a cached version and apply migrations.
 */
final class ModuleVersionActivator
{
    public function __construct(
        private readonly string $basePath,
        private readonly ModuleManager $modules,
        private readonly ModuleVersionStore $store,
        private readonly ModuleVersionInstaller $installer,
        private readonly Migrator $migrator,
        private readonly ModuleUpdater $updater,
        private readonly EntityManagerInterface $em,
        private readonly Auditor $auditor,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Ensure version is cached (fetch if needed), then activate.
     *
     * @return list<string>
     */
    public function activate(string $moduleId, string $version, bool $fetchIfMissing = true): array
    {
        $manifest = $this->modules->manifest($moduleId);
        if ($manifest === null) {
            throw new \RuntimeException($this->translator->trans('modules.error.not_found', ['%id%' => $moduleId]));
        }

        $version = GitLabModuleClient::normalizeVersion($version);
        if (!GitLabModuleClient::isSemver($version)) {
            throw new \InvalidArgumentException($this->translator->trans('admin.modules.versions.error.bad_version'));
        }

        if (!$this->store->has($moduleId, $version)) {
            if (!$fetchIfMissing) {
                throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.not_cached', [
                    '%version%' => $version,
                ]));
            }
            $this->installer->fetch($moduleId, $version);
        }

        $targetPath = $this->store->pathFor($moduleId, $version);
        $livePath = $manifest->path;
        $fromVersion = GitLabModuleClient::normalizeVersion($manifest->version);
        $cmp = version_compare($version, $fromVersion);

        $messages = [];

        // Snapshot current live tree into cache before replacing.
        try {
            $this->store->snapshotLive($manifest);
        } catch (\Throwable) {
            // Non-fatal — continue with activation.
        }

        if ($cmp < 0) {
            $messages = array_merge(
                $messages,
                $this->migrator->rollbackMigrationsMissingFrom($moduleId, $livePath, $targetPath),
            );
        }

        $this->swapLiveTree($livePath, $targetPath);
        $this->modules->rediscover();

        $fresh = $this->modules->manifest($moduleId);
        if ($fresh === null) {
            throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.activate_missing'));
        }

        $row = $this->modules->installed($moduleId);
        if ($row === null) {
            $row = new InstalledModule($moduleId, $fresh->version, false);
            $this->em->persist($row);
        }

        if ($cmp > 0 || $row->version() !== $fresh->version) {
            $messages = array_merge($messages, $this->updater->update($moduleId));
        } else {
            $row->setVersion($fresh->version);
            $row->setSource('gitlab');
            $this->em->flush();
        }

        // Ensure source flag after updater.
        $row = $this->modules->installed($moduleId);
        if ($row !== null) {
            $row->setSource('gitlab');
            $row->setVersion($fresh->version);
            $this->em->flush();
        }

        $this->auditor->log('module.version_activated', 'module', $moduleId, [
            'version' => $fromVersion,
        ], [
            'version' => $version,
            'messages' => count($messages),
        ]);

        return $messages;
    }

    private function swapLiveTree(string $livePath, string $sourcePath): void
    {
        $liveReal = realpath($livePath);
        $sourceReal = realpath($sourcePath);
        if ($sourceReal === false || !is_dir($sourceReal)) {
            throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.not_cached_path'));
        }
        if ($liveReal === false || !is_dir($liveReal)) {
            throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.live_missing'));
        }
        if ($liveReal === $sourceReal) {
            return;
        }

        $parent = dirname($liveReal);
        $name = basename($liveReal);
        $backup = $parent . '/.' . $name . '.bak_' . bin2hex(random_bytes(4));
        $staging = $parent . '/.' . $name . '.new_' . bin2hex(random_bytes(4));

        $this->store->deleteTree($staging);
        $this->copyTree($sourceReal, $staging);

        if (!rename($liveReal, $backup)) {
            $this->store->deleteTree($staging);
            throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.swap'));
        }
        if (!rename($staging, $liveReal)) {
            rename($backup, $liveReal);
            $this->store->deleteTree($staging);
            throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.swap'));
        }

        $this->store->deleteTree($backup);
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
            } elseif ($item->getFilename() === '.gereq-version.json') {
                continue;
            } else {
                if (!copy($item->getPathname(), $target)) {
                    throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.write'));
                }
            }
        }
    }
}
