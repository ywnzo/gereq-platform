<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Gereq\Config\Repository;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\StagingStore;
use Gereq\Publish\StagingWriter;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Modules\Analytics\Application\AnalyticsConfig;
use Modules\Analytics\Application\PurgeService;
use Modules\Analytics\Application\Repositories\PurgeRepository;
use PHPUnit\Framework\TestCase;

/**
 * Retention purge (README "Retention and rollups").
 *
 * `PurgeRepository` is final and DB-bound, so the service is exercised against a narrow double of
 * its only collaborator (a mocked DBAL connection behind a real `PurgeRepository`): the tests pin
 * the cutoff computation, the dry-run aggregation (nothing deleted) and the batch-size chunking,
 * not the SQL execution semantics of a real database. The consent target follows the **visitor**
 * window and is counted in `candidates` / `deleted`; a real in-memory SQLite run pins that the
 * in-window consent row survives while the raw window would already have removed it.
 */
final class PurgeServiceTest extends TestCase
{
    public function testDryRunReportsCandidatesWithoutDeleting(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());

        /** @var list<array{query: string, cutoff: string}> $calls */
        $calls = [];
        $connection->expects(self::exactly(7))->method('fetchOne')
            ->willReturnCallback(function (string $query, array $params = [], array $types = []) use (&$calls): int {
                $calls[] = ['query' => $query, 'cutoff' => (string) ($params[0] ?? '')];

                return 3;
            });
        $connection->expects(self::never())->method('executeStatement');
        $connection->expects(self::never())->method('fetchFirstColumn');

        $result = $this->service($connection)->purge(null, true);

        self::assertSame([
            'pageviews' => 3,
            'events' => 3,
            'sessions' => 3,
            'visitors' => 3,
            'consent' => 3,
            'rollups' => 6,
            'candidates' => 21,
            'deleted' => 0,
        ], $result);

        self::assertStringContainsString('"analytics_pageviews"', $calls[0]['query']);
        self::assertStringContainsString('"analytics_events"', $calls[1]['query']);
        self::assertStringContainsString('"analytics_sessions"', $calls[2]['query']);
        self::assertStringContainsString('"analytics_visitors"', $calls[3]['query']);
        self::assertStringContainsString('"analytics_consent_events"', $calls[4]['query']);
        self::assertStringContainsString('"analytics_daily_stats"', $calls[5]['query']);
        self::assertStringContainsString('"analytics_daily_dimensions"', $calls[6]['query']);

        $today = new \DateTimeImmutable('today');
        $rawCutoff = $today->modify('-90 days')->format('Y-m-d H:i:s');
        $visitorCutoff = $today->modify('-395 days')->format('Y-m-d H:i:s');
        $rollupCutoff = $today->modify('-1095 days')->format('Y-m-d');

