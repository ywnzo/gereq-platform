<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Gereq\Debug\Doctrine\QueryMiddleware;
use Gereq\Debug\QueryCollector;
use Gereq\Testing\QueryAssertions;
use Modules\Analytics\Application\Repositories\TrafficRepository;
use PHPUnit\Framework\TestCase;

final class TrafficRepositoryBatchTest extends TestCase
{
    use QueryAssertions;

    public function testFiftyEventsUseOneWriteAndPreserveProperties(): void
    {
        $queries = new QueryCollector();
        $config = new Configuration();
        $config->setMiddlewares([new QueryMiddleware($queries)]);
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $db->executeStatement('CREATE TABLE analytics_events (id TEXT PRIMARY KEY, site_id TEXT, session_id TEXT, visitor_id TEXT, pageview_id TEXT, name TEXT, occurred_at TEXT, value REAL, props TEXT)');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($db);
        $repository = new TrafficRepository($em);
        $rows = array_fill(0, 50, [
            'site_id' => 'site', 'session_id' => 'session', 'visitor_id' => 'visitor',
            'pageview_id' => 'page', 'name' => 'form_field_focus',
            'occurred_at' => new \DateTimeImmutable('2026-10-20 12:00:00'),
            'value' => 1.5, 'props' => ['field' => 'email', 'filled' => true],
        ]);
        $before = $queries->count();
        $repository->insertEvents($rows);
        self::assertSame(1, $queries->count() - $before);
        $this->assertNoNPlusOne($queries);
        self::assertSame(50, (int) $db->fetchOne('SELECT COUNT(*) FROM analytics_events'));
        $row = $db->fetchAssociative('SELECT * FROM analytics_events LIMIT 1');
        self::assertIsArray($row);
        self::assertSame('2026-10-20 12:00:00', $row['occurred_at']);
        self::assertSame(['field' => 'email', 'filled' => true], json_decode((string) $row['props'], true));
        self::assertSame(1.5, (float) $row['value']);
        self::assertSame(50, (int) $db->fetchOne('SELECT COUNT(DISTINCT id) FROM analytics_events'));

        $before = $queries->count();
        $repository->insertEvents([]);
        self::assertSame($before, $queries->count());
        $before = $queries->count();
        $repository->insertEvents(array_fill(0, 201, ['name' => 'session_replay', 'props' => ['data' => 'chunk']]));
        self::assertSame(3, $queries->count() - $before);
        self::assertSame(251, (int) $db->fetchOne('SELECT COUNT(*) FROM analytics_events'));
    }
}
