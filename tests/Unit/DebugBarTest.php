<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Config\Repository;
use Gereq\Debug\DebugBar;
use Gereq\Debug\Dumper;
use Gereq\Debug\QueryCollector;
use Gereq\Debug\Redactor;
use Gereq\Debug\Renderer;
use Gereq\Event\EventBus;
use Gereq\Http\Router;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DebugBarTest extends TestCase
{
    public function testRedactorMasksSecrets(): void
    {
        $redactor = new Redactor();
        $out = $redactor->value([
            'email' => 'jiri.novak@gereq.cz',
            'password' => 'secret',
            'csrf_token' => 'abc',
            'mail.dsn' => 'smtp://user:pass@localhost',
            'nested' => ['authorization' => 'Bearer x'],
        ]);

        self::assertSame('jiri.novak@gereq.cz', $out['email']);
        self::assertSame('••••', $out['password']);
        self::assertSame('••••', $out['csrf_token']);
        self::assertSame('••••', $out['mail.dsn']);
        self::assertSame('••••', $out['nested']['authorization']);
    }

    public function testQueryCollectorTotals(): void
    {
        $queries = new QueryCollector();
        $queries->add('SELECT 1', [], 1.25);
        $queries->add('SELECT 2', [1], 2.5, 'Foo.php:10');

        self::assertSame(2, $queries->count());
        self::assertSame(3.75, $queries->totalMs());
        self::assertSame('Foo.php:10', $queries->all()[1]['origin']);
    }

    public function testInjectsIntoHtmlResponse(): void
    {
        $bar = $this->bar();
        $bar->queries()->add('SELECT * FROM users', [], 1.1, 'User.php:3');
        $bar->addDump(['password' => 'tajne'], 'app.php', 12);
        $bar->mark('controller');

        $request = Request::create('/admin');
        $request->attributes->set('csp_nonce', 'test-nonce');
        $response = new Response('<html><body>ahoj</body></html>', 200, ['Content-Type' => 'text/html']);

        $out = $bar->inject($request, $response);
        $html = (string) $out->getContent();

        self::assertStringContainsString('id="gereq-debug"', $html);
        self::assertStringContainsString('ahoj', $html);
        self::assertStringContainsString('nonce="test-nonce"', $html);
        self::assertStringContainsString('SELECT * FROM users', $html);
        self::assertStringContainsString('••••', $html);
        self::assertStringContainsString('</body>', $html);

        $again = $bar->inject($request, $out);
        self::assertSame(1, substr_count((string) $again->getContent(), 'id="gereq-debug"'));
    }

    public function testDoesNotInjectJsonOrWhenDebugOff(): void
    {
        $json = $this->bar()->inject(
            Request::create('/api'),
            new JsonResponse(['ok' => true]),
        );
        self::assertStringNotContainsString('gereq-debug', (string) $json->getContent());

        $html = $this->bar(debug: false)->inject(
            Request::create('/admin'),
            new Response('<html><body>x</body></html>'),
        );
        self::assertStringNotContainsString('gereq-debug', (string) $html->getContent());
    }

    public function testPayloadIncludesRouteAndTimeline(): void
    {
        $router = new Router();
        $router->get('/admin', static fn () => 'ok')->name('admin.dashboard')->middleware('auth');
        $bar = $this->bar(router: $router);

        $request = Request::create('/admin');
        $request->attributes->set('_route', 'admin.dashboard');
        $request->attributes->set('_route_params', []);
        $payload = $bar->payload($request, new Response('ok', 200));

        self::assertSame('admin.dashboard', $payload['route']['name']);
        self::assertSame(['auth'], $payload['route']['middleware']);
        self::assertNotEmpty($payload['timeline']);
        self::assertTrue($payload['user']['guest']);
    }

    public function testDumperEscapesHtml(): void
    {
        $html = (new Dumper(new Redactor()))->dump('<script>alert(1)</script>');
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>alert', $html);
    }

    private function bar(bool $debug = true, ?Router $router = null): DebugBar
    {
        $base = dirname(__DIR__, 2);
        $redactor = new Redactor();

        return new DebugBar(
            new Repository(['app' => ['debug' => $debug, 'env' => 'testing', 'name' => 'GEREQ']]),
            new QueryCollector(),
            new Dumper($redactor),
            $redactor,
            new Renderer($base),
            $router ?? new Router(),
            new EventBus(),
            $base,
        );
    }
}
