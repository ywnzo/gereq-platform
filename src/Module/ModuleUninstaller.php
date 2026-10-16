<?php

declare(strict_types=1);

namespace Gereq\Module;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Database\Migrator;
use Gereq\File\FileManager;
use Gereq\Media\Domain\MediaAsset;
use Gereq\Media\Domain\MediaLink;

/**
 * Deactivation = soft-disable + drop module DB schema (+ optional media).
 */
final class ModuleUninstaller
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly Migrator $migrator,
        private readonly EntityManagerInterface $em,
        private readonly FileManager $files,
    ) {
    }

    /**
     * @param list<string> $alsoDisabling IDs disabled in the same batch (skips dependent check)
     * @return list<string> log messages
     */
    public function uninstall(string $id, bool $purgeMedia = false, array $alsoDisabling = []): array
    {
        // Check dependents before deleting media or schema.
        $this->modules->disable($id, $alsoDisabling);
        $messages = [];

        if ($purgeMedia) {
            $deleted = $this->purgeMedia($id);
            if ($deleted > 0) {
                $messages[] = 'media:' . $deleted;
            }
        }

        // Disabled modules stay out of schema synchronization during rollback.
        $messages = array_merge($messages, $this->migrator->rollbackModule($id));

        return $messages;
    }

    /**
     * @param list<string> $ids
     * @return array{ok: int, errors: list<string>}
     */
    public function uninstallMany(array $ids, bool $purgeMedia = false): array
    {
        $ids = $this->modules->expandWithDependents($ids, true);
        $ordered = $this->modules->sortForDisable($ids);
        $ok = 0;
        $errors = [];
        $failed = [];

        foreach ($ordered as $id) {
            try {
                foreach ($failed as $failedId) {
                    if (isset($this->modules->manifest($failedId)?->requires[$id])) {
                        throw new \RuntimeException('Dependency retained after failed uninstall: ' . $failedId);
                    }
                }
                if ($this->modules->installed($id) === null) {
                    continue;
                }
                $this->uninstall($id, $purgeMedia);
                ++$ok;
            } catch (\Throwable $e) {
                $failed[] = $id;
                $errors[] = $id . ': ' . $e->getMessage();
            }
        }

        return ['ok' => $ok, 'errors' => $errors];
    }

    private function purgeMedia(string $moduleId): int
    {
        if (!class_exists(MediaAsset::class)) {
            return 0;
        }

        $ids = $this->collectMediaAssetIds($moduleId);
        if ($ids === []) {
            return 0;
        }

        $deleted = 0;

        if (class_exists(MediaLink::class)) {
            foreach ($ids as $assetId) {
                foreach ($this->em->getRepository(MediaLink::class)->findBy(['mediaAssetId' => $assetId]) as $link) {
                    if ($link instanceof MediaLink) {
                        $this->em->remove($link);
                    }
                }
            }
        }

        foreach ($ids as $assetId) {
            $asset = $this->em->find(MediaAsset::class, $assetId);
            if (!$asset instanceof MediaAsset) {
                continue;
            }

            $path = $asset->path();
            $this->em->remove($asset);
            ++$deleted;

            try {
                if ($this->files->filesystem()->fileExists($path)) {
                    $this->files->filesystem()->delete($path);
                }
            } catch (\Throwable) {
                // Best-effort file cleanup.
            }
        }

        if ($deleted > 0) {
            $this->em->flush();
        }

        return $deleted;
    }

    /**
     * @return list<string>
     */
    private function collectMediaAssetIds(string $moduleId): array
    {
        $ids = [];
        $conn = $this->em->getConnection();
        $sm = $conn->createSchemaManager();

        foreach ($this->migrator->metadataForModule($moduleId) as $meta) {
            $table = $meta->getTableName();
            if (!$sm->tablesExist([$table])) {
                continue;
            }

            foreach ($meta->getFieldNames() as $field) {
                $col = $meta->getColumnName($field);
                if (!str_contains(strtolower($col), 'media_asset')) {
                    continue;
                }
                try {
                    $col = $conn->quoteIdentifier($col);
                    $tableSql = $conn->quoteIdentifier($table);
                    $rows = $conn->fetchFirstColumn(
                        sprintf('SELECT DISTINCT %s FROM %s WHERE %s IS NOT NULL', $col, $tableSql, $col)
                    );
                    foreach ($rows as $value) {
                        if (is_string($value) && $value !== '') {
                            $ids[$value] = true;
                        }
                    }
                } catch (\Throwable) {
                    // Table/column may be mid-migration.
                }
            }
        }

        if (class_exists(MediaLink::class) && $sm->tablesExist(['media_links'])) {
            $types = $this->entityTypeCandidates($moduleId);
            if ($types !== []) {
                try {
                    $placeholders = implode(',', array_fill(0, count($types), '?'));
                    $rows = $conn->fetchFirstColumn(
                        "SELECT DISTINCT media_asset_id FROM media_links WHERE entity_type IN ({$placeholders})",
                        $types
                    );
                    foreach ($rows as $value) {
                        if (is_string($value) && $value !== '') {
                            $ids[$value] = true;
                        }
                    }
                } catch (\Throwable) {
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * @return list<string>
     */
    private function entityTypeCandidates(string $moduleId): array
    {
        $types = [$moduleId];
        foreach ($this->migrator->metadataForModule($moduleId) as $meta) {
            $short = (new \ReflectionClass($meta->getName()))->getShortName();
            $types[] = strtolower($short);
            $snake = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $short));
            if ($snake !== '') {
                $types[] = $snake;
            }
            $table = $meta->getTableName();
            if ($table !== '') {
                $types[] = $table;
                if (str_contains($table, '_')) {
                    $types[] = substr($table, (int) strrpos($table, '_') + 1);
                }
            }
        }

        return array_values(array_unique(array_filter($types, static fn (string $t): bool => $t !== '')));
    }
}