        self::assertSame($rawCutoff, $calls[0]['cutoff']);
        self::assertSame($rawCutoff, $calls[1]['cutoff']);
        self::assertSame($rawCutoff, $calls[2]['cutoff']);
        // Consent rows are personal data, so they follow the visitor window, not the raw one.
        self::assertSame($visitorCutoff, $calls[3]['cutoff']);
        self::assertSame($visitorCutoff, $calls[4]['cutoff']);
        self::assertSame($rollupCutoff, $calls[5]['cutoff']);
        self::assertSame($rollupCutoff, $calls[6]['cutoff']);
    }

    public function testCustomDayOverrideChangesOnlyTheRawCutoff(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());

        /** @var list<string> $cutoffs */
        $cutoffs = [];
        $connection->expects(self::exactly(7))->method('fetchOne')
            ->willReturnCallback(function (string $query, array $params = [], array $types = []) use (&$cutoffs): int {
                $cutoffs[] = (string) ($params[0] ?? '');

                return 0;
            });

        $this->service($connection)->purge(7, true);

        $today = new \DateTimeImmutable('today');
        self::assertSame($today->modify('-7 days')->format('Y-m-d H:i:s'), $cutoffs[0]);
        self::assertSame($today->modify('-7 days')->format('Y-m-d H:i:s'), $cutoffs[1]);
        self::assertSame($today->modify('-7 days')->format('Y-m-d H:i:s'), $cutoffs[2]);
        self::assertSame($today->modify('-395 days')->format('Y-m-d H:i:s'), $cutoffs[3]);
        self::assertSame($today->modify('-395 days')->format('Y-m-d H:i:s'), $cutoffs[4]);
        self::assertSame($today->modify('-1095 days')->format('Y-m-d'), $cutoffs[5]);
        self::assertSame($today->modify('-1095 days')->format('Y-m-d'), $cutoffs[6]);
    }

    public function testZeroOrNegativeDaysFallBackToTheGlobalRetention(): void
    {
        $today = new \DateTimeImmutable('today');
        $expected = $today->modify('-90 days')->format('Y-m-d H:i:s');

        foreach ([0, -10] as $days) {
            $connection = $this->createMock(Connection::class);
            $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
            $cutoffs = [];
            $connection->expects(self::exactly(7))->method('fetchOne')
                ->willReturnCallback(function (string $query, array $params = [], array $types = []) use (&$cutoffs): int {
                    $cutoffs[] = (string) ($params[0] ?? '');

                    return 0;
                });

            $this->service($connection)->purge($days, true);

            self::assertSame($expected, $cutoffs[0], 'days=' . $days);
        }
    }

    public function testRealPurgeChunksByPurgeBatchSize(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());

        $served = false;
        /** @var list<string> $firstColumnQueries */
        $firstColumnQueries = [];
        $connection->method('fetchFirstColumn')
            ->willReturnCallback(function (string $query, array $params = [], array $types = []) use (&$served, &$firstColumnQueries): array {
                $firstColumnQueries[] = $query;
                if (str_contains($query, 'analytics_pageviews') && !$served) {
                    $served = true;

                    return array_map(static fn (int $i): string => 'pv' . $i, range(1, 100));
                }

                return [];
            });

        /** @var list<array{sql: string, params: array<int, mixed>}> $statements */
        $statements = [];
        $connection->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = [], array $types = []) use (&$statements): int {
                $statements[] = ['sql' => $sql, 'params' => $params];

                return count($params);
            });

        $result = $this->service($connection, [
            new SettingRecord('analytics.purge_batch_size', '100', SettingRecord::TYPE_INTEGER),
        ])->purge();

        self::assertSame(100, $result['pageviews']);
        self::assertSame(0, $result['events']);
        self::assertSame(0, $result['sessions']);
        self::assertSame(100, $result['candidates']);
        self::assertSame(100, $result['deleted']);

        self::assertCount(1, $statements);
        self::assertStringContainsString('DELETE FROM "analytics_pageviews"', $statements[0]['sql']);
        self::assertStringContainsString('"id" IN (', $statements[0]['sql']);
        self::assertCount(100, $statements[0]['params']);

        $pageviewQueries = array_values(array_filter(
            $firstColumnQueries,
            static fn (string $query): bool => str_contains($query, 'analytics_pageviews'),
        ));
        self::assertCount(2, $pageviewQueries, 'the pageviews target is scanned again until it drains');
        self::assertStringContainsString('LIMIT 100', $pageviewQueries[0]);
    }

    public function testPreviewIsADryRunOfPurge(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('fetchOne')->willReturn(5);
        $connection->expects(self::never())->method('executeStatement');

        $result = $this->service($connection)->preview(30);

        self::assertSame(5, $result['pageviews']);
        self::assertSame(5, $result['events']);
        self::assertSame(5, $result['sessions']);
        self::assertSame(5, $result['visitors']);
        self::assertSame(5, $result['consent']);
        self::assertSame(10, $result['rollups']);
        self::assertSame(35, $result['candidates']);
        self::assertSame(0, $result['deleted']);
    }

    /**
     * The consent target is purged with the **visitor** window (`analytics.retention_visitor_days`),
     * not the raw one: a consent row that is 200 days old is outside the default raw retention
     * (90 days) but inside the visitor retention (395 days), so it must survive a real purge. Only
     * the row older than the visitor window is deleted, and the dry run counts exactly that row.
     */
    public function testConsentRowsFollowTheVisitorRetentionWindow(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createPurgeSchema($connection);

        $today = new \DateTimeImmutable('today');
        $connection->insert('analytics_consent_events', [
            'id' => 1,
            'occurred_at' => $today->modify('-400 days')->format('Y-m-d H:i:s'),
        ]);
        $connection->insert('analytics_consent_events', [
            'id' => 2,
            'occurred_at' => $today->modify('-200 days')->format('Y-m-d H:i:s'),
        ]);
        $connection->insert('analytics_consent_events', [
            'id' => 3,
            'occurred_at' => $today->modify('-10 days')->format('Y-m-d H:i:s'),
        ]);

        $preview = $this->service($connection)->preview();
        self::assertSame(1, $preview['consent'], 'only the row past the visitor window is a candidate');
        self::assertSame(1, $preview['candidates'], 'the consent count is part of candidates');
        self::assertSame(0, $preview['deleted'], 'a preview deletes nothing');

        $result = $this->service($connection)->purge();
        self::assertSame(1, $result['consent']);
        self::assertSame(1, $result['deleted'], 'the consent count is part of deleted');

        $remaining = array_map(
            static fn (mixed $id): int => (int) $id,
            $connection->fetchFirstColumn('SELECT id FROM analytics_consent_events ORDER BY id'),
        );
        self::assertSame([2, 3], $remaining, 'the in-window consent rows survive a real purge');
    }

    /**
     * Every table the purge walks must exist, even though only `analytics_consent_events` is
     * populated here.
     */
    private function createPurgeSchema(Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();
        foreach ([
            'analytics_pageviews' => 'occurred_at',
            'analytics_events' => 'occurred_at',
            'analytics_sessions' => 'last_activity_at',
            'analytics_visitors' => 'last_seen_at',
            'analytics_consent_events' => 'occurred_at',
            'analytics_daily_stats' => 'day',
            'analytics_daily_dimensions' => 'day',
        ] as $table => $column) {
            $connection->executeStatement(
                'CREATE TABLE ' . $platform->quoteSingleIdentifier($table)
                . ' (id INTEGER PRIMARY KEY, ' . $platform->quoteSingleIdentifier($column) . ' VARCHAR(19) NOT NULL)',
            );
        }
    }

    /**
     * @param list<SettingRecord> $rows
     */
    private function service(Connection $connection, array $rows = []): PurgeService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return new PurgeService($this->config($rows), new PurgeRepository($em));
    }

    /**
     * @param list<SettingRecord> $rows
     */
    private function config(array $rows = []): AnalyticsConfig
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findAll')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $publishables = new PublishableRegistry();

        return new AnalyticsConfig(new Settings(
            $em,
            new Repository([]),
            new ContentReadContext(),
            $publishables,
            new StagingWriter(new StagingStore($em), $publishables),
        ));
    }
}
