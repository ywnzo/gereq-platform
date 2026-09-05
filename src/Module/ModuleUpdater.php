<?php

declare(strict_types=1);

namespace Gereq\Module;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Audit\Auditor;
use Gereq\Database\Migrator;
use Gereq\User\Entity\Permission;
use Gereq\User\PermissionRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ModuleUpdater
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly Migrator $migrator,
        private readonly EntityManagerInterface $em,
        private readonly PermissionRegistry $permissionRegistry,
        private readonly Auditor $auditor,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function isUpdateAvailable(string $id): bool
    {
        $manifest = $this->modules->manifest($id);
        if ($manifest === null) {
            return false;
        }

        $row = $this->em->find(InstalledModule::class, $id);
        if ($row === null) {
            return false;
        }

        if ($row->version() !== $manifest->version) {
            return true;
        }

        foreach ($this->migrator->statusForModule($id) as $item) {
            if (!$item['ran']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply pending migrations for the module and sync DB version to the file manifest.
     *
     * @return list<string>
     */
    public function update(string $id): array
    {
        $manifest = $this->modules->manifest($id);
        if ($manifest === null) {
            throw new \RuntimeException($this->translator->trans('modules.error.not_found', ['%id%' => $id]));
        }

        $row = $this->em->find(InstalledModule::class, $id);
        if ($row === null) {
            throw new \RuntimeException($this->translator->trans('modules.error.not_installed', ['%id%' => $id]));
        }

        $messages = $this->migrator->migrateModule($id);

        $from = $row->version();
        $row->setVersion($manifest->version);
        $this->syncPermissions($manifest);
        $this->em->flush();

        $this->auditor->log('module.updated', 'module', $id, [
            'version' => $from,
        ], [
            'version' => $manifest->version,
            'migrations' => count($messages),
        ]);

        return $messages;
    }

    private function syncPermissions(ModuleManifest $manifest): void
    {
        foreach ($manifest->permissions as $item) {
            $name = (string) ($item['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $this->permissionRegistry->register(
                $name,
                (string) ($item['label'] ?? $name),
                (string) ($item['group'] ?? $manifest->name),
            );
        }

        foreach ($this->permissionRegistry->all() as $item) {
            $existing = $this->em->getRepository(Permission::class)->findOneBy(['name' => $item['name']]);
            if ($existing === null) {
                $this->em->persist(new Permission($item['name'], $item['label'], $item['group']));
            }
        }
    }
}
