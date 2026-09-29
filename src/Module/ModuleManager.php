<?php

declare(strict_types=1);

namespace Gereq\Module;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Audit\Auditor;
use Gereq\Hook\HookManager;
use Gereq\User\Entity\Permission;
use Gereq\User\PermissionRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ModuleManager implements EnabledModulesProvider
{
    /** @var array<string, ModuleManifest>|null */
    private ?array $discovered = null;

    /** @var list<ModuleInterface> */
    private array $booted = [];

    /** @var list<string>|null */
    private ?array $enabledIds = null;

    /** @var array<string, InstalledModule>|null */
    private ?array $installedById = null;

    /** @var array<string, Permission>|null request memo for permission sync */
    private ?array $permissionsByName = null;

    public function __construct(
        private readonly string $basePath,
        private readonly EntityManagerInterface $em,
        private readonly PermissionRegistry $permissionRegistry,
        private readonly Auditor $auditor,
        private readonly TranslatorInterface $translator,
        private readonly ?HookManager $hooks = null,
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
        foreach (['modules', 'integrations'] as $dirName) {
            $root = $this->basePath . '/' . $dirName;
            if (!is_dir($root)) {
                continue;
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
                if (isset($this->discovered[$manifest->id])) {
                    throw new \RuntimeException(sprintf(
                        'Duplicitní package id "%s" (%s vs %s).',
                        $manifest->id,
                        $this->discovered[$manifest->id]->path,
                        $manifest->path,
                    ));
                }
                $this->discovered[$manifest->id] = $manifest;
            }
        }

        return $this->discovered;
    }

    /** Forget discovery / installed caches after live tree swap. */
    public function rediscover(): void
    {
        $this->discovered = null;
        $this->enabledIds = null;
        $this->installedById = null;
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
        $footprint = ModuleFootprint::ofDirectory($manifest->path);
        $row->setFootprint($footprint !== '' ? $footprint : null);

        $this->syncPermissions($manifest);
        $this->em->flush();
        $this->rememberInstalled($row);
        $this->auditor->log('module.enabled', 'module', $id);
        $this->hooks?->doAction('module.enabled', $id);
    }

    /** Persist missing Permission rows for a manifest (request-memoized findAll). */
    public function syncPermissions(ModuleManifest $manifest): void
    {
        $this->syncManifestPermissions($manifest);

        $existing = $this->permissionsByName();
        foreach ($this->permissionRegistry->all() as $item) {
            if (!isset($existing[$item['name']])) {
                $permission = new Permission($item['name'], $item['label'], $item['group']);
                $this->em->persist($permission);
                $this->permissionsByName[$item['name']] = $permission;
            }
        }
    }

    public function disable(string $id, array $alsoDisabling = []): void
    {
        $this->requireManifest($id);
        $also = array_fill_keys($alsoDisabling, true);

        foreach ($this->discover() as $other) {
            if (!$this->isEnabled($other->id) || $other->id === $id) {
                continue;
            }
            if (isset($also[$other->id])) {
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
            $this->rememberInstalled($row);
        } else {
            $this->enabledIds = null;
        }

        $this->auditor->log('module.disabled', 'module', $id);
    }

    /**
     * Activation order: dependencies first.
     *
     * @param list<string> $ids
     * @return list<string>
     */
    public function sortForEnable(array $ids): array
    {
        return $this->topologicalSort($ids);
    }

    /**
     * Deactivation order: dependent modules first (reverse topological sort).
     *
     * @param list<string> $ids
     * @return list<string>
     */
    public function sortForDisable(array $ids): array
    {
        return array_reverse($this->topologicalSort($ids));
    }

    /**
     * Soft-disable (pause): disables the module; DB data remains.
     *
     * @param list<string> $ids
     * @return array{ok: int, errors: list<string>}
     */
    public function pauseMany(array $ids): array
    {
        $ids = $this->expandWithDependents($ids);
        $ordered = $this->sortForDisable($ids);
        $ok = 0;
        $errors = [];

        foreach ($ordered as $id) {
            try {
                if (!$this->isEnabled($id)) {
                    continue;
                }
                $this->disable($id, $ids);
                ++$ok;
            } catch (\Throwable $e) {
                $errors[] = $id . ': ' . $e->getMessage();
            }
        }

        return ['ok' => $ok, 'errors' => $errors];
    }

    /**
     * Adds active modules that depend on the selected ones (transitively).
     *
     * @param list<string> $ids
     * @return list<string>
     */
    public function expandWithDependents(array $ids): array
    {
        $set = [];
        foreach ($ids as $id) {
            if ($id !== '') {
                $set[$id] = true;
            }
        }

        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($this->discover() as $other) {
                if (!$this->isEnabled($other->id) || isset($set[$other->id])) {
                    continue;
                }
                foreach (array_keys($other->requires) as $dep) {
                    if ($dep === 'core' || !isset($set[$dep])) {
                        continue;
                    }
                    $set[$other->id] = true;
                    $changed = true;
                    break;
                }
            }
        }

        return array_keys($set);
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function topologicalSort(array $ids): array
    {
        $set = array_fill_keys($ids, true);
        $visited = [];
        $stack = [];

        $visit = function (string $id) use (&$visit, &$visited, &$stack, $set): void {
            if (isset($visited[$id])) {
                return;
            }
            $visited[$id] = true;
            $manifest = $this->manifest($id);
            if ($manifest !== null) {
                foreach (array_keys($manifest->requires) as $dep) {
                    if ($dep === 'core' || !isset($set[$dep])) {
                        continue;
                    }
                    $visit($dep);
                }
            }
            $stack[] = $id;
        };

        foreach ($ids as $id) {
            $visit($id);
        }

        return $stack;
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

    /** Updates request cache after enable/disable — without another SELECT * FROM modules. */
    private function rememberInstalled(InstalledModule $row): void
    {
        if ($this->installedById === null) {
            $this->installedById = [];
        }
        $this->installedById[$row->id()] = $row;

        $this->enabledIds = [];
        foreach ($this->installedById as $installed) {
            if ($installed->enabled()) {
                $this->enabledIds[] = $installed->id();
            }
        }
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

    /**
     * @return array<string, Permission>
     */
    private function permissionsByName(): array
    {
        if ($this->permissionsByName !== null) {
            return $this->permissionsByName;
        }

        $this->permissionsByName = [];
        try {
            foreach ($this->em->getRepository(Permission::class)->findAll() as $permission) {
                if ($permission instanceof Permission) {
                    $this->permissionsByName[$permission->name()] = $permission;
                }
            }
        } catch (\Throwable) {
            $this->permissionsByName = [];
        }

        return $this->permissionsByName;
    }
}
