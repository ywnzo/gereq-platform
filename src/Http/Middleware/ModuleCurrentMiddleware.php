<?php

declare(strict_types=1);

namespace Gereq\Http\Middleware;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Router;
use Gereq\Module\ModuleManager;
use Gereq\Module\ModuleUpdater;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Blokuje admin UI zapnutého modulu, dokud není nainstalovaná verze = verze ze souborů
 * (a nejsou pending migrace). Nový Twig/JS se tak neukáže před „Aktualizovat“.
 */
final class ModuleCurrentMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ModuleUpdater $updater,
        private readonly ModuleManager $modules,
        private readonly Environment $twig,
        private readonly Router $router,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly TranslatorInterface $translator,
        private readonly ?string $moduleId = null,
    ) {
    }

    public function forModule(string $moduleId): self
    {
        return new self(
            $this->updater,
            $this->modules,
            $this->twig,
            $this->router,
            $this->auth,
            $this->menu,
            $this->palette,
            $this->translator,
            $moduleId,
        );
    }

    public function process(Request $request, callable $next): Response
    {
        if ($this->moduleId === null || $this->moduleId === '') {
            return $next($request);
        }

        if (!$this->modules->isEnabled($this->moduleId)) {
            return $next($request);
        }

        if (!$this->updater->isUpdateAvailable($this->moduleId)) {
            return $next($request);
        }

        $manifest = $this->modules->manifest($this->moduleId);
        if ($manifest === null) {
            return $next($request);
        }

        if ($this->wantsJson($request)) {
            return new JsonResponse([
                'error' => 'module_update_required',
                'message' => $this->translator->trans('admin.modules.gate.json', [
                    '%module%' => $manifest->name,
                ]),
                'module' => $this->moduleId,
                'update_url' => $this->router->url('admin.modules.show', ['id' => $this->moduleId], $request),
            ], 503);
        }

        $user = $this->auth->user();
        $installed = null;
        try {
            $row = gereq()->make(\Doctrine\ORM\EntityManagerInterface::class)
                ->find(\Gereq\Module\InstalledModule::class, $this->moduleId);
            $installed = $row?->version();
        } catch (\Throwable) {
            $installed = null;
        }

        $html = $this->twig->render('admin/modules/update_required.html.twig', [
            'pageTitle' => $this->translator->trans('admin.modules.gate.title'),
            'manifest' => $manifest,
            'installedVersion' => $installed,
            'returnUrl' => $request->getRequestUri(),
            'menu' => $this->menu->tree($user, $this->router, $request),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]);

        return new Response($html, 503);
    }

    private function wantsJson(Request $request): bool
    {
        if ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
            return true;
        }

        return str_contains((string) $request->headers->get('Accept', ''), 'application/json');
    }
}
