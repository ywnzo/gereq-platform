<?php

declare(strict_types=1);

namespace Gereq\Module;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Audit\Auditor;
use Gereq\Database\Migrator;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ModuleUpdater
{
    /** @var array<string, bool> */
    private array $updateAvailableCache = [];

    public function __construct(
        private readonly ModuleManager $modules,
        private readonly Migrator $migrator,
        private readonly EntityManagerInterface $em,
        private readonly Auditor $auditor,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function isUpdateAvailable(string $id): bool
    {
        if (array_key_exists($id, $this->updateAvailableCache)) {
            return $this->updateAvailableCache[$id];
        }

        $manifest = $this->modules->manifest($id);
        if ($manifest === null) {
            return $this->updateAvailableCache[$id] = false;
        }

        $row = $this->modules->installed($id);
        if ($row === null) {
            return $this->updateAvailableCache[$id] = false;
        }

        if ($row->version() !== $manifest->version) {
            return $this->updateAvailableCache[$id] = true;
        }

        foreach ($this->migrator->statusForModule($id) as $item) {
            if (!$item['ran']) {
                return $this->updateAvailableCache[$id] = true;
            }
        }

        return $this->updateAvailableCache[$id] = false;
    }

    /**
     * Apply pending migrations for the module and sync DB version to the file manifest.
     *
     * @return list<string>
     */
    public function update(string $id, bool $syncSchema = true): array
    {
        $manifest = $this->modules->manifest($id);
        if ($manifest === null) {
            throw new \RuntimeException($this->translator->trans('modules.error.not_found', ['%id%' => $id]));
        }

        $row = $this->modules->installed($id);
        if ($row === null) {
            throw new \RuntimeException($this->translator->trans('modules.error.not_installed', ['%id%' => $id]));
        }

        $messages = $this->migrator->migrateModule($id, syncSchema: $syncSchema);

        $from = $row->version();
        $row->setVersion($manifest->version);
        $this->modules->syncPermissions($manifest);
        $this->em->flush();

        $this->auditor->log('module.updated', 'module', $id, [
            'version' => $from,
        ], [
            'version' => $manifest->version,
            'migrations' => count($messages),
        ]);

        unset($this->updateAvailableCache[$id]);

        return $messages;
    }
}
