<?php

declare(strict_types=1);

namespace Gereq\View;

use Gereq\Admin\Ui\Icons;
use Gereq\Auth\Authenticator;
use Gereq\Debug\DebugBar;
use Gereq\Hook\HookManager;
use Gereq\Http\FormState;
use Gereq\Http\Router;
use Gereq\I18n\LocaleCatalog;
use Gereq\Security\Csrf;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Extension\ProfilerExtension;
use Twig\Loader\FilesystemLoader;
use Twig\Profiler\Profile;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class TwigFactory
{
    public function __construct(
        private readonly string $basePath,
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
        private readonly ?DebugBar $debugBar = null,
    ) {
    }

    public function create(): Environment
    {
        $loader = new FilesystemLoader($this->basePath . '/resources/views');
        $modules = $this->basePath . '/modules';
        if (is_dir($modules)) {
            foreach (scandir($modules) ?: [] as $entry) {
                $views = $modules . '/' . $entry . '/Resources/views';
                if (is_dir($views)) {
                    $loader->addPath($views, strtolower($entry));
                }
            }
        }

        $twig = new Environment($loader, [
            'cache' => false,
            'autoescape' => 'html',
            'strict_variables' => false,
        ]);

        $runtime = new TwigRuntime(
            $this->router,
            $this->csrf,
            $this->auth,
            $this->assets,
            $this->requestStack,
            $this->icons,
            $this->formState,
            $this->hooks,
            $this->translator,
            $this->locales,
        );

        $twig->addFunction(new TwigFunction('url', [$runtime, 'url']));
        $twig->addFunction(new TwigFunction('csrf_field', [$runtime, 'csrfField'], ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('csrf_token', [$runtime, 'csrfToken']));
        $twig->addFunction(new TwigFunction('asset', [$runtime, 'asset']));
        $twig->addFunction(new TwigFunction('icon', [$runtime, 'icon'], ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('current_user', [$runtime, 'currentUser']));
        $twig->addFunction(new TwigFunction('route_is', [$runtime, 'routeIs']));
        $twig->addFunction(new TwigFunction('flash', [$runtime, 'flash']));
        $twig->addFunction(new TwigFunction('csp_nonce', [$runtime, 'cspNonce']));
        $twig->addFunction(new TwigFunction('old', [$runtime, 'old']));
        $twig->addFunction(new TwigFunction('error', [$runtime, 'error']));
        $twig->addFunction(new TwigFunction('errors', [$runtime, 'errors']));
        $twig->addFunction(new TwigFunction('has_error', [$runtime, 'hasError']));
        $twig->addFunction(new TwigFunction('form_errors', [$runtime, 'formErrors']));
        $twig->addFunction(new TwigFunction('do_action', [$runtime, 'doAction']));
        $twig->addFunction(new TwigFunction('apply_filters', [$runtime, 'applyFilters']));
        $twig->addFunction(new TwigFunction('trans', [$runtime, 'trans']));
        $twig->addFunction(new TwigFunction('__', [$runtime, 'trans']));
        $twig->addFunction(new TwigFunction('app_locale', [$runtime, 'appLocale']));
        $twig->addFunction(new TwigFunction('available_locales', [$runtime, 'availableLocales']));
        $twig->addFilter(new TwigFilter('trans', [$runtime, 'trans']));

        if ($this->debugBar !== null && $this->debugBar->enabled()) {
            $profile = new Profile();
            $twig->addExtension(new ProfilerExtension($profile));
            $this->debugBar->setTwigProfile($profile);
        }

        return $twig;
    }
}
