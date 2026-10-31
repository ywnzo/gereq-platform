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
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Modules\Analytics\Application\AnalyticsConfig;
use Modules\Analytics\Application\Repositories\SiteRepository;
use Modules\Analytics\Application\TrackerConfig;
use Modules\Analytics\Application\TrackerInjector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Storefront tracker injection (README "Injection").
 *
 * The injector must fail open: it runs for every storefront shell, so an unavailable analytics
 * schema must never turn a page into a 500. The host lookup is memoised per request, including
 * negative results, so one request performs at most one lookup per host.
 */
final class TrackerInjectorTest extends TestCase
{
    private const SHELL = '<html><body>hello</body></html>';

    public function testInjectsSnippetForTheResolvedHost(): void
    {
        $injector = $this->injector([$this->site('s1', ['example.com'])]);

        $out = $injector->inject(self::SHELL, Request::create('https://example.com/page'));

        self::assertStringContainsString('data-analytics="gereq"', $out);
        self::assertStringContainsString('data-site="grq_an_s1"', $out);
        $snippetPosition = strpos($out, 'data-analytics="gereq"');
        $bodyPosition = strpos($out, '</body>');
        self::assertIsInt($snippetPosition);
        self::assertIsInt($bodyPosition);
        self::assertLessThan($bodyPosition, $snippetPosition, 'the snippet goes right before </body>');
    }

    public function testUnknownHostReturnsHtmlByteForByte(): void
    {
        $injector = $this->injector([$this->site('s1', ['example.com'])]);

        self::assertSame(
            self::SHELL,
            $injector->inject(self::SHELL, Request::create('https://other.test/page')),
        );
    }

    public function testHtmlThatAlreadyCarriesTheMarkerIsLeftUntouchedWithoutALookup(): void
    {
        $lookups = 0;
        $injector = $this->injector(
            [$this->site('s1', ['example.com'])],
            null,
            function () use (&$lookups): void {
                $lookups++;
            },
        );

        $html = '<html><body><script data-analytics="gereq"></script></body></html>';
        self::assertSame($html, $injector->inject($html, Request::create('https://example.com/page')));
        self::assertSame(0, $lookups, 'an already-injected shell must not trigger a lookup');
    }

    public function testFailsOpenWhenTheSiteLookupThrows(): void
    {
        $injector = $this->injector([], new \RuntimeException('schema missing'));

        self::assertSame(
            self::SHELL,
            $injector->inject(self::SHELL, Request::create('https://example.com/page')),
            'a broken lookup must never throw out of the filter',
        );
    }

    public function testTheSameRequestResolvesAHostOnlyOnce(): void
    {
        $lookups = 0;
        $injector = $this->injector([$this->site('s1', ['example.com'])], null, $this->counter($lookups));

        $request = Request::create('https://example.com/page');
        $injector->inject(self::SHELL, $request);
        $injector->inject(self::SHELL, $request);

        self::assertSame(1, $lookups);
    }

    public function testANewRequestReResolvesTheHost(): void
    {
        $lookups = 0;
        $injector = $this->injector([$this->site('s1', ['example.com'])], null, $this->counter($lookups));

        $injector->inject(self::SHELL, Request::create('https://example.com/page'));
        $injector->inject(self::SHELL, Request::create('https://example.com/other'));

        self::assertSame(2, $lookups, 'a long-running worker must not serve a stale site row');
    }

    public function testANegativeResultIsCachedToo(): void
    {
        $lookups = 0;
        $injector = $this->injector([], null, $this->counter($lookups));

        $request = Request::create('https://other.test/page');
        $injector->inject(self::SHELL, $request);
        $injector->inject(self::SHELL, $request);

        self::assertSame(1, $lookups);
    }

    private function counter(int &$count): \Closure
    {
        return function () use (&$count): void {
            $count++;
        };
    }

    /**
     * @param list<array<string, mixed>> $sites
     */
    private function injector(array $sites, ?\RuntimeException $throw = null, ?callable $onLookup = null): TrackerInjector
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($this->settingsRepository());
        $em->method('createQueryBuilder')->willReturn($this->siteBuilder($sites, $throw, $onLookup));

        $config = $this->config($em);

        return new TrackerInjector($config, new SiteRepository($em), $this->trackerConfig($config));
    }

    /**
     * @param list<array<string, mixed>> $sites
     */
    private function siteBuilder(array $sites, ?\RuntimeException $throw, ?callable $onLookup): QueryBuilder
    {
        $query = $this->createMock(Query::class);
        $query->method('getArrayResult')->willReturnCallback(function () use ($sites, $throw, $onLookup): array {
            if ($onLookup !== null) {
                $onLookup();
            }
            if ($throw !== null) {
                throw $throw;
            }

            return $sites;
        });

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
