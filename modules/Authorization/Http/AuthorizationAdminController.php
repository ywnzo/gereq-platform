<?php

declare(strict_types=1);

namespace Modules\Authorization\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\ModuleManager;
use Modules\Authorization\Application\OAuthProviderRegistry;
use Modules\Authorization\Application\OAuthUrls;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class AuthorizationAdminController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly TranslatorInterface $translator,
        private readonly OAuthProviderRegistry $registry,
        private readonly OAuthUrls $urls,
        private readonly ModuleManager $modules,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $rows = [];
        foreach ($this->modules->discover() as $manifest) {
            if (!in_array('plugin.oauth', $manifest->groups, true)) {
                continue;
            }
            $providerId = preg_replace('/_oauth$/', '', $manifest->id) ?: $manifest->id;
            $provider = $this->registry->get($providerId);
            $rows[] = [
                'id' => $providerId,
                'plugin' => $manifest->id,
                'label' => $provider?->label() ?? $manifest->name,
                'configured' => $provider?->configured() ?? false,
                'enabled' => $this->modules->isEnabled($manifest->id),
                'callback' => $this->urls->callbackUrl($request, $providerId),
            ];
        }

        $user = $this->auth->user();

        return $this->render('@authorization/index.html.twig', [
            'pageTitle' => $this->translator->trans('authorization.title'),
            'rows' => $rows,
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]);
    }
}
