<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\ModuleStructure;

use Gereq\Debug\NPlusOneDetector;
use Gereq\Debug\QueryCollector;
use Gereq\Testing\QueryAssertions;
use PHPUnit\Framework\TestCase;

/**
 * Query-hygiene contract for shipped modules (runs under the `modules` target).
 *
 * Ensures the platform N+1 detector + QueryAssertions stay usable for module
 * Application/HTTP code, and that classic per-row SELECTs are flagged.
 */
final class QueryHygieneTest extends TestCase
{
    use QueryAssertions;

    public function testModuleOriginNPlusOneIsDetected(): void
    {
        $queries = new QueryCollector(2);
        $queries->add(
            'SELECT * FROM messenger_conversations WHERE id = ?',
            [1],
            0.3,
            'modules/Messenger/Application/MessengerProvider.php:40',
        );
        $queries->add(
            'SELECT * FROM messenger_conversations WHERE id = ?',
            [2],
            0.4,
            'modules/Messenger/Application/MessengerProvider.php:40',
        );
        $queries->add(
            'SELECT COUNT(*) FROM messenger_participants WHERE conversation_id = ?',
            [1],
            0.2,
            'modules/Messenger/Application/MessengerProvider.php:55',
        );

        $this->assertNPlusOneHitCount($queries, 1);
        $hits = $queries->nPlusOnes();
        self::assertStringContainsString('modules/Messenger', $hits[0]['origins'][0]);
    }

    public function testBatchedModuleLookupHasNoNPlusOne(): void
    {
        $queries = new QueryCollector(2);
        // One IN (…) load instead of per-id finds (CatalogCacheStore / similar).
        $queries->add(
            'SELECT * FROM catalog_cache WHERE cache_key IN (?, ?, ?)',
            ['a', 'b', 'c'],
            1.2,
            'modules/BikeService/Application/Catalog/CatalogCacheStore.php:150',
        );

        $this->assertNoNPlusOne($queries);
        $this->assertQueryCountAtMost($queries, 1);
    }

    public function testFingerprintTreatsModuleLiteralIdsAsSameShape(): void
    {
        self::assertSame(
            NPlusOneDetector::fingerprint('SELECT * FROM fleet_vehicles WHERE id = 10'),
            NPlusOneDetector::fingerprint('SELECT * FROM fleet_vehicles WHERE id = 99'),
        );
    }

    public function testPlatformNPlusOneConfigKeysExist(): void
    {
        $config = require dirname(__DIR__, 4) . '/config/app.php';
        self::assertIsArray($config);
        self::assertArrayHasKey('n_plus_one_threshold', $config);
        self::assertArrayHasKey('n_plus_one_log', $config);
        self::assertGreaterThanOrEqual(2, (int) $config['n_plus_one_threshold']);
    }
}
