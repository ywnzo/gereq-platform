<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\ModuleGroupCatalog;
use Gereq\Module\ModuleInspector;
use Gereq\Module\ModuleManager;
use Gereq\Module\ModuleUpdater;
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
        private readonly ModuleGroupCatalog $groups,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        $items = [];
        foreach ($this->modules->discover() as $manifest) {
            $report = $this->inspector->inspect($manifest);
            $items[] = [
                'manifest' => $manifest,
                'enabled' => $report['overview']['enabled'],
                'missing' => $report['overview']['enabled'] ? $report['overview']['missing'] : [],
                'counts' => $report['counts'],
                'health_issues' => $report['counts']['health_issues'],
                'update_available' => $report['overview']['update_available'],
                'settings_route' => $report['overview']['settings_route'],
            ];
        }

        usort($items, static function (array $a, array $b): int {
            if ($a['update_available'] !== $b['update_available']) {
                return $a['update_available'] ? -1 : 1;
            }
            if ($a['enabled'] !== $b['enabled']) {
                return $a['enabled'] ? -1 : 1;
            }

            return $a['manifest']->name <=> $b['manifest']->name;
        });

        $updates = count(array_filter($items, static fn (array $row): bool => $row['update_available']));
        $byId = [];
        foreach ($items as $row) {
            $byId[$row['manifest']->id] = $row;
        }

        return $this->page('admin/modules/index.html.twig', [
            'pageTitle' => $this->translator->trans('admin.modules.title'),
            'items' => $items,
            'groups' => $this->groups->forIndex($byId),
            'stats' => [
                'total' => count($items),
                'enabled' => count(array_filter($items, static fn (array $row): bool => $row['enabled'])),
                'issues' => array_sum(array_map(
                    static fn (array $row): int => $row['enabled'] ? $row['health_issues'] : 0,
                    $items
                )),
                'updates' => $updates,
            ],
        ]);
    }

    public function show(string $id): Response
    {
        $manifest = $this->modules->manifest($id);
        if ($manifest === null) {
            $this->abort(404, $this->translator->trans('admin.modules.not_found'));
        }

        $report = $this->inspector->inspect($manifest);

        return $this->page('admin/modules/show.html.twig', [
            'pageTitle' => $manifest->name,
            'manifest' => $manifest,
            'enabled' => $report['overview']['enabled'],
            'missing' => $report['overview']['missing'],
            'report' => $report,
            'settingsRoute' => $report['overview']['settings_route'],
        ]);
    }

    public function enable(string $id): Response
    {
        try {
            $this->modules->enable($id);
            $this->flash('success', $this->translator->trans('admin.modules.activated'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->modulesReturnRedirect($id);
    }

    public function disable(string $id): Response
    {
        try {
            $this->modules->disable($id);
            $this->flash('success', $this->translator->trans('admin.modules.deactivated'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->modulesReturnRedirect($id);
    }

    public function update(string $id): Response
    {
        try {
            $messages = $this->updater->update($id);
            $count = count($messages);
            $this->flash('success', $this->translator->trans(
                $count > 0 ? 'admin.modules.updated_with_migrations' : 'admin.modules.updated',
                ['%count%' => $count]
            ));
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

        return $this->redirect('admin.modules.index');
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

        if (!in_array($action, ['update', 'enable', 'disable'], true)) {
            $this->flash('error', $this->translator->trans('admin.modules.bulk.error.action'));

            return $this->redirect('admin.modules.index');
        }

        $ok = 0;
        $errors = [];
        foreach ($ids as $id) {
            try {
                match ($action) {
                    'update' => $this->updater->update($id),
                    'enable' => $this->modules->enable($id),
                    'disable' => $this->modules->disable($id),
                };
                ++$ok;
            } catch (\Throwable $e) {
                $errors[] = $id . ': ' . $e->getMessage();
            }
        }

        if ($ok > 0) {
            $this->flash('success', $this->translator->trans('admin.modules.bulk.flash.' . $action, [
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
        if ($this->request()->request->get('return') === 'index') {
            return $this->redirect('admin.modules.index');
        }

        return $this->redirect('admin.modules.show', ['id' => $id]);
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
