<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Config\Repository;
use Gereq\Debug\DebugBar;
use Gereq\Debug\Dumper;
use Gereq\Debug\NPlusOneDetector;
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
        self::assertFalse($queries->all()[0]['nPlusOne']);
    }

    public function testNPlusOneDetectionGroupsSimilarQueries(): void
    {
        $queries = new QueryCollector(2);
        $queries->add('SELECT * FROM products WHERE id = ?', [1], 0.5, 'Product.php:10');
        $queries->add('SELECT * FROM products WHERE id = ?', [2], 0.4, 'Product.php:10');
        $queries->add('SELECT * FROM products WHERE id = ?', [3], 0.6, 'Product.php:12');
        $queries->add('SELECT COUNT(*) FROM orders', [], 1.0, 'Order.php:1');

        $hits = $queries->nPlusOnes();
        self::assertCount(1, $hits);
        self::assertSame(3, $hits[0]['count']);
        self::assertSame(1, $queries->nPlusOneCount());
        self::assertTrue($queries->all()[0]['nPlusOne']);
        self::assertSame(3, $queries->all()[0]['nPlusOneCount']);
        self::assertFalse($queries->all()[3]['nPlusOne']);
    }

    public function testNPlusOneFingerprintNormalizesLiterals(): void
    {
        self::assertSame(
            NPlusOneDetector::fingerprint("SELECT * FROM t WHERE id = 1 AND name = 'a'"),
            NPlusOneDetector::fingerprint('SELECT * FROM t WHERE id = 99 AND name = "b"'),
        );

        $detector = new NPlusOneDetector(2);
        $hits = $detector->detect([
            ['sql' => 'SELECT * FROM users WHERE id = 1', 'params' => [], 'duration' => 1.0, 'origin' => 'A.php:1'],
            ['sql' => 'SELECT * FROM users WHERE id = 2', 'params' => [], 'duration' => 1.0, 'origin' => 'A.php:1'],
        ]);
        self::assertCount(1, $hits);
        self::assertSame(2, $hits[0]['count']);
    }

    public function testPayloadIncludesNPlusOnesAndHeadersOnInject(): void
    {
        $bar = $this->bar();
        $bar->queries()->add('SELECT * FROM x WHERE id = ?', [1], 0.2, 'X.php:1');
        $bar->queries()->add('SELECT * FROM x WHERE id = ?', [2], 0.3, 'X.php:1');

        $request = Request::create('/admin');
        $payload = $bar->payload($request, new Response('ok'));
        self::assertSame(1, $payload['nPlusOneCount']);
        self::assertNotEmpty($payload['nPlusOnes']);

        $response = new Response('<html><body>ok</body></html>', 200, ['Content-Type' => 'text/html']);
        $out = $bar->inject($request, $response);
        self::assertSame('1', $out->headers->get('X-Debug-N-Plus-One'));
        self::assertSame('2', $out->headers->get('X-Debug-N-Plus-One-Queries'));
        self::assertStringContainsString('nPlusOnes', (string) $out->getContent());
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

    public function testPayloadIncludesProjectRootAndResolvedTwigFiles(): void
    {
        $bar = $this->bar();
        $profile = new \Twig\Profiler\Profile('main', \Twig\Profiler\Profile::ROOT);
        $tpl = new \Twig\Profiler\Profile(
            '@crm/deals/index.html.twig',
            \Twig\Profiler\Profile::TEMPLATE,
            '@crm/deals/index.html.twig',
        );
        $profile->addProfile($tpl);
        $bar->setTwigProfile($profile);

        $payload = $bar->payload(Request::create('/admin'), new Response('ok'));

        self::assertSame(dirname(__DIR__, 2), $payload['projectRoot']);
        self::assertSame(dirname(__DIR__, 2), $payload['serverRoot']);
        self::assertNotEmpty($payload['views']);
        self::assertSame('@crm/deals/index.html.twig', $payload['views'][0]['name']);
        self::assertSame(
            'modules/CRM/Resources/views/deals/index.html.twig',
            $payload['views'][0]['file'],
        );
    }

    public function testDebugEditorRootOverrideIsPreferred(): void
    {
        $bar = $this->bar(configExtra: [
            'debug_editor_root' => 'C:\\Users\\dev\\RealEstates',
        ]);
        $payload = $bar->payload(Request::create('/'), new Response('ok'));

        self::assertSame('C:\\Users\\dev\\RealEstates', $payload['projectRoot']);
        self::assertSame(dirname(__DIR__, 2), $payload['serverRoot']);
    }

    public function testResolvesCoreTwigTemplatePath(): void
    {
        $bar = $this->bar();
        $method = new \ReflectionMethod(DebugBar::class, 'resolveTwigRelativePath');

        self::assertSame(
            'resources/views/admin/layout.html.twig',
            $method->invoke($bar, 'admin/layout.html.twig'),
        );
        self::assertNull($method->invoke($bar, '@missing/nope.html.twig'));
    }

    public function testDumperEscapesHtml(): void
    {
        $html = (new Dumper(new Redactor()))->dump('<script>alert(1)</script>');
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>alert', $html);
    }

    /**
     * @param array<string, mixed> $configExtra
     */
    private function bar(bool $debug = true, ?Router $router = null, array $configExtra = []): DebugBar
    {
        $base = dirname(__DIR__, 2);
        $redactor = new Redactor();

        return new DebugBar(
            new Repository([
                'app' => array_merge([
                    'debug' => $debug,
                    'env' => 'testing',
                    'name' => 'GEREQ',
                    'n_plus_one_log' => false,
                    'n_plus_one_threshold' => 2,
                    'debug_editor_root' => '',
                ], $configExtra),
            ]),
            new QueryCollector(),
            new Dumper($redactor),
            $redactor,
            new Renderer($base),
            $router ?? new Router(),
            new EventBus(new \Gereq\Hook\HookManager()),
            $base,
            null,
        );
    }
}
