<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Gereq\Config\Repository;
use Gereq\Http\Router;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\StagingStore;
use Gereq\Publish\StagingWriter;
use Gereq\Security\RateLimiter;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Modules\Analytics\Application\AnalyticsConfig;
use Modules\Analytics\Application\Repositories\SiteRepository;
use Modules\Analytics\Application\TrackerConfig;
use Modules\Analytics\Http\Public\PublicAnalyticsController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Public tracker bootstrap `GET /api/public/analytics/config` (README "Public surface").
 *
 * The payload depends on the resolved site, which depends on the request host, so the response has
 * to vary on `Host`. A `?site=` tracker key is public, so the endpoint must never confirm a key for
 * a foreign host — it answers `{"enabled":false}` just like an unknown host.
 *
 * `config()` only touches the `sites` and `tracker` collaborators, so the heavy `ingest` /
 * `consentService` dependencies are left uninitialised. The rate-limit tests inject a real
 * `RateLimiter` backed by a throwaway temp store that is removed again in `tearDown()` — there are
 * no repository or container side effects.
 */
final class PublicAnalyticsControllerTest extends TestCase
{
    /** @var list<string> Temp limiter stores created by the rate-limit tests. */
    private array $limiterDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->limiterDirectories as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
        $this->limiterDirectories = [];

        parent::tearDown();
    }

    public function testConfigVariesOnHostAndIsPubliclyCacheable(): void
    {
        $controller = $this->controller([$this->site('s1', ['example.com'])]);

        $response = $controller->config(
            Request::create('https://example.com/api/public/analytics/config?site=grq_an_s1'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Host', $response->headers->get('Vary'));
        self::assertNotNull($response->getEtag());
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame(TrackerConfig::CACHE_TTL, $response->getMaxAge());

        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['enabled']);
        self::assertSame('grq_an_s1', $payload['site']);
    }

    public function testForeignHostNeverConfirmsASiteKey(): void
    {
        $controller = $this->controller([$this->site('s1', ['example.com'])]);

        $response = $controller->config(
            Request::create('https://evil.test/api/public/analytics/config?site=grq_an_s1'),
        );

        self::assertSame('{"enabled":false}', (string) $response->getContent());
        self::assertSame('Host', $response->headers->get('Vary'));
    }

    public function testHostResolutionWithoutAKeyUsesTheRequestHost(): void
    {
        $controller = $this->controller([$this->site('s1', ['example.com'])]);

        $own = $controller->config(Request::create('https://example.com/api/public/analytics/config'));
        self::assertStringContainsString('"enabled":true', (string) $own->getContent());

        $foreign = $controller->config(Request::create('https://evil.test/api/public/analytics/config'));
        self::assertSame('{"enabled":false}', (string) $foreign->getContent());
    }

    public function testRateLimiterUsesOneIpBucketWithoutLoadingTheSite(): void
    {
        $directory = $this->limiterDirectory();
        self::assertTrue($this->invokeRateLimited('grq_an_known', $directory));
        self::assertFileExists($directory . '/' . hash('sha256', 'analytics:1.2.3.4') . '.json');
        self::assertFileDoesNotExist($directory . '/' . hash('sha256', 'analytics:1.2.3.4:grq_an_known') . '.json');
    }

    public function testChangingRawSiteKeysCannotBypassTheIpLimit(): void
    {
        $directory = $this->limiterDirectory();
        for ($index = 0; $index < PublicAnalyticsController::RATE_LIMIT_PER_MINUTE; ++$index) {
            self::assertTrue($this->invokeRateLimited('grq_an_unknown_' . $index, $directory));
        }
        self::assertFalse($this->invokeRateLimited('grq_an_another_unknown', $directory));
    }

    private function invokeRateLimited(string $siteKey, string $directory, string $ip = '1.2.3.4'): bool
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('createQueryBuilder');
        $reflect = new \ReflectionClass(PublicAnalyticsController::class);
        /** @var PublicAnalyticsController $controller */
        $controller = $reflect->newInstanceWithoutConstructor();
        $reflect->getProperty('sites')->setValue($controller, new SiteRepository($em));
        $reflect->getProperty('limiter')->setValue($controller, new RateLimiter($directory));
        $request = Request::create('https://example.com/api/public/analytics/collect', 'POST', [], [], [], ['REMOTE_ADDR' => $ip], json_encode(['site' => $siteKey], JSON_THROW_ON_ERROR));
        $result = $reflect->getMethod('rateLimited')->invoke($controller, $request);
        self::assertIsBool($result);

        return $result;
    }

    private function limiterDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/gereq-analytics-rl-' . bin2hex(random_bytes(6));
        $this->limiterDirectories[] = $directory;

        return $directory;
    }

    /**
     * @param list<array<string, mixed>> $sites
     */
    private function controller(array $sites): PublicAnalyticsController
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($this->settingsRepository());
        $em->method('createQueryBuilder')->willReturn($this->siteBuilder($sites));

        $config = $this->config($em);
        $reflect = new \ReflectionClass(PublicAnalyticsController::class);
        /** @var PublicAnalyticsController $controller */
        $controller = $reflect->newInstanceWithoutConstructor();
        $reflect->getProperty('sites')->setValue($controller, new SiteRepository($em));
        $reflect->getProperty('tracker')->setValue($controller, $this->trackerConfig($config));

        return $controller;
    }

    /**
     * @param list<array<string, mixed>> $sites
     */
    private function siteBuilder(array $sites): QueryBuilder
    {
        $query = $this->createMock(Query::class);
        $query->method('getArrayResult')->willReturn($sites);
        $query->method('getOneOrNullResult')->willReturn($sites[0] ?? null);

        $builder = $this->createMock(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'andWhere', 'setParameter', 'setMaxResults', 'orderBy'] as $fluent) {
            $builder->method($fluent)->willReturnSelf();
        }
        $builder->method('getQuery')->willReturn($query);

        return $builder;
    }

    private function trackerConfig(AnalyticsConfig $config): TrackerConfig
    {
        $router = new Router();
        $handler = static fn (): null => null;
        $router->post('/api/public/analytics/collect', $handler)->name('api.public.analytics.collect');
        $router->post('/api/public/analytics/consent', $handler)->name('api.public.analytics.consent');
        $router->get('/api/public/analytics/config', $handler)->name('api.public.analytics.config');
        $router->get('/api/public/analytics/tracker.js', $handler)->name('api.public.analytics.tracker');

        return new TrackerConfig($config, $router);
    }

    private function config(EntityManagerInterface $em): AnalyticsConfig
    {
        $publishables = new PublishableRegistry();

        return new AnalyticsConfig(new Settings(
            $em,
            new Repository([]),
            new ContentReadContext(),
            $publishables,
            new StagingWriter(new StagingStore($em), $publishables),
        ));
    }

    /** @return EntityRepository<SettingRecord> */
    private function settingsRepository(): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findAll')->willReturn([]);

        return $repository;
    }

    /**
     * @param list<string> $domains
     * @return array<string, mixed>
     */
    private function site(string $id, array $domains): array
    {
        return [
            'id' => $id,
            'name' => 'Site ' . $id,
            'key' => 'grq_an_' . $id,
            'domains' => $domains,
            'enabled' => true,
            'timezone' => 'Europe/Prague',
            'secret' => str_repeat('a', 64),
            'retention_override_days' => null,
            'keep_bot_hits' => false,
            'respect_dnt' => true,
            'excluded_paths' => [],
            'excluded_ips' => [],
        ];
    }
}
