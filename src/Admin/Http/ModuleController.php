<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\ModuleInspector;
use Gereq\Module\ModuleManager;
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
                'missing' => $report['overview']['missing'],
                'counts' => $report['counts'],
                'health_issues' => $report['counts']['health_issues'],
            ];
        }

        usort($items, static function (array $a, array $b): int {
            if ($a['enabled'] !== $b['enabled']) {
                return $a['enabled'] ? -1 : 1;
            }

            return $a['manifest']->name <=> $b['manifest']->name;
        });

        return $this->page('admin/modules/index.html.twig', [
            'pageTitle' => $this->translator->trans('admin.modules.title'),
            'items' => $items,
            'stats' => [
                'total' => count($items),
                'enabled' => count(array_filter($items, static fn (array $row): bool => $row['enabled'])),
                'issues' => array_sum(array_map(static fn (array $row): int => $row['health_issues'], $items)),
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

        return $this->redirect('admin.modules.show', ['id' => $id]);
    }

    public function disable(string $id): Response
    {
        try {
            $this->modules->disable($id);
            $this->flash('success', $this->translator->trans('admin.modules.deactivated'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
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
