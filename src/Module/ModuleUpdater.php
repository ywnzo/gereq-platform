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
        private readonly ModuleInstalledSnapshot $snapshots,
        private readonly ModuleFootprintCache $footprintCache,
        private readonly ?ModuleAssetBuilder $assets = null,
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

        $liveFootprint = $this->liveFootprint($id, $manifest->path);
        $installedFootprint = $row->footprint();
        if ($liveFootprint === '' || $installedFootprint === null || $installedFootprint === $liveFootprint) {
            if ($liveFootprint !== '' && ($installedFootprint === null || $installedFootprint === $liveFootprint)) {
                $this->ensureInstalledSnapshot($id, $manifest, $liveFootprint);
            }

            return $this->updateAvailableCache[$id] = false;
        }

        // Fingerprints stored before VCS gitfiles were excluded still describe the same tree.
        $legacyFootprint = ModuleFootprint::ofDirectory($manifest->path, legacy: true);
        if ($installedFootprint === $legacyFootprint) {
            $row->setFootprint($liveFootprint);
            $this->em->flush();
            $this->ensureInstalledSnapshot($id, $manifest, $liveFootprint);

            return $this->updateAvailableCache[$id] = false;
        }

        return $this->updateAvailableCache[$id] = true;
    }

    /**
     * Full content footprint, skipped when the cheap mtime/size signature matches a cache entry.
     */
    private function liveFootprint(string $id, string $path): string
    {
        $quick = ModuleFootprint::quickSignature($path);
        if ($quick !== '') {
            $cached = $this->footprintCache->read($id);
            if ($cached !== null && $cached['quick'] === $quick) {
                return $cached['footprint'];
            }
        }

        $live = ModuleFootprint::ofDirectory($path);
        if ($live !== '' && $quick !== '') {
            $this->footprintCache->write($id, $quick, $live);
        }

        return $live;
    }

    /** Persist file inventory for the installed footprint so the gate can list real diffs later. */
    private function ensureInstalledSnapshot(string $id, ModuleManifest $manifest, string $footprint): void
    {
        $existing = $this->snapshots->read($id);
        if (
            $existing !== null
            && $existing['footprint'] === $footprint
            && $existing['files'] !== []
        ) {
            return;
        }
        $this->snapshots->write(
            $id,
            $manifest->version,
            $footprint,
            ModuleFootprint::inventory($manifest->path),
        );
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
        if ($liveFootprint !== '') {
            $this->footprintCache->write($id, ModuleFootprint::quickSignature($manifest->path), $liveFootprint);
        } else {
            $this->footprintCache->forget($id);
        }
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

        if ($liveFootprint !== '') {
            $this->snapshots->write(
                $id,
                $manifest->version,
                $liveFootprint,
                ModuleFootprint::inventory($manifest->path),
            );
        }

        if ($this->modules->isEnabled($id)) {
            $this->assets?->ensure($id, force: true);
        }

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
