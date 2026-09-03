<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Gereq\Module\ModuleManager;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
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
        private readonly CapabilityRegistry $capabilities,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        $items = [];
        foreach ($this->modules->discover() as $manifest) {
            $items[] = [
                'manifest' => $manifest,
                'enabled' => $this->modules->isEnabled($manifest->id),
                'missing' => $this->modules->missingDependencies($manifest),
            ];
        }

        return $this->page('admin/modules/index.html.twig', [
            'pageTitle' => 'Moduly',
            'items' => $items,
        ]);
    }

    public function show(string $id): Response
    {
        $manifest = $this->modules->manifest($id);
        if ($manifest === null) {
            $this->abort(404, 'Modul neexistuje.');
        }

        return $this->page('admin/modules/show.html.twig', [
            'pageTitle' => $manifest->name,
            'manifest' => $manifest,
            'enabled' => $this->modules->isEnabled($id),
            'missing' => $this->modules->missingDependencies($manifest),
            'capabilityProviders' => $this->capabilities->all(),
        ]);
    }

    public function enable(string $id): Response
    {
        try {
            $this->modules->enable($id);
            $this->flash('success', 'Modul byl aktivován. Obnovte stránku, aby se načetly jeho služby.');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('admin.modules.show', ['id' => $id]);
    }

    public function disable(string $id): Response
    {
        try {
            $this->modules->disable($id);
            $this->flash('success', 'Modul byl deaktivován.');
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
