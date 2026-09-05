<?php

declare(strict_types=1);

namespace Gereq\Module;

use Gereq\Api\ApiRegistry;
use Gereq\Config\Repository;
use Gereq\Database\Migrator;
use Gereq\Event\EventRegistry;
use Gereq\Hook\HookManager;
use Gereq\Http\Route;
use Gereq\Http\Router;
use Gereq\Setting\Settings;
use Doctrine\ORM\EntityManagerInterface;

final class ModuleInspector
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly ModuleUpdater $updater,
        private readonly Migrator $migrator,
        private readonly ApiRegistry $api,
        private readonly Router $router,
        private readonly EventRegistry $events,
        private readonly HookManager $hooks,
        private readonly CapabilityRegistry $capabilities,
        private readonly Settings $settings,
        private readonly EntityManagerInterface $em,
        private readonly Repository $config,
    ) {
    }

    /**
     * @return array{
     *     overview: array<string, mixed>,
     *     migrations: list<array{version: string, ran: bool, ran_at: ?string, file: string}>,
     *     api: list<array{methods: list<string>, path: string, scopes: list<string>, summary: string, permission: ?string, module: string}>,
     *     routes: list<array{methods: list<string>, path: string, name: ?string, permission: ?string, handler: string}>,
     *     hooks: list<array{name: string, type: string, listeners: int}>,
     *     events: list<array{type: string, description: string, version: int, webhook: bool}>,
     *     settings: list<array{key: string, type: string, label: string, default: mixed}>,
     *     files: list<array{path: string, type: string, size: ?int}>,
     *     health: list<array{id: string, label: string, status: 'ok'|'warn'|'fail'|'info', detail: string}>,
     *     counts: array<string, int>
     * }
     */
    public function inspect(ModuleManifest $manifest): array
    {
        $enabled = $this->modules->isEnabled($manifest->id);
        $missing = $this->modules->missingDependencies($manifest);
        $migrations = $this->migrator->statusForModule($manifest->id);
        $api = $this->apiRoutes($manifest);
        $routes = $this->adminRoutes($manifest);
        $hooks = $this->moduleHooks($manifest);
        $events = $this->moduleEvents($manifest);
        $settings = $this->moduleSettings($manifest);
        $files = $this->fileTree($manifest);
        $installed = $this->em->find(InstalledModule::class, $manifest->id);
        $updateAvailable = $this->updater->isUpdateAvailable($manifest->id);
        $health = $this->health($manifest, $enabled, $missing, $migrations, $installed?->version(), $updateAvailable);

        $pendingMigrations = count(array_filter($migrations, static fn (array $row): bool => !$row['ran']));

        return [
            'overview' => [
                'enabled' => $enabled,
                'missing' => $missing,
                'installed_version' => $installed?->version(),
                'enabled_at' => $installed?->enabledAt()?->format('c'),
                'class_exists' => class_exists($manifest->moduleClass),
                'capability_providers' => $this->capabilityMap($manifest),
                'update_available' => $updateAvailable,
            ],
            'migrations' => $migrations,
            'api' => $api,
            'routes' => $routes,
            'hooks' => $hooks,
            'events' => $events,
            'settings' => $settings,
            'files' => $files,
            'health' => $health,
            'counts' => [
                'migrations' => count($migrations),
                'migrations_pending' => $pendingMigrations,
                'api' => count($api),
                'routes' => count($routes),
                'hooks' => count($hooks),
                'events' => count($events),
                'settings' => count($settings),
                'files' => count($files),
                'health_issues' => count(array_filter(
                    $health,
                    static fn (array $row): bool => in_array($row['status'], ['warn', 'fail'], true)
                )),
            ],
        ];
    }

    /**
     * @return list<array{methods: list<string>, path: string, scopes: list<string>, summary: string, permission: ?string, module: string}>
     */
    private function apiRoutes(ModuleManifest $manifest): array
    {
        $prefix = (string) $this->config->get('api.prefix', '/api/v1');
        $items = [];

        foreach ($this->api->allRoutes() as $route) {
            if (!$this->belongsToModule($manifest, $route->module, $route->handler)) {
                continue;
            }

            $path = $route->fullPath($prefix);
            $path = preg_replace('#/+#', '/', $path) ?: $path;
            $items[] = [
                'methods' => $route->methods,
                'path' => rtrim($path, '/') ?: $path,
                'scopes' => $route->scopes,
                'summary' => $route->summary,
                'permission' => $route->permission,
                'module' => $route->module,
            ];
        }

        return $items;
    }

    /**
     * @return list<array{methods: list<string>, path: string, name: ?string, permission: ?string, handler: string}>
     */
    private function adminRoutes(ModuleManifest $manifest): array
    {
        $items = [];

        foreach ($this->router->all() as $route) {
            $name = $route->getName();
            if ($name !== null && str_starts_with($name, 'api.')) {
                continue;
            }

            if (!$this->routeBelongs($manifest, $route)) {
                continue;
            }

            $items[] = [
                'methods' => $route->getMethods(),
                'path' => $route->getPath(),
                'name' => $name,
                'permission' => $route->getPermission(),
                'handler' => $this->handlerLabel($route->getHandler()),
            ];
        }

        usort($items, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);

        return $items;
    }

    /**
     * @return list<array{name: string, type: string, listeners: int}>
     */
    private function moduleHooks(ModuleManifest $manifest): array
    {
        $id = $manifest->id;
        $items = [];

        foreach ($this->hooks->inventory() as $hook) {
            $name = $hook['name'];
            if (
                str_starts_with($name, $id . '.')
                || str_starts_with($name, $id . '_')
                || str_contains($name, '.' . $id . '.')
                || str_ends_with($name, '.' . $id)
            ) {
                $items[] = $hook;
            }
        }

        return $items;
    }

    /**
     * @return list<array{type: string, description: string, version: int, webhook: bool}>
     */
    private function moduleEvents(ModuleManifest $manifest): array
    {
        $items = [];
        foreach ($this->events->groupedByModule()[$manifest->id] ?? [] as $event) {
            $items[] = [
                'type' => $event->type,
                'description' => $event->description,
                'version' => $event->version,
                'webhook' => $event->webhookEnabled,
            ];
        }

        return $items;
    }

    /**
     * @return list<array{key: string, type: string, label: string, default: mixed}>
     */
    private function moduleSettings(ModuleManifest $manifest): array
    {
        $items = [];
        foreach ($this->settings->definitions() as $key => $definition) {
            $module = $definition['module'] ?? null;
            if ($module !== $manifest->id && !str_starts_with($key, $manifest->id . '.')) {
                continue;
            }

            $items[] = [
                'key' => $key,
                'type' => (string) $definition['type'],
                'label' => (string) $definition['label'],
                'default' => $definition['default'] ?? null,
            ];
        }

        return $items;
    }

    /**
     * @return list<array{path: string, type: string, size: ?int}>
     */
    private function fileTree(ModuleManifest $manifest): array
    {
        $root = rtrim($manifest->path, '/');
        if (!is_dir($root)) {
            return [];
        }

        $items = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $iterator->setMaxDepth(3);

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $absolute = $file->getPathname();
            $relative = ltrim(substr($absolute, strlen($root)), '/\\');
            if ($relative === '' || str_contains($relative, DIRECTORY_SEPARATOR . '.')) {
                continue;
            }

            $items[] = [
                'path' => str_replace('\\', '/', $relative),
                'type' => $file->isDir() ? 'dir' : 'file',
                'size' => $file->isFile() ? $file->getSize() : null,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }

            return $a['path'] <=> $b['path'];
        });

        return $items;
    }

    /**
     * @param list<string> $missing
     * @param list<array{version: string, ran: bool, ran_at: ?string, file: string}> $migrations
     * @return list<array{id: string, label: string, status: 'ok'|'warn'|'fail'|'info', detail: string, detail_params: array<string, string|int>}>
     */
    private function health(
        ModuleManifest $manifest,
        bool $enabled,
        array $missing,
        array $migrations,
        ?string $installedVersion,
        bool $updateAvailable,
    ): array {
        $checks = [];

        $checks[] = [
            'id' => 'enabled',
            'label' => 'modules.health.enabled.label',
            'status' => $enabled ? 'ok' : 'info',
            'detail' => $enabled ? 'modules.health.enabled.ok' : 'modules.health.enabled.off',
            'detail_params' => [],
        ];

        $classOk = class_exists($manifest->moduleClass);
        $checks[] = [
            'id' => 'class',
            'label' => 'modules.health.class.label',
            'status' => $classOk ? 'ok' : 'fail',
            'detail' => $classOk ? 'modules.health.class.ok' : 'modules.health.class.missing',
            'detail_params' => ['%class%' => $manifest->moduleClass],
        ];

        $checks[] = [
            'id' => 'dependencies',
            'label' => 'modules.health.deps.label',
            'status' => $missing === [] ? 'ok' : 'fail',
            'detail' => $missing === [] ? 'modules.health.deps.ok' : 'modules.health.deps.missing',
            'detail_params' => ['%deps%' => implode(', ', $missing)],
        ];

        $checks[] = [
            'id' => 'version',
            'label' => 'modules.health.version.label',
            'status' => $updateAvailable ? 'warn' : 'ok',
            'detail' => $installedVersion === null
                ? 'modules.health.version.not_installed'
                : ($updateAvailable
                    ? 'modules.health.version.update'
                    : 'modules.health.version.ok'),
            'detail_params' => [
                '%installed%' => $installedVersion ?? '—',
                '%current%' => $manifest->version,
            ],
        ];

        $pending = array_values(array_filter($migrations, static fn (array $row): bool => !$row['ran']));
        $checks[] = [
            'id' => 'migrations',
            'label' => 'modules.health.migrations.label',
            'status' => $pending === [] ? 'ok' : 'warn',
            'detail' => $migrations === []
                ? 'modules.health.migrations.none'
                : ($pending === []
                    ? 'modules.health.migrations.ok'
                    : 'modules.health.migrations.pending'),
            'detail_params' => [
                '%count%' => $migrations === [] ? 0 : ($pending === [] ? count($migrations) : count($pending)),
            ],
        ];

        foreach ($manifest->capabilities as $capability) {
            $providers = $this->capabilities->providers($capability);
            $checks[] = [
                'id' => 'capability:' . $capability,
                'label' => 'modules.health.capability.label',
                'status' => $providers !== [] ? 'ok' : ($enabled ? 'warn' : 'info'),
                'detail' => $providers !== []
                    ? 'modules.health.capability.ok'
                    : ($enabled
                        ? 'modules.health.capability.missing'
                        : 'modules.health.capability.pending'),
                'detail_params' => [
                    '%capability%' => $capability,
                    '%providers%' => implode(', ', $providers),
                ],
            ];
        }

        $pathOk = is_dir($manifest->path) && is_readable($manifest->path . '/module.php');
        $checks[] = [
            'id' => 'filesystem',
            'label' => 'modules.health.fs.label',
            'status' => $pathOk ? 'ok' : 'fail',
            'detail' => $pathOk ? 'modules.health.fs.ok' : 'modules.health.fs.fail',
            'detail_params' => ['%path%' => $manifest->path],
        ];

        return $checks;
    }

    /**
     * @return array<string, list<string>>
     */
    private function capabilityMap(ModuleManifest $manifest): array
    {
        $map = [];
        foreach ($manifest->capabilities as $capability) {
            $map[$capability] = $this->capabilities->providers($capability);
        }

        return $map;
    }

    private function belongsToModule(ModuleManifest $manifest, string $apiModule, mixed $handler): bool
    {
        if ($apiModule === $manifest->id || str_starts_with($apiModule, $manifest->id . '-')) {
            return true;
        }

        return $this->handlerBelongs($manifest, $handler);
    }

    private function routeBelongs(ModuleManifest $manifest, Route $route): bool
    {
        if ($this->handlerBelongs($manifest, $route->getHandler())) {
            return true;
        }

        $name = (string) $route->getName();
        $path = $route->getPath();
        $id = $manifest->id;
        $folder = basename($manifest->path);

        if ($name !== '' && (str_contains($name, '.' . $id . '.') || str_contains($name, $id))) {
            return true;
        }

        if (str_contains($path, '/' . $id) || str_contains(strtolower($path), '/' . strtolower($folder))) {
            return true;
        }

        return false;
    }

    private function handlerBelongs(ModuleManifest $manifest, mixed $handler): bool
    {
        $label = $this->handlerLabel($handler);
        $ns = 'Modules\\' . basename($manifest->path) . '\\';

        return str_contains($label, $ns);
    }

    private function handlerLabel(mixed $handler): string
    {
        if (is_array($handler) && isset($handler[0], $handler[1])) {
            $class = is_object($handler[0]) ? $handler[0]::class : (string) $handler[0];

            return $class . '::' . (string) $handler[1];
        }

        if (is_string($handler)) {
            return $handler;
        }

        if ($handler instanceof \Closure) {
            return 'Closure';
        }

        if (is_object($handler)) {
            return $handler::class;
        }

        return 'callable';
    }
}
