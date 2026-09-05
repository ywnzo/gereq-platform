<?php

declare(strict_types=1);

namespace Gereq\Module;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Audit\Auditor;
use Gereq\User\Entity\Permission;
use Gereq\User\PermissionRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ModuleManager
{
    /** @var array<string, ModuleManifest>|null */
    private ?array $discovered = null;

    /** @var list<ModuleInterface> */
    private array $booted = [];

    /** @var list<string>|null */
    private ?array $enabledIds = null;

    /** @var array<string, InstalledModule>|null */
    private ?array $installedById = null;

    public function __construct(
        private readonly string $basePath,
        private readonly EntityManagerInterface $em,
        private readonly PermissionRegistry $permissionRegistry,
        private readonly Auditor $auditor,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, ModuleManifest>
     */
    public function discover(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $this->discovered = [];
        $root = $this->basePath . '/modules';
        if (!is_dir($root)) {
            return $this->discovered;
        }

        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $manifestFile = $root . '/' . $entry . '/module.php';
            if (!is_file($manifestFile)) {
                continue;
            }

            $data = require $manifestFile;
            if (!is_array($data)) {
                continue;
            }

            $manifest = ModuleManifest::fromArray($data, $root . '/' . $entry);
            $this->discovered[$manifest->id] = $manifest;
        }

        return $this->discovered;
    }

    public function manifest(string $id): ?ModuleManifest
    {
        return $this->discover()[$id] ?? null;
    }

    public function isEnabled(string $id): bool
    {
        return in_array($id, $this->enabledIds(), true);
    }

    public function installed(string $id): ?InstalledModule
    {
        return $this->installedById()[$id] ?? null;
    }

    /**
     * @return array<string, InstalledModule>
     */
    public function installedById(): array
    {
        if ($this->installedById !== null) {
            return $this->installedById;
        }

        $this->installedById = [];
        try {
            foreach ($this->em->getRepository(InstalledModule::class)->findAll() as $row) {
                if ($row instanceof InstalledModule) {
                    $this->installedById[$row->id()] = $row;
                }
            }
        } catch (\Throwable) {
            $this->installedById = [];
        }

        return $this->installedById;
    }

    /**
     * @return list<string>
     */
    public function enabledIds(): array
    {
        if ($this->enabledIds !== null) {
            return $this->enabledIds;
        }

        $this->enabledIds = [];
        foreach ($this->installedById() as $row) {
            if ($row->enabled()) {
                $this->enabledIds[] = $row->id();
            }
        }

        return $this->enabledIds;
    }

    public function enable(string $id): void
    {
        $manifest = $this->requireManifest($id);
        $this->assertDependencies($manifest);

        $row = $this->installed($id);
        if ($row === null) {
            $row = new InstalledModule($id, $manifest->version, true);
            $this->em->persist($row);
        } else {
            $row->setVersion($manifest->version);
            $row->enable();
        }

        $this->syncManifestPermissions($manifest);
        $this->syncPermissions($manifest);
        $this->em->flush();
        $this->enabledIds = null;
        $this->installedById = null;
        $this->auditor->log('module.enabled', 'module', $id);
    }

    public function disable(string $id): void
    {
        $this->requireManifest($id);

        foreach ($this->discover() as $other) {
            if (!$this->isEnabled($other->id) || $other->id === $id) {
                continue;
            }

            if (isset($other->requires[$id])) {
                throw new \RuntimeException($this->translator->trans('modules.error.disable_dependent', [
                    '%id%' => $id,
                    '%dependent%' => $other->name,
                ]));
            }
        }

        $row = $this->installed($id);
        if ($row !== null) {
            $row->disable();
            $this->em->flush();
        }

        $this->enabledIds = null;
        $this->installedById = null;
        $this->auditor->log('module.disabled', 'module', $id);
    }

    public function boot(ModuleContext $context): void
    {
        $enabled = [];
        foreach ($this->enabledIds() as $id) {
            $manifest = $this->manifest($id);
            if ($manifest === null) {
                continue;
            }

            if (!class_exists($manifest->moduleClass)) {
                continue;
            }

            $module = new $manifest->moduleClass();
            if (!$module instanceof ModuleInterface) {
                continue;
            }

            $enabled[] = $module;
        }

        foreach ($enabled as $module) {
            $module->register($context);
        }

        foreach ($enabled as $module) {
            $module->boot($context);
        }

        $this->booted = $enabled;
    }

    /**
     * @return list<string>
     */
    public function missingDependencies(ModuleManifest $manifest): array
    {
        $missing = [];
        foreach ($manifest->requires as $dependency => $constraint) {
            if ($dependency === 'core') {
                continue;
            }

            if (!$this->isEnabled($dependency)) {
                $missing[] = $dependency;
            }
        }

        return $missing;
    }

    /**
     * @return list<ModuleInterface>
     */
    public function booted(): array
    {
        return $this->booted;
    }

    private function requireManifest(string $id): ModuleManifest
    {
        $manifest = $this->manifest($id);
        if ($manifest === null) {
            throw new \RuntimeException($this->translator->trans('modules.error.not_found', ['%id%' => $id]));
        }

        return $manifest;
    }

    private function assertDependencies(ModuleManifest $manifest): void
    {
        $missing = $this->missingDependencies($manifest);
        if ($missing !== []) {
            throw new \RuntimeException($this->translator->trans('modules.error.missing_deps', [
                '%deps%' => implode(', ', $missing),
            ]));
        }
    }

    private function syncManifestPermissions(ModuleManifest $manifest): void
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
    }

    private function syncPermissions(ModuleManifest $manifest): void
    {
        $this->syncManifestPermissions($manifest);

        /** @var array<string, Permission> $existing */
        $existing = [];
        foreach ($this->em->getRepository(Permission::class)->findAll() as $permission) {
            if ($permission instanceof Permission) {
                $existing[$permission->name()] = $permission;
            }
        }

        foreach ($this->permissionRegistry->all() as $item) {
            if (!isset($existing[$item['name']])) {
                $this->em->persist(new Permission($item['name'], $item['label'], $item['group']));
            }
        }
    }
}
