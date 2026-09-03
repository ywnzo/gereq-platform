<?php

declare(strict_types=1);

namespace Gereq\View;

use Gereq\Admin\Ui\Icons;
use Gereq\Auth\Authenticator;
use Gereq\Debug\DebugBar;
use Gereq\Http\Router;
use Gereq\Security\Csrf;
use Gereq\View\AssetManager;
use Gereq\Http\FormState;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Extension\ProfilerExtension;
use Twig\Loader\FilesystemLoader;
use Twig\Profiler\Profile;
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

        $twig->addFunction(new TwigFunction('url', function (string $name, array $params = []): string {
            $request = $this->requestStack->getCurrentRequest();

            return $this->router->url($name, $params, $request);
        }));

        $twig->addFunction(new TwigFunction('csrf_field', fn (): string => $this->csrf->field(), ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('csrf_token', fn (): string => $this->csrf->token()));
        $twig->addFunction(new TwigFunction('asset', fn (string $entry): string => $this->assets->url($entry)));
        $twig->addFunction(new TwigFunction('icon', fn (string $name, string $class = ''): string => $this->icons->svg($name, $class), ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('current_user', fn () => $this->auth->user()));
        $twig->addFunction(new TwigFunction('route_is', function (string $name): bool {
            $request = $this->requestStack->getCurrentRequest();
            if ($request === null) {
                return false;
            }

            return $request->attributes->get('_route') === $name
                || str_starts_with((string) $request->attributes->get('_route'), $name);
        }));
        $twig->addFunction(new TwigFunction('flash', function (string $key): mixed {
            $request = $this->requestStack->getCurrentRequest();
            if ($request === null || !$request->hasSession()) {
                return null;
            }

            $session = $request->getSession();
            if (!$session instanceof \Symfony\Component\HttpFoundation\Session\Session) {
                return null;
            }

            return $session->getFlashBag()->get($key);
        }));
        $twig->addFunction(new TwigFunction('csp_nonce', function (): string {
            return (string) $this->requestStack->getCurrentRequest()?->attributes->get('csp_nonce', '');
        }));
        $twig->addFunction(new TwigFunction('old', function (string $key, mixed $default = null, ?string $form = null): mixed {
            return $this->formState->old($key, $default, $form);
        }));
        $twig->addFunction(new TwigFunction('error', function (string $key, ?string $form = null): ?string {
            return $this->formState->error($key, $form);
        }));
        $twig->addFunction(new TwigFunction('errors', function (?string $form = null): array {
            return $this->formState->errors($form);
        }));
        $twig->addFunction(new TwigFunction('has_error', function (string $key, ?string $form = null): bool {
            return $this->formState->error($key, $form) !== null;
        }));
        $twig->addFunction(new TwigFunction('form_errors', function (?string $form = null): bool {
            return $this->formState->hasErrors($form);
        }));

        if ($this->debugBar !== null && $this->debugBar->enabled()) {
            $profile = new Profile();
            $twig->addExtension(new ProfilerExtension($profile));
            $this->debugBar->setTwigProfile($profile);
        }

        return $twig;
    }
}
