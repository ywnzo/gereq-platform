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

        // Disabled modules keep migration files on disk after purge/rollback; those
        // "pending" rows are expected and will run on enable — not an update.
        if (!$this->modules->isEnabled($id)) {
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

        $liveFootprint = ModuleFootprint::ofDirectory($manifest->path);
        $installedFootprint = $row->footprint();
        if ($liveFootprint !== '' && $installedFootprint !== null && $installedFootprint !== $liveFootprint) {
            return $this->updateAvailableCache[$id] = true;
        }

        return $this->updateAvailableCache[$id] = false;
    }

    /**
     * Apply pending migrations for the module and sync DB version to the file manifest.
     * Disabled modules only bump the stored version — schema is applied on enable.
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

        $messages = [];
        if ($this->modules->isEnabled($id)) {
            $messages = $this->migrator->migrateModule($id, syncSchema: $syncSchema);
        }

        $from = $row->version();
        $fromFootprint = $row->footprint();
        $liveFootprint = ModuleFootprint::ofDirectory($manifest->path);
        $row->setVersion($manifest->version);
        $row->setFootprint($liveFootprint !== '' ? $liveFootprint : null);
        if ($this->modules->isEnabled($id)) {
            $this->modules->syncPermissions($manifest);
        }
        $this->em->flush();

        if ($id === 'listings' && $this->modules->isEnabled($id)) {
            $this->ensureBrokerRole();
        }

        $this->auditor->log('module.updated', 'module', $id, [
            'version' => $from,
            'footprint' => $fromFootprint,
        ], [
            'version' => $manifest->version,
            'footprint' => $row->footprint(),
            'migrations' => count($messages),
        ]);

        unset($this->updateAvailableCache[$id]);

        return $messages;
    }

    /** Ensure the Makléř (broker) role exists with the expected permissions. */
    private function ensureBrokerRole(): void
    {
        $roleRepo = $this->em->getRepository(\Gereq\User\Entity\Role::class);
        $role = $roleRepo->findOneBy(['name' => 'broker']);
        if (!$role instanceof \Gereq\User\Entity\Role) {
            $role = new \Gereq\User\Entity\Role(
                'broker',
                'Makléř',
                'Vlastní kalendář, prohlídky, nabídky, klienti, dokumenty a média — bez CRM/automatizace.',
            );
            $this->em->persist($role);
        } else {
            $role->setLabel('Makléř');
            $role->setDescription('Vlastní kalendář, prohlídky, nabídky, klienti, dokumenty a média — bez CRM/automatizace.');
        }

        $names = [
            'calendar.view',
            'calendar.manage',
            'viewings.view',
            'viewings.manage',
            'listings.view',
            'listings.manage',
            'customers.view',
            'documents.view',
            'documents.manage',
            'media.view',
            'media.manage',
        ];
        $permissions = [];
        foreach ($names as $name) {
            $permission = $this->em->getRepository(\Gereq\User\Entity\Permission::class)->findOneBy(['name' => $name]);
            if ($permission instanceof \Gereq\User\Entity\Permission) {
                $permissions[] = $permission;
            }
        }
        $role->syncPermissions(...$permissions);
        $this->em->flush();
    }
}
