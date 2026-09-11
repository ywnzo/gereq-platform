<?php

declare(strict_types=1);

namespace Gereq\Admin\Http\Developer;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Api\ApiRegistry;
use Gereq\Api\Auth\ApiTokenService;
use Gereq\Api\Auth\PublicApiKey;
use Gereq\Api\OpenApi\OpenApiGenerator;
use Gereq\Auth\Authenticator;
use Gereq\Config\Repository;
use Gereq\Event\EventRegistry;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Security\Csrf;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DeveloperController extends Controller
{
    private const SESSION_TESTER_PLAIN = 'api_tester.plain';
    private const SESSION_TESTER_ID = 'api_tester.token_id';

    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly ApiRegistry $api,
        private readonly EventRegistry $events,
        private readonly TranslatorInterface $translator,
        private readonly OpenApiGenerator $openApi,
        private readonly Repository $config,
        private readonly ApiTokenService $tokens,
        private readonly PublicApiKey $publicApiKey,
        private readonly Csrf $csrf,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function api(): Response
    {
        $prefix = rtrim((string) $this->config->get('api.prefix', '/api/v1'), '/');
        $privateModules = [];
        foreach ($this->api->allRoutes() as $route) {
            $fullPath = preg_replace('#/+#', '/', $route->fullPath($prefix)) ?: $prefix;
            $fullPath = rtrim($fullPath, '/') ?: '/';
            $ruleset = is_string($route->meta['ruleset'] ?? null) ? $route->meta['ruleset'] : null;
            foreach ($route->methods as $method) {
                $summary = $route->summary !== ''
                    ? $route->summary
                    : $this->openApi->inferSummary(strtolower($method), $fullPath, $route->module);
                $privateModules[$route->module][] = [
                    'method' => strtoupper($method),
                    'path' => $fullPath,
                    'scopes' => $route->scopes,
                    'summary' => $summary,
                    'ruleset' => $ruleset,
                ];
            }
        }

        $publicModules = [];
        foreach ($this->router->all() as $route) {
            $fullPath = preg_replace('#/+#', '/', $route->getPath()) ?: '/';
            $fullPath = rtrim($fullPath, '/') ?: '/';
            if (!str_starts_with($fullPath, '/api/public/') && $fullPath !== '/api/public') {
                continue;
            }
            $module = $this->publicApiModule($fullPath, $route->getModule());
            foreach ($route->getMethods() as $method) {
                $method = strtoupper($method);
                if ($method === 'OPTIONS' || $method === 'HEAD') {
                    continue;
                }
                $publicModules[$module][] = [
                    'method' => $method,
                    'path' => $fullPath,
                    'scopes' => $route->getScopes(),
                    'summary' => $this->openApi->inferSummary(strtolower($method), $fullPath, $module),
                    'ruleset' => null,
                ];
            }
        }

        $this->sortApiModules($privateModules);
        $this->sortApiModules($publicModules);

        $user = $this->auth->user();

        return $this->page('admin/developer/api/index.html.twig', [
            'pageTitle' => $this->translator->trans('admin.api.title'),
            'privateModules' => $privateModules,
            'publicModules' => $publicModules,
            'privateCount' => $this->countApiRoutes($privateModules),
            'publicCount' => $this->countApiRoutes($publicModules),
            'publicApiKey' => $this->publicApiKey->configured(),
            'publicApiKeyMasked' => $this->publicApiKey->masked(),
            'scopes' => $this->api->scopes(),
            'apiBase' => $this->request()->getSchemeAndHttpHost(),
            'testerTokenUrl' => $this->router->url('admin.developer.api.tester_token'),
            'testerUserId' => $user?->id() ?? '',
            'csrfToken' => $this->csrf->token(),
        ]);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $modules
     */
    private function sortApiModules(array &$modules): void
    {
        foreach ($modules as &$routes) {
            usort(
                $routes,
                static fn (array $a, array $b): int => [$a['path'], $a['method']] <=> [$b['path'], $b['method']],
            );
        }
        unset($routes);
        ksort($modules);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $modules
     */
    private function countApiRoutes(array $modules): int
    {
        $n = 0;
        foreach ($modules as $routes) {
            $n += count($routes);
        }

        return $n;
    }

    private function publicApiModule(string $path, ?string $routeModule): string
    {
        if ($routeModule !== null && $routeModule !== '') {
            return $routeModule;
        }
        $rest = trim(substr($path, strlen('/api/public')), '/');
        $segment = explode('/', $rest)[0] ?? '';

        return $segment !== '' ? $segment : 'public';
    }

    public function testerToken(Request $request): JsonResponse
    {
        $user = $this->auth->user();
        if ($user === null) {
            return new JsonResponse(['error' => 'unauthenticated'], 401);
        }

        $session = $request->getSession();
        $force = (bool) $request->request->get('rotate', $request->query->get('rotate', false));
        $plain = (string) $session->get(self::SESSION_TESTER_PLAIN, '');
        if (!$force && $plain !== '') {
            $existing = $this->tokens->findByPlain($plain);
            if ($existing !== null && $existing->userId() === $user->id() && $existing->isActive()) {
                return new JsonResponse([
                    'token' => $plain,
                    'token_id' => $existing->id(),
                    'prefix' => $existing->tokenPrefix(),
                    'rotated' => false,
                ]);
            }
        }

        $created = $this->tokens->issueTesterToken($user, $this->api->scopes());
        $session->set(self::SESSION_TESTER_PLAIN, $created['plain']);
        $session->set(self::SESSION_TESTER_ID, $created['token']->id());

        return new JsonResponse([
            'token' => $created['plain'],
            'token_id' => $created['token']->id(),
            'prefix' => $created['token']->tokenPrefix(),
            'rotated' => true,
        ]);
    }

    public function events(): Response
    {
        return $this->page('admin/developer/events/index.html.twig', [
            'pageTitle' => $this->translator->trans('admin.events.title'),
            'groups' => $this->events->groupedByModule(),
        ]);
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
