<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Config\Repository;
use Gereq\Hook\HookManager;
use Gereq\Http\Exception\AccessDeniedHttpException;
use Gereq\Http\Exception\NotFoundHttpException;
use Gereq\Http\ExceptionHandler;
use Gereq\Http\Router;
use Gereq\Security\ContentSecurityPolicy;
use Gereq\Validation\ValidationException;
use Gereq\View\AssetManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class ExceptionHandlerTest extends TestCase
{
    public function testRendersHtmlNotFoundPage(): void
    {
        $response = $this->handler(debug: false)->render(
            Request::create('/neexistuje'),
            new NotFoundHttpException('Zákazník neexistuje.'),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Zákazník neexistuje.', $response->getContent() ?: '');
        self::assertStringContainsString('404', $response->getContent() ?: '');
        self::assertStringContainsString('GEREQ', $response->getContent() ?: '');
    }

    public function testRendersJsonForApiRequests(): void
    {
        $request = Request::create('/api/v1/missing', 'GET', server: [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response = $this->handler(debug: false)->render($request, new ResourceNotFoundException());

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(404, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('not_found', $payload['error']['code']);
        self::assertArrayNotHasKey('trace', $payload['error']);
    }

    public function testJsonValidationIncludesFields(): void
    {
        $request = Request::create('/api/v1/customers', 'POST', server: [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response = $this->handler(debug: false)->render(
            $request,
            new ValidationException(['email' => 'Zadejte platný e-mail.'], ['email' => 'x'], 'customers.save'),
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('validation_failed', $payload['error']['code']);
        self::assertSame('Zadejte platný e-mail.', $payload['error']['fields']['email']);
    }

    public function testHidesInternalDetailsWhenDebugIsOff(): void
    {
        $response = $this->handler(debug: false)->render(
            Request::create('/boom'),
            new \RuntimeException('tajný detail databáze'),
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('tajný detail databáze', $response->getContent() ?: '');
        self::assertStringContainsString('Něco se pokazilo', $response->getContent() ?: '');
    }

    public function testShowsInternalDetailsWhenDebugIsOn(): void
    {
        $response = $this->handler(debug: true)->render(
            Request::create('/boom'),
            new \RuntimeException('tajný detail databáze'),
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('tajný detail databáze', $response->getContent() ?: '');
        self::assertStringContainsString('error-trace', $response->getContent() ?: '');
    }

    public function testFilterCanReplaceResponse(): void
    {
        $handler = $this->handler(debug: false, hooks: $hooks = new HookManager());
        $hooks->addFilter('http.error.response', static function ($response) {
            $response->headers->set('X-Gereq-Error', '1');

            return $response;
        });

        $response = $handler->render(Request::create('/x'), new AccessDeniedHttpException());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('X-Gereq-Error'));
    }

    public function testDoesNotLogClientErrors(): void
    {
        $basePath = sys_get_temp_dir() . '/gereq-errors-' . bin2hex(random_bytes(4));
        mkdir($basePath . '/storage/logs', 0777, true);
        $handler = $this->handler(debug: false, basePath: $basePath);
        $handler->report(new NotFoundHttpException());

        self::assertFileDoesNotExist($basePath . '/storage/logs/gereq.log');
    }

    public function testDeprecationsDoNotBecomeExceptions(): void
    {
        $handler = $this->handler(debug: true);
        $previous = error_reporting(E_ALL);

        try {
            self::assertTrue($handler->handleError(E_DEPRECATED, 'session.sid_length INI setting is deprecated'));
        } finally {
            error_reporting($previous);
        }
    }

    private function handler(bool $debug, ?HookManager $hooks = null, ?string $basePath = null): ExceptionHandler
    {
        $translator = new Translator('cs');
        $translator->addLoader('array', new ArrayLoader());
        $messages = require dirname(__DIR__, 2) . '/resources/translations/messages.cs.php';
        $translator->addResource('array', $messages, 'cs');

        $loader = new FilesystemLoader(dirname(__DIR__, 2) . '/resources/views');
        $twig = new Environment($loader, ['autoescape' => 'html']);
        $twig->addFilter(new TwigFilter('trans', static fn (string $id, array $parameters = []): string => $translator->trans($id, $parameters)));
        $twig->addFunction(new TwigFunction('trans', static fn (string $id, array $parameters = []): string => $translator->trans($id, $parameters)));
        $twig->addFunction(new TwigFunction('asset', static fn (): string => '/build/admin.css'));
        $twig->addFunction(new TwigFunction('vite_hot', static fn (): bool => false));
        $twig->addFunction(new TwigFunction('vite_tags', static fn (): string => ''));
        $twig->addFunction(new TwigFunction('csp_nonce', static fn (): string => 'test'));
        $twig->addFunction(new TwigFunction('url', static fn (): string => '/admin'));
        $twig->addFunction(new TwigFunction('app_locale', static fn (): string => 'cs'));
        $twig->addFunction(new TwigFunction('brand', static fn (?string $key = null): mixed => $key === null ? [] : 'GEREQ'));
        $twig->addFunction(new TwigFunction('brand_html_style', static fn (): string => ''));
        $twig->addFunction(new TwigFunction('brand_css', static fn (): string => ''));
        $twig->addFunction(new TwigFunction('icon', static fn (): string => ''));

        $router = new Router();
        $router->get('/admin', static fn () => 'ok')->name('admin.dashboard');

        $base = $basePath ?? sys_get_temp_dir();

        return new ExceptionHandler(
            $twig,
            $hooks ?? new HookManager(),
            new ContentSecurityPolicy(new AssetManager($base)),
            new Repository(['app' => ['debug' => $debug]]),
            $router,
            $base,
            null,
            $translator,
        );
    }
}
