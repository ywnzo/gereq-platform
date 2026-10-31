<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Gereq\Config\Repository;
use Gereq\Event\EventBus;
use Gereq\Hook\HookManager;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\StagingStore;
use Gereq\Publish\StagingWriter;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Modules\Analytics\Application\AnalyticsConfig;
use Modules\Analytics\Application\ConsentGate;
use Modules\Analytics\Application\ConsentService;
use Modules\Analytics\Application\Repositories\SiteRepository;
use Modules\Analytics\Application\Repositories\TrafficRepository;
use Modules\Analytics\Application\VisitorPseudonymizer;
use PHPUnit\Framework\TestCase;

/**
 * Consent endpoint integrity (README "Consent contract", layer 3).
 *
 * `granted` / `denied` are recorded only when the `grq_consent` assertion cookie backs the
 * decision up; `revoked` stays cookie independent. The stored category map is bounded so a
 * crafted beacon body cannot bloat `analytics_consent_events.categories`.
 *
 * The repositories are final, so the service runs against a real `SiteRepository` /
 * `TrafficRepository` on a mocked DBAL boundary; the test reads the category map straight off the
 * captured `INSERT` instead of the database.
 */
final class ConsentServiceTest extends TestCase
{
    private const CONSENT_COOKIE = '{"v":1,"statistics":true}';

    public function testCategoryMapIsCappedAndSanitised(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        /** @var list<array{sql: string, params: array<int, mixed>}> $statements */
        $statements = [];
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = [], array $types = []) use (&$statements): int {
                $statements[] = ['sql' => $sql, 'params' => $params];

                return 1;
            },
        );

        $valid = [];
        for ($i = 1; $i <= 13; $i++) {
            $valid['k' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)] = $i % 2 === 0;
        }

        $payload = [
            'site' => 'grq_an_test',
            'decision' => 'granted',
            'visitor' => 'visitor1234',
            'categories' => array_merge([
                'v' => true,
                'marker_int' => 1,
                'marker_string' => 'true',
                'marker_null' => null,
                str_repeat('x', 33) => true,
                str_repeat('b', 32) => true,
            ], $valid),
        ];

        $result = $this->service($connection, $this->site())->record($payload, self::CONSENT_COOKIE);
        self::assertTrue($result['recorded'], 'a cookie-backed grant is recorded');

        // 12 entries max: the 32-char key is the boundary that is kept, `k12` / `k13` are dropped
        // by the cap, `v` is a version marker, the non-boolean values and the 33-char key drop out.
        $expected = [str_repeat('b', 32) => true];
        for ($i = 1; $i <= 11; $i++) {
            $expected['k' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)] = $i % 2 === 0;
        }
        self::assertCount(12, $expected);
        self::assertSame($expected, $this->storedCategories($statements));
    }

    public function testCookieAssertionIsTheFallbackCategoryMap(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        /** @var list<array{sql: string, params: array<int, mixed>}> $statements */
        $statements = [];
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = [], array $types = []) use (&$statements): int {
                $statements[] = ['sql' => $sql, 'params' => $params];

                return 1;
            },
        );

        $result = $this->service($connection, $this->site())->record([
            'site' => 'grq_an_test',
            'decision' => 'granted',
            'visitor' => 'visitor1234',
        ], self::CONSENT_COOKIE);

        self::assertTrue($result['recorded']);
        // `v` is a non-boolean marker, so the cookie map reduces to the granted category.
        self::assertSame(['statistics' => true], $this->storedCategories($statements));
    }

    public function testGrantedAndDeniedRequireTheAssertionCookie(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->expects(self::never())->method('executeStatement');
        $service = $this->service($connection, $this->site());

        // Payload only — no cookie state to back it up: nothing is recorded.
        self::assertFalse($service->record(['site' => 'grq_an_test', 'decision' => 'granted'])['recorded']);
        self::assertFalse($service->record(['site' => 'grq_an_test', 'decision' => 'denied'])['recorded']);
        // The cookie grants, but the payload assertion contradicts it.
        self::assertFalse($service->record([
            'site' => 'grq_an_test',
            'decision' => 'granted',
            'consent' => ['v' => 1, 'statistics' => false],
        ], self::CONSENT_COOKIE)['recorded']);
    }

    public function testRevokedIsRecordedWithoutTheCookieAndUsesTheConfiguredCategoryFallback(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        /** @var list<array{sql: string, params: array<int, mixed>}> $statements */
        $statements = [];
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = [], array $types = []) use (&$statements): int {
                $statements[] = ['sql' => $sql, 'params' => $params];

                return 1;
            },
        );

        $result = $this->service($connection, $this->site())->record([
            'site' => 'grq_an_test',
            'decision' => 'revoked',
        ]);

        self::assertTrue($result['recorded'], 'revocation must not require the cookie');
        self::assertSame(ConsentGate::DECISION_REVOKED, $result['decision']);
        // No payload map and no cookie: the configured category is stored with `false`.
        self::assertSame(['statistics' => false], $this->storedCategories($statements));
    }

    /**
     * @param list<array{sql: string, params: array<int, mixed>}> $statements
     * @return array<string, mixed>
     */
    private function storedCategories(array $statements): array
    {
        foreach ($statements as $statement) {
            if (!str_contains($statement['sql'], 'analytics_consent_events')) {
                continue;
            }
            $decoded = json_decode((string) $statement['params'][4], true);
            self::assertIsArray($decoded);

            $out = [];
            foreach ($decoded as $key => $value) {
                $out[(string) $key] = $value;
            }

            return $out;
        }

        self::fail('no consent event was inserted');
    }

    /**
     * @param array<string, mixed>|null $site
     */
    private function service(Connection $connection, ?array $site): ConsentService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($this->settingsRepository());
        $em->method('getConnection')->willReturn($connection);
        $em->method('createQueryBuilder')->willReturn($this->siteBuilder($site));

        $publishables = new PublishableRegistry();
        $config = new AnalyticsConfig(new Settings(
            $em,
            new Repository([]),
            new ContentReadContext(),
            $publishables,
            new StagingWriter(new StagingStore($em), $publishables),
        ));

        return new ConsentService(
            $config,
            new ConsentGate(),
            new VisitorPseudonymizer(),
            new SiteRepository($em),
            new TrafficRepository($em),
            new EventBus(new HookManager()),
        );
    }

    /** @return EntityRepository<SettingRecord> */
    private function settingsRepository(): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findAll')->willReturn([]);

        return $repository;
    }

    /**
     * @param array<string, mixed>|null $site
     */
    private function siteBuilder(?array $site): QueryBuilder
    {
        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($site);
        $query->method('getSingleColumnResult')->willReturn([]);

        $builder = $this->createMock(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'andWhere', 'setParameter', 'setMaxResults', 'orderBy', 'addOrderBy', 'groupBy'] as $fluent) {
            $builder->method($fluent)->willReturnSelf();
        }
        $builder->method('getQuery')->willReturn($query);

        return $builder;
    }

    /** @return array<string, mixed> */
    private function site(): array
    {
        return [
            'id' => 'site-1',
            'name' => 'Example',
            'key' => 'grq_an_test',
            'domains' => ['example.com'],
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
