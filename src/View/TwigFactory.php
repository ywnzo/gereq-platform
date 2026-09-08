<?php

declare(strict_types=1);

namespace Gereq\View;

use Gereq\Admin\Ui\Icons;
use Gereq\Admin\Ui\Ui;
use Gereq\Auth\Authenticator;
use Gereq\Brand\Branding;
use Gereq\Config\Repository;
use Gereq\Debug\DebugBar;
use Gereq\Hook\HookManager;
use Gereq\Http\FormState;
use Gereq\Http\Router;
use Gereq\I18n\LocaleCatalog;
use Gereq\Security\Csrf;
use Gereq\Support\AppVersion;
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
        private readonly Branding $branding,
        private readonly Repository $config,
        private readonly ?DebugBar $debugBar = null,
    ) {
    }

    public function create(): Environment
    {
        $loader = new FilesystemLoader($this->basePath . '/resources/views');
        foreach (['modules', 'integrations'] as $dirName) {
            $root = $this->basePath . '/' . $dirName;
            if (!is_dir($root)) {
                continue;
            }
            foreach (scandir($root) ?: [] as $entry) {
                $views = $root . '/' . $entry . '/Resources/views';
                if (is_dir($views)) {
                    $loader->addPath($views, strtolower($entry));
                }
            }
        }

        $debug = (bool) $this->config->get('app.debug', false);
        $twigCache = (bool) $this->config->get('cache.twig', true);
        $cacheDir = $this->basePath . '/storage/cache/twig';
        if ($twigCache && !$debug) {
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0775, true);
            }
        }

        $twig = new Environment($loader, [
            'cache' => ($twigCache && !$debug) ? $cacheDir : false,
            'auto_reload' => $debug,
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
            $this->branding,
        );

        $twig->addFunction(new TwigFunction('url', [$runtime, 'url']));
        $twig->addFunction(new TwigFunction('route_exists', [$runtime, 'routeExists']));
        $twig->addFunction(new TwigFunction('csrf_field', [$runtime, 'csrfField'], ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('csrf_token', [$runtime, 'csrfToken']));
        $twig->addFunction(new TwigFunction('asset', [$runtime, 'asset']));
        $twig->addFunction(new TwigFunction('vite_hot', [$runtime, 'viteHot']));
        $twig->addFunction(new TwigFunction('vite_tags', [$runtime, 'viteTags'], ['is_safe' => ['html']]));
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
        $twig->addFunction(new TwigFunction('audit_action', [$runtime, 'auditAction']));
        $twig->addFunction(new TwigFunction('audit_action_tone', [$runtime, 'auditActionTone']));
        $twig->addFunction(new TwigFunction('app_locale', [$runtime, 'appLocale']));
        $twig->addFunction(new TwigFunction('app_env', static fn (): string => (string) config('app.env', 'local')));
        $twig->addFunction(new TwigFunction('app_version', static fn (): string => (string) config('app.version', AppVersion::DEFAULT)));
        $twig->addFunction(new TwigFunction('available_locales', [$runtime, 'availableLocales']));
        $twig->addFunction(new TwigFunction('brand', [$runtime, 'brand']));
        $twig->addFunction(new TwigFunction('brand_css', [$runtime, 'brandCss'], ['is_safe' => ['css']]));
        $twig->addFunction(new TwigFunction('brand_html_style', [$runtime, 'brandHtmlStyle'], ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('ui', static fn (): Ui => gereq()->make(Ui::class)));
        $twig->addFunction(new TwigFunction(
            'ui_render',
            static fn (string $name, array $data = []): string => gereq()->make(Ui::class)->component($name, $data),
            ['is_safe' => ['html']],
        ));
        $twig->addFilter(new TwigFilter('trans', [$runtime, 'trans']));
        $twig->addFilter(new TwigFilter('audit_action', [$runtime, 'auditAction']));
        $twig->addFilter(new TwigFilter('audit_action_tone', [$runtime, 'auditActionTone']));

        if ($this->debugBar !== null && $this->debugBar->enabled()) {
            $profile = new Profile();
            $twig->addExtension(new ProfilerExtension($profile));
            $this->debugBar->setTwigProfile($profile);
        }

        return $twig;
    }
}
