<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\GitLabModuleClient;
use Gereq\Module\ModuleGroupCatalog;
use Gereq\Module\ModuleInspector;
use Gereq\Module\ModuleManager;
use Gereq\Module\ModuleUninstaller;
use Gereq\Module\ModuleUpdater;
use Gereq\Module\ModuleVersionActivator;
use Gereq\Module\ModuleVersionBumper;
use Gereq\Module\ModuleVersionInstaller;
use Gereq\Database\Migrator;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\Support\Json;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ModuleController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly ModuleManager $modules,
        private readonly ModuleInspector $inspector,
        private readonly ModuleUpdater $updater,
        private readonly ModuleUninstaller $uninstaller,
        private readonly ModuleVersionBumper $bumper,
        private readonly Migrator $migrator,
        private readonly ModuleGroupCatalog $groups,
        private readonly ModuleVersionInstaller $versions,
        private readonly ModuleVersionActivator $activator,
        private readonly GitLabModuleClient $gitlab,
        private readonly TranslatorInterface $translator,
        private readonly Settings $settings,
        private readonly Auditor $auditor,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        $this->twig->setCache(false);
        $items = [];
        foreach ($this->modules->discover() as $manifest) {
            $report = $this->inspector->inspect($manifest);
            // First migration filename (YYYY_MM_DD_…) ≈ when the module was introduced.
            $created = isset($report['migrations'][0]['file'])
                ? (string) $report['migrations'][0]['file']
                : '9999_99_99_999999';
            $items[] = [
                'manifest' => $manifest,
                'parent_name' => $manifest->parentId !== null ? $this->modules->manifest($manifest->parentId)?->name : null,
                'installed' => $this->modules->installed($manifest->id) !== null,
                'enabled' => $report['overview']['enabled'],
                'missing' => $report['overview']['enabled'] ? $report['overview']['missing'] : [],
                'counts' => $report['counts'],
                'health_issues' => $report['counts']['health_issues'],
                'update_available' => $report['overview']['update_available'],
                'settings_route' => $report['overview']['settings_route'],
                'created' => $created,
            ];
        }

        // Chronological (oldest first); pending updates stay on top.
        usort($items, static function (array $a, array $b): int {
            if ($a['update_available'] !== $b['update_available']) {
                return $a['update_available'] ? -1 : 1;
            }

            $byCreated = $a['created'] <=> $b['created'];
            if ($byCreated !== 0) {
                return $byCreated;
            }

            return $a['manifest']->name <=> $b['manifest']->name;
        });

        $updates = count(array_filter($items, static fn (array $row): bool => $row['update_available']));
        $byId = [];
        foreach ($items as $row) {
            $byId[$row['manifest']->id] = $row;
        }

        $moduleItems = array_values(array_filter(
            $items,
            static fn (array $row): bool => !$row['manifest']->isIntegration(),
        ));
        // Keep children directly below their parent while preserving root ordering.
        $childCounts = [];
        foreach ($moduleItems as $row) {
            $parentId = $row['manifest']->parentId;
            if ($parentId !== null) {
                $childCounts[$parentId] = ($childCounts[$parentId] ?? 0) + 1;
            }
        }
        $ordered = [];
        foreach ($moduleItems as $row) {
            if ($row['manifest']->parentId !== null) {
                continue;
            }
            $row['child_count'] = $childCounts[$row['manifest']->id] ?? 0;
            $row['parent_enabled'] = true;
            $ordered[] = $row;
            $children = [];
            foreach ($moduleItems as $child) {
                if ($child['manifest']->parentId === $row['manifest']->id) {
                    $child['child_count'] = 0;
                    // Submodules cannot be activated while the parent module is off.
                    $child['parent_enabled'] = $row['enabled'];
                    $children[] = $child;
                }
            }
            usort($children, static fn (array $a, array $b): int => $a['manifest']->name <=> $b['manifest']->name);
            foreach ($children as $child) {
                $ordered[] = $child;
            }
        }
        $moduleItems = $ordered;
        $pluginGroups = $this->groups->forIndex($byId, 'plugins');
        $pluginEnabled = 0;
        $pluginTotal = 0;
        foreach ($pluginGroups as $g) {
            $pluginEnabled += $g['enabled'];
            $pluginTotal += $g['total'];
        }

        return $this->page('admin/modules/index.html.twig', [
            'pageTitle' => $this->translator->trans('admin.modules.title'),
            'items' => $moduleItems,
            'groups' => $this->groups->forIndex($byId, 'packages'),
            'pluginGroups' => $pluginGroups,
            'stats' => [
                'total' => count($moduleItems),
                'enabled' => count(array_filter($moduleItems, static fn (array $row): bool => $row['enabled'])),
                'issues' => array_sum(array_map(
                    static fn (array $row): int => $row['enabled'] ? $row['health_issues'] : 0,
                    $moduleItems
                )),
                'updates' => count(array_filter($moduleItems, static fn (array $row): bool => $row['update_available'])),
            ],
            'pluginStats' => [
                'groups' => count($pluginGroups),
                'enabled' => $pluginEnabled,
                'total' => $pluginTotal,
            ],
        ]);
    }

    public function show(string $id): Response
    {
        $this->twig->setCache(false);
        $manifest = $this->modules->manifest($id);
        if ($manifest === null) {
            $this->abort(404, $this->translator->trans('admin.modules.not_found'));
        }

        $children = [];
        foreach ($this->modules->discover() as $child) {
            if ($child->parentId !== $id) {
                continue;
            }
            $children[] = [
                'manifest' => $child,
                'enabled' => $this->modules->isEnabled($child->id),
                'installed' => $this->modules->installed($child->id) !== null,
                'missing' => $this->modules->missingDependencies($child),
                'update_available' => $this->updater->isUpdateAvailable($child->id),
            ];
        }

        $report = $this->inspector->inspect($manifest);
        $settingValues = [];
        foreach ($this->settings->definitionsForModule($manifest->id) as $key => $definition) {
            $settingValues[$key] = $this->settings->get($key, $definition['default'] ?? null);
        }
        $versionRows = [];
        $versionsError = null;
        $gitlabConfigured = $manifest->parentId === null && $this->gitlab->configured();
        try {
            $versionRows = $manifest->parentId === null ? $this->versions->listVersions($id, true) : [];
        } catch (\Throwable $e) {
            $versionsError = $e->getMessage();
        }

        $remoteLatest = null;
        $remoteNewer = false;
        $remoteOlderCount = 0;
        foreach ($versionRows as $row) {
            if ($remoteLatest === null) {
                $remoteLatest = $row['version'];
            }
            if (!empty($row['is_upgrade'])) {
                $remoteNewer = true;
            }
            if (!empty($row['is_downgrade'])) {
                ++$remoteOlderCount;
            }
        }

        $parentEnabled = $manifest->parentId === null
            || $this->modules->isEnabled($manifest->parentId);

        return $this->page('admin/modules/show.html.twig', [
            'pageTitle' => $manifest->name,
            'manifest' => $manifest,
            'children' => $children,
            'enabled' => $report['overview']['enabled'],
            'parent_enabled' => $parentEnabled,
            'missing' => $report['overview']['missing'],
            'report' => $report,
            'settingsRoute' => $report['overview']['settings_route'],
            'settingValues' => $settingValues,
            'versionRows' => $versionRows,
            'versionsError' => $versionsError,
            'gitlabConfigured' => $gitlabConfigured,
            'gitlabProject' => $gitlabConfigured ? $this->gitlab->projectPathFor($manifest) : null,
            'remoteLatest' => $remoteLatest,
            'remoteNewer' => $remoteNewer,
            'remoteOlderCount' => $remoteOlderCount,
        ]);
    }

    public function fetchVersions(string $id): Response
    {
        $manifest = $this->modules->manifest($id);
        if ($manifest === null) {
            $this->abort(404, $this->translator->trans('admin.modules.not_found'));
        }

        try {
            $this->versions->listVersions($id, true);
            $this->flash('success', $this->translator->trans('admin.modules.versions.refreshed'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('admin.modules.show', ['id' => $id]);
    }

    public function fetchVersion(Request $request, string $id, string $version): Response
    {
        try {
            $this->versions->fetch($id, $version);
            $this->flash('success', $this->translator->trans('admin.modules.versions.fetched', [
                '%version%' => $version,
            ]));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('admin.modules.show', ['id' => $id]);
    }

    public function activateVersion(Request $request, string $id, string $version): Response
    {
        $manifest = $this->modules->manifest($id);
        if ($manifest === null) {
            $this->abort(404, $this->translator->trans('admin.modules.not_found'));
        }

        $target = \Gereq\Module\GitLabModuleClient::normalizeVersion($version);
        $current = \Gereq\Module\GitLabModuleClient::normalizeVersion($manifest->version);
        if (version_compare($target, $current) < 0 && !$request->request->getBoolean('confirm_downgrade')) {
            $this->flash('error', $this->translator->trans('admin.modules.versions.downgrade_required'));

            return $this->redirect('admin.modules.show', ['id' => $id]);
        }

        try {
            $messages = $this->activator->activate($id, $version, true);
            $this->flash('success', $this->translator->trans('admin.modules.versions.activated', [
                '%version%' => $target,
                '%count%' => count($messages),
            ]));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('admin.modules.show', ['id' => $id]);
    }

    public function enable(string $id): Response
    {
        try {
            $this->modules->enable($id);
            $this->migrator->migrateModule($id);
            $message = $this->translator->trans('admin.modules.activated');
            if (!$this->wantsJson()) {
                $this->flash('success', $message);
            }

            return $this->modulesActionResponse(
                action: 'enable',
                id: $id,
                message: $message,
                modules: $this->moduleStates([$id]),
            );
        } catch (\Throwable $e) {
            return $this->modulesActionError($id, $e->getMessage());
        }
    }

    public function pause(string $id): Response
    {
        try {
            $affected = $this->modules->expandWithDependents([$id]);
            $result = $this->modules->pauseMany([$id]);
            $message = null;
            if ($result['ok'] > 0) {
                $message = $this->translator->trans('admin.modules.paused', [
                    '%count%' => $result['ok'],
                ]);
                if (!$this->wantsJson()) {
                    $this->flash('success', $message);
                }
            }
            $error = $result['errors'] !== []
                ? implode(' · ', array_slice($result['errors'], 0, 5))
                : null;
            if ($error !== null && !$this->wantsJson()) {
                $this->flash('error', $error);
            }
            if ($this->wantsJson() && $result['ok'] === 0 && $error !== null) {
                return $this->jsonError('module_pause_failed', $error, [], 422);
            }

            return $this->modulesActionResponse(
                action: 'pause',
                id: $id,
                message: $message ?? '',
                modules: $this->moduleStates($affected),
                error: $error,
            );
        } catch (\Throwable $e) {
            return $this->modulesActionError($id, $e->getMessage());
        }
    }

    public function disable(Request $request, string $id): Response
    {
        $purgeMedia = $request->request->getBoolean('purge_media');
        try {
            $affected = $this->modules->expandWithDependents([$id], true);
            $result = $this->uninstaller->uninstallMany([$id], $purgeMedia);
            $message = null;
            if ($result['ok'] > 0) {
                $message = $this->translator->trans(
                    $purgeMedia ? 'admin.modules.deactivated_purged_media' : 'admin.modules.deactivated_purged',
                    ['%count%' => $result['ok']]
                );
                if (!$this->wantsJson()) {
                    $this->flash('success', $message);
                }
            }
            $error = $result['errors'] !== []
                ? implode(' · ', array_slice($result['errors'], 0, 5))
                : null;
            if ($error !== null && !$this->wantsJson()) {
                $this->flash('error', $error);
            }
            if ($this->wantsJson() && $result['ok'] === 0 && $error !== null) {
                return $this->jsonError('module_disable_failed', $error, [], 422);
            }

            return $this->modulesActionResponse(
                action: 'disable',
                id: $id,
                message: $message ?? '',
                modules: $this->moduleStates($affected),
                error: $error,
            );
        } catch (\Throwable $e) {
            return $this->modulesActionError($id, $e->getMessage());
        }
    }

    public function saveSettings(Request $request, string $id): Response
    {
        $manifest = $this->modules->manifest($id);
        if ($manifest === null) {
            $this->abort(404, $this->translator->trans('admin.modules.not_found'));
        }

        foreach ($this->settings->definitionsForModule($manifest->id) as $key => $definition) {
            $posted = $request->request->get(str_replace('.', '__', $key));
            $type = (string) $definition['type'];
            $module = is_string($definition['module'] ?? null) ? $definition['module'] : $manifest->id;
            $secret = $type === SettingRecord::TYPE_ENCRYPTED
                || str_contains(strtolower($key), 'secret')
                || str_contains(strtolower($key), 'password')
                || str_contains(strtolower($key), 'token')
                || str_contains(strtolower($key), 'api_key');
            if ($secret && (!is_string($posted) || $posted === '')) {
                continue;
            }

            if ($type === SettingRecord::TYPE_BOOLEAN) {
                $this->settings->set($key, $posted === '1', $type, $module);
                continue;
            }

            if ($type === SettingRecord::TYPE_JSON) {
                $decoded = is_string($posted) && $posted !== '' ? Json::decode($posted) : [];
                $this->settings->set($key, is_array($decoded) ? $decoded : [], $type, $module);
                continue;
            }

            if ($type === SettingRecord::TYPE_INTEGER) {
                $this->settings->set($key, (int) $posted, $type, $module);
                continue;
            }

            $this->settings->set($key, (string) $posted, $type, $module);
        }

        $this->auditor->log('settings.updated', 'module', $manifest->id);
        $this->flash('success', $this->translator->trans('settings.saved'));

        return $this->redirectTo($this->router->url('admin.modules.show', ['id' => $id], $this->request()) . '#settings');
    }

    public function update(string $id): Response
    {
        try {
            $bump = (string) ($this->request()?->request->get('bump') ?? '') === '1';
            $bumpedTo = null;
            if ($bump) {
                $manifest = $this->modules->manifest($id);
                $installed = $this->modules->installed($id);
                if ($manifest === null || $installed === null || $manifest->version !== $installed->version()) {
                    throw new \RuntimeException($this->translator->trans('admin.modules.gate.bump_same_version_only'));
                }
                $bumpedTo = $this->bumper->bumpPatch($id);
            }

            $messages = $this->updater->update($id);
            $count = count($messages);
            if ($bumpedTo !== null) {
                $this->flash('success', $this->translator->trans('admin.modules.updated_with_bump', [
                    '%version%' => $bumpedTo,
                    '%count%' => $count,
                ]));
            } else {
                $this->flash('success', $this->translator->trans(
                    $count > 0 ? 'admin.modules.updated_with_migrations' : 'admin.modules.updated',
                    ['%count%' => $count]
                ));
            }
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->modulesReturnRedirect($id);
    }

    public function enableGroup(string $group): Response
    {
        if (!$this->groups->has($group)) {
            $this->flash('error', $this->translator->trans('admin.modules.group.not_found'));

            return $this->redirect('admin.modules.index');
        }

        try {
            $ids = $this->groups->enableOrder($group);
        } catch (\Throwable $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.modules.index');
        }

        $ok = 0;
        $skipped = 0;
        $errors = [];
        foreach ($ids as $id) {
            if ($this->modules->isEnabled($id)) {
                ++$skipped;
                continue;
            }
            try {
                $this->modules->enable($id);
                $this->migrator->migrateModule($id);
                ++$ok;
            } catch (\Throwable $e) {
                $errors[] = $id . ': ' . $e->getMessage();
            }
        }

        if ($ok > 0) {
            $this->flash('success', $this->translator->trans('admin.modules.group.enabled', [
                '%count%' => $ok,
                '%group%' => $group,
            ]));
        } elseif ($skipped > 0 && $errors === []) {
            $this->flash('success', $this->translator->trans('admin.modules.group.already_enabled'));
        }
        if ($errors !== []) {
            $this->flash('error', implode(' · ', array_slice($errors, 0, 5)));
        }

        $return = $this->request()?->request->get('return');
        if (is_string($return) && $return !== '') {
            $path = $this->safeReturnPath($return);
            if ($path !== null) {
                return $this->redirectTo($path);
            }
        }

        return $this->redirectTo($this->router->url('admin.modules.index', [], $this->request()) . '#packages');
    }

    public function bulk(Request $request): Response
    {
        $action = (string) $request->request->get('action', '');
        $ids = $request->request->all('ids');
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): string => is_string($id) ? trim($id) : '',
            $ids,
        ), static fn (string $id): bool => $id !== '')));

        if ($action === 'update_outdated') {
            foreach ($this->modules->discover() as $manifest) {
                if ($this->updater->isUpdateAvailable($manifest->id)) {
                    $ids[] = $manifest->id;
                }
            }
            $ids = array_values(array_unique($ids));
            $action = 'update';
        }

        if ($ids === []) {
            $this->flash('error', $this->translator->trans('admin.modules.bulk.error.empty'));

            return $this->redirect('admin.modules.index');
        }

        if (!in_array($action, ['update', 'enable', 'disable', 'pause'], true)) {
            $this->flash('error', $this->translator->trans('admin.modules.bulk.error.action'));

            return $this->redirect('admin.modules.index');
        }

        $ok = 0;
        $errors = [];
        $purgeMedia = $request->request->getBoolean('purge_media');
        $needSchemaSync = false;

        if ($action === 'disable') {
            $result = $this->uninstaller->uninstallMany($ids, $purgeMedia);
            $ok = $result['ok'];
            $errors = $result['errors'];
        } elseif ($action === 'pause') {
            $result = $this->modules->pauseMany($ids);
            $ok = $result['ok'];
            $errors = $result['errors'];
        } else {
            $ordered = $action === 'enable' ? $this->modules->sortForEnable($ids) : $ids;
            foreach ($ordered as $id) {
                try {
                    if ($action === 'update') {
                        $this->updater->update($id, syncSchema: false);
                    } else {
                        $this->modules->enable($id);
                        $this->migrator->migrateModule($id, syncSchema: false);
                    }
                    $needSchemaSync = true;
                    ++$ok;
                } catch (\Throwable $e) {
                    $errors[] = $id . ': ' . $e->getMessage();
                }
            }
            if ($needSchemaSync) {
                try {
                    $this->migrator->syncSchema();
                } catch (\Throwable $e) {
                    $errors[] = 'schema: ' . $e->getMessage();
                }
            }
        }

        if ($ok > 0) {
            $key = 'admin.modules.bulk.flash.' . $action;
            if ($action === 'disable' && $purgeMedia) {
                $key = 'admin.modules.bulk.flash.disable_media';
            }
            $this->flash('success', $this->translator->trans($key, [
                '%count%' => $ok,
            ]));
        }
        if ($errors !== []) {
            $this->flash('error', implode(' · ', array_slice($errors, 0, 5)));
        }

        return $this->redirect('admin.modules.index');
    }

    private function modulesReturnRedirect(string $id): Response
    {
        $return = $this->request()->request->get('return');
        if ($return === 'index') {
            return $this->redirect('admin.modules.index');
        }

        if (is_string($return) && $return !== '') {
            $path = $this->safeReturnPath($return);
            if ($path !== null) {
                return $this->redirectTo($path);
            }
        }

        return $this->redirect('admin.modules.show', ['id' => $id]);
    }

    /**
     * @param list<string> $ids
     * @return list<array{id: string, enabled: bool, installed: bool, parent_id: ?string, parent_enabled: bool}>
     */
    private function moduleStates(array $ids): array
    {
        $out = [];
        foreach (array_values(array_unique($ids)) as $moduleId) {
            if ($moduleId === '') {
                continue;
            }
            $manifest = $this->modules->manifest($moduleId);
            if ($manifest === null) {
                continue;
            }
            $parentId = $manifest->parentId;
            $out[] = [
                'id' => $moduleId,
                'enabled' => $this->modules->isEnabled($moduleId),
                'installed' => $this->modules->installed($moduleId) !== null,
                'parent_id' => $parentId,
                'parent_enabled' => $parentId === null || $this->modules->isEnabled($parentId),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{id: string, enabled: bool, installed: bool, parent_id: ?string, parent_enabled: bool}> $modules
     */
    private function modulesActionResponse(
        string $action,
        string $id,
        string $message,
        array $modules,
        ?string $error = null,
    ): Response {
        if ($this->wantsJson()) {
            return $this->json([
                'ok' => $error === null || $modules !== [],
                'action' => $action,
                'id' => $id,
                'message' => $message,
                'error' => $error,
                'modules' => $modules,
            ]);
        }

        return $this->modulesReturnRedirect($id);
    }

    private function modulesActionError(string $id, string $message): Response
    {
        if ($this->wantsJson()) {
            return $this->jsonError('module_action_failed', $message, [], 422);
        }
        $this->flash('error', $message);

        return $this->modulesReturnRedirect($id);
    }

    /**
     * Only same-origin / relative paths — blocks open redirects.
     */
    private function safeReturnPath(string $url): ?string
    {
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }

        $request = $this->request();
        if ($request === null) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['path'])) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $expected = strtolower($request->getHost());
        if ($host !== '' && $host !== $expected) {
            return null;
        }

        $path = (string) $parts['path'];
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $path .= '#' . $parts['fragment'];
        }

        return $path !== '' ? $path : null;
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }
}
