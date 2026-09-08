<?php

declare(strict_types=1);

namespace Gereq\View;

use Gereq\Admin\Ui\Icons;
use Gereq\Auth\Authenticator;
use Gereq\Brand\Branding;
use Gereq\Hook\HookManager;
use Gereq\Http\FormState;
use Gereq\Http\Router;
use Gereq\I18n\LocaleCatalog;
use Gereq\Security\Csrf;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Twig funkce platformy — cíle pro Go to Definition z .twig šablon.
 */
final class TwigRuntime
{
    public function __construct(
        private readonly Router $router,
        private readonly Csrf $csrf,
        private readonly Authenticator $auth,
        private readonly AssetManager $assets,
        private readonly RequestStack $requestStack,
        private readonly Icons $icons,
        private readonly FormState $formState,
        private readonly HookManager $hooks,
        private readonly TranslatorInterface $translator,
        private readonly LocaleCatalog $locales,
        private readonly Branding $branding,
    ) {
    }

    /**
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    public function brand(?string $key = null): mixed
    {
        $resolved = $this->branding->resolve();
        if ($key === null || $key === '') {
            return $resolved;
        }

        return $resolved[$key] ?? null;
    }

    public function brandCss(): string
    {
        return $this->branding->adminCssVariables();
    }

    public function brandHtmlStyle(): string
    {
        return $this->branding->htmlStyleAttribute();
    }

    /** @param array<string, mixed> $params */
    public function url(string $name, array $params = []): string
    {
        return $this->router->url($name, $params, $this->requestStack->getCurrentRequest());
    }

    public function routeExists(string $name): bool
    {
        return $this->router->has($name);
    }

    public function csrfField(): string
    {
        return $this->csrf->field();
    }

    public function csrfToken(): string
    {
        return $this->csrf->token();
    }

    public function asset(string $entry): string
    {
        return $this->assets->url($entry);
    }

    public function viteHot(): bool
    {
        return $this->assets->isHot();
    }

    public function viteTags(string $entry = 'resources/js/admin.ts'): string
    {
        return $this->assets->tags($entry, $this->cspNonce());
    }

    public function icon(string $name, string $class = ''): string
    {
        return $this->icons->svg($name, $class);
    }

    public function currentUser(): mixed
    {
        return $this->auth->user();
    }

    public function routeIs(string $name): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return false;
        }

        $route = (string) $request->attributes->get('_route');

        return $route === $name || str_starts_with($route, $name);
    }

    /**
     * @return list<array{label: string, url?: string|null}>
     */
    public function adminBreadcrumbs(?string $pageTitle = null): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $route = $request !== null ? (string) $request->attributes->get('_route', '') : '';

        /** @var \Gereq\Admin\Menu\Menu $menu */
        $menu = gereq()->make(\Gereq\Admin\Menu\Menu::class);

        return $menu->breadcrumbsFor($route, $this->router, $request, $pageTitle);
    }

    public function flash(string $key): mixed
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();
        if (!$session instanceof Session) {
            return null;
        }

        return $session->getFlashBag()->get($key);
    }

    public function cspNonce(): string
    {
        return (string) $this->requestStack->getCurrentRequest()?->attributes->get('csp_nonce', '');
    }

    public function old(string $key, mixed $default = null, ?string $form = null): mixed
    {
        return $this->formState->old($key, $default, $form);
    }

    public function error(string $key, ?string $form = null): ?string
    {
        return $this->formState->error($key, $form);
    }

    /** @return array<string, list<string>> */
    public function errors(?string $form = null): array
    {
        return $this->formState->errors($form);
    }

    public function hasError(string $key, ?string $form = null): bool
    {
        return $this->formState->error($key, $form) !== null;
    }

    public function formErrors(?string $form = null): bool
    {
        return $this->formState->hasErrors($form);
    }

    public function doAction(string $hook, mixed ...$payload): void
    {
        $this->hooks->doAction($hook, ...$payload);
    }

    public function applyFilters(string $hook, mixed $value, mixed ...$context): mixed
    {
        return $this->hooks->filter($hook, $value, ...$context);
    }

    /** @param array<string, mixed> $parameters */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $this->translator->trans($id, $parameters, $domain, $locale);
    }

    public function auditAction(string $action): string
    {
        $key = 'audit.action.' . $action;
        $translated = $this->translator->trans($key);

        return $translated === $key ? $action : $translated;
    }

    public function auditActionTone(string $action): string
    {
        if (
            str_ends_with($action, '.deleted')
            || str_ends_with($action, '.revoked')
            || str_ends_with($action, '.disabled')
        ) {
            return 'danger';
        }

        if (
            str_ends_with($action, '.created')
            || $action === 'auth.login'
            || str_ends_with($action, '.enabled')
            || str_ends_with($action, '.uploaded')
            || str_ends_with($action, '.published')
        ) {
            return 'ok';
        }

        if (
            str_contains($action, '.updated')
            || str_ends_with($action, '.status')
            || str_ends_with($action, '.synced')
            || str_contains($action, '_changed')
            || str_ends_with($action, '.default_changed')
        ) {
            return 'warn';
        }

        if ($action === 'auth.logout') {
            return 'info';
        }

        return 'muted';
    }

    public function appLocale(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null) {
            $locale = $request->attributes->get('_locale');
            if (is_string($locale) && $locale !== '') {
                return $locale;
            }

            $requestLocale = $request->getLocale();
            if ($requestLocale !== '') {
                return $requestLocale;
            }
        }

        if ($this->translator instanceof \Symfony\Contracts\Translation\LocaleAwareInterface) {
            return $this->translator->getLocale();
        }

        return $this->locales->fallback();
    }

    /** @return array<string, string> */
    public function availableLocales(): array
    {
        return $this->locales->all();
    }
}
