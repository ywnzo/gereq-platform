<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Modules\Analytics\Application\Repositories\RollupRepository;
use Modules\Analytics\Application\Repositories\SiteRepository;
use Modules\Analytics\Application\RollupService;
use PHPUnit\Framework\TestCase;

/**
 * Daily rollup (README "Retention and rollups").
 *
 * `SiteRepository` / `RollupRepository` are final and DB-bound, so the service is exercised
 * against a narrow double of the ORM/DBAL boundary: these tests pin day normalisation, the
 * per-site/per-day row count and the delete-before-insert order that makes `rollup()` idempotent —
 * not the SQL execution semantics of a real database.
 */
final class RollupServiceTest extends TestCase
{
    public function testRollupCountsOneRowPerSitePerDay(): void
    {
        $run = $this->runRollup('2026-10-18', 2, [$this->site('s1'), $this->site('s2')]);

        self::assertSame(4, $run['rows']);

        $days = $this->insertedStatDays($run['statements']);
        sort($days);
        self::assertSame(['2026-10-17', '2026-10-17', '2026-10-18', '2026-10-18'], $days);
    }

    public function testRollupNormalisesTheDayToDateOnly(): void
    {
        $withTime = $this->runRollup('2026-10-18T23:59:59', 1, [$this->site('s1')]);
        self::assertSame(['2026-10-18'], $this->insertedStatDays($withTime['statements']));

        $withPadding = $this->runRollup('  2026-10-18  ', 1, [$this->site('s1')]);
        self::assertSame(['2026-10-18'], $this->insertedStatDays($withPadding['statements']));
    }

    public function testRollupFallsBackToTodayForMissingOrInvalidDay(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $missing = $this->runRollup(null, 1, [$this->site('s1')]);
        self::assertSame([$today], $this->insertedStatDays($missing['statements']));

        $invalid = $this->runRollup('not-a-date', 1, [$this->site('s1')]);
        self::assertSame([$today], $this->insertedStatDays($invalid['statements']));
    }

    public function testDimensionRowsAreDeletedBeforeReinsertOnEveryRun(): void
    {
        $first = $this->runRollup('2026-10-18', 1, [$this->site('s1')]);
        $second = $this->runRollup('2026-10-18', 1, [$this->site('s1')]);

        // Re-running the same day converges to the same row count (idempotent re-run).
        self::assertSame($first['rows'], $second['rows']);
        self::assertSame(1, $first['rows']);

        foreach ([$first, $second] as $run) {
            $deleteIndex = $this->indexOf($run['statements'], 'DELETE FROM analytics_daily_dimensions');
            $insertIndex = $this->indexOf($run['statements'], 'INSERT INTO analytics_daily_dimensions');
            self::assertNotNull($deleteIndex);
            self::assertNotNull($insertIndex);
            self::assertLessThan($insertIndex, $deleteIndex, 'dimension rows must be deleted before re-insert');

            self::assertSame(['s1', '2026-10-18'], $run['statements'][$deleteIndex]['params']);
            self::assertSame('path', $run['statements'][$insertIndex]['params'][3]);
            self::assertSame('/a', $run['statements'][$insertIndex]['params'][4]);
        }
    }

    public function testRollupDaysAreCappedAtFourHundred(): void
    {
        // The queue payload is JSON reconstructed through the job constructor, so a forged or
        // internal value must not request an unbounded rebuild.
        $run = $this->runRollup('2026-10-18', 100000, [$this->site('s1')]);

        self::assertSame(400, $run['rows']);

        $days = array_values(array_unique($this->insertedStatDays($run['statements'])));
        self::assertCount(400, $days);
        self::assertContains('2026-10-18', $days);
        self::assertContains('2025-09-14', $days, 'the cap reaches 399 days back from the end day');
    }

    public function testRollupDaysFloorAtOne(): void
    {
        self::assertSame(1, $this->runRollup('2026-10-18', 0, [$this->site('s1')])['rows']);
        self::assertSame(1, $this->runRollup('2026-10-18', -5, [$this->site('s1')])['rows']);
    }

    /**
     * @param list<array<string, mixed>> $sites
     * @return array{rows: int, statements: list<array{sql: string, params: array<int, mixed>}>}
     */
    private function runRollup(?string $day, int $days, array $sites): array
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(0);

        $dimensionServed = false;
        $connection->method('fetchAllAssociative')
            ->willReturnCallback(function (string $query, array $params = [], array $types = []) use (&$dimensionServed): array {
                if (!$dimensionServed) {
                    $dimensionServed = true;

                    return [[
                        'bucket' => '/a',
                        'visitors' => 1,
                        'sessions' => 1,
                        'pageviews' => 1,
                        'events' => 0,
                        'duration' => 5,
                    ]];
                }

                return [];
            });

        /** @var list<array{sql: string, params: array<int, mixed>}> $statements */
        $statements = [];
        $connection->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = [], array $types = []) use (&$statements): int {
                $statements[] = ['sql' => $sql, 'params' => $params];

                return 1;
            });

        $query = $this->createMock(Query::class);
        $query->method('getArrayResult')->willReturn($sites);
        $query->method('getOneOrNullResult')->willReturn(null);

        $builder = $this->createMock(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'andWhere', 'setParameter', 'groupBy', 'orderBy', 'setMaxResults'] as $fluent) {
            $builder->method($fluent)->willReturnSelf();
        }
        $builder->method('getQuery')->willReturn($query);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('createQueryBuilder')->willReturn($builder);

        $service = new RollupService($em, new SiteRepository($em), new RollupRepository($em));
        $result = $service->rollup($day, $days);

        return ['rows' => $result['rows'], 'statements' => $statements];
    }

    /**
     * @param list<array{sql: string, params: array<int, mixed>}> $statements
     * @return list<string>
     */
    private function insertedStatDays(array $statements): array
    {
        $days = [];
        foreach ($statements as $statement) {
            if (str_contains($statement['sql'], 'INSERT INTO analytics_daily_stats')) {
                $days[] = (string) ($statement['params'][2] ?? '');
            }
        }

        return $days;
    }

    /**
     * @param list<array{sql: string, params: array<int, mixed>}> $statements
     */
    private function indexOf(array $statements, string $needle): ?int
    {
        foreach ($statements as $index => $statement) {
            if (str_contains($statement['sql'], $needle)) {
                return $index;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function site(string $id): array
    {
        return [
            'id' => $id,
            'name' => 'Site ' . $id,
            'key' => 'grq_an_' . $id,
            'domains' => [],
            'enabled' => true,
            'timezone' => 'Europe/Prague',
            'secret' => str_repeat('a', 64),
            'retention_override_days' => null,
            'keep_bot_hits' => false,
            'respect_dnt' => true,
            'excluded_paths' => [],
            'excluded_ips' => [],
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];
    }
}
