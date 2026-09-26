<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Debug;

use Gereq\Debug\NPlusOneDetector;
use Gereq\Debug\QueryCollector;
use Gereq\Testing\QueryAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Core coverage for N+1 fingerprinting and query-budget assertions.
 */
final class NPlusOneDetectorTest extends TestCase
{
    use QueryAssertions;

    public function testDetectsRepeatedParameterizedQueries(): void
    {
        $detector = new NPlusOneDetector(2);
        $hits = $detector->detect([
            [
                'sql' => 'SELECT * FROM users WHERE id = ?',
                'params' => [1],
                'duration' => 0.4,
                'origin' => 'UserRepo.php:10',
            ],
            [
                'sql' => 'SELECT * FROM users WHERE id = ?',
                'params' => [2],
                'duration' => 0.5,
                'origin' => 'UserRepo.php:10',
            ],
            [
                'sql' => 'SELECT COUNT(*) FROM roles',
                'params' => [],
                'duration' => 0.2,
                'origin' => 'RoleRepo.php:1',
            ],
        ]);

        self::assertCount(1, $hits);
        self::assertSame(2, $hits[0]['count']);
        self::assertSame(['UserRepo.php:10'], $hits[0]['origins']);
    }

    public function testBelowThresholdIsNotReported(): void
    {
        $detector = new NPlusOneDetector(3);
        $hits = $detector->detect([
            [
                'sql' => 'SELECT * FROM t WHERE id = ?',
                'params' => [1],
                'duration' => 0.1,
                'origin' => 'A.php:1',
            ],
            [
                'sql' => 'SELECT * FROM t WHERE id = ?',
                'params' => [2],
                'duration' => 0.1,
                'origin' => 'A.php:1',
            ],
        ]);

        self::assertSame([], $hits);
    }

    #[DataProvider('fingerprintPairs')]
    public function testFingerprintCollapsesLiterals(string $a, string $b): void
    {
        self::assertSame(
            NPlusOneDetector::fingerprint($a),
            NPlusOneDetector::fingerprint($b),
        );
    }

    /** @return list<array{0: string, 1: string}> */
    public static function fingerprintPairs(): array
    {
        return [
            [
                "SELECT * FROM products WHERE id = 1 AND name = 'a'",
                'SELECT * FROM products WHERE id = 99 AND name = "b"',
            ],
            [
                'SELECT * FROM t WHERE id IN (1, 2, 3)',
                'SELECT * FROM t WHERE id IN (9, 8)',
            ],
            [
                "SELECT * FROM t WHERE slug = 'foo'",
                "SELECT * FROM t WHERE slug = 'bar'",
            ],
        ];
    }

    public function testAssertNoNPlusOnePassesOnCleanBudget(): void
    {
        $queries = new QueryCollector(2);
        $queries->add('SELECT * FROM settings', [], 0.5, 'src/Setting/Settings.php:10');
        $queries->add(
            'SELECT * FROM modules WHERE enabled = 1',
            [],
            0.3,
            'src/Module/ModuleManager.php:20',
        );

        $this->assertNoNPlusOne($queries);
        $this->assertQueryCountAtMost($queries, 2);
        $this->assertNPlusOneHitCount($queries, 0);
    }

    public function testAssertNoNPlusOneFailsOnClassicLoop(): void
    {
        $origin = 'src/Publish/Adapter/ListingPublishAdapter.php:33';
        $queries = new QueryCollector(2);
        $queries->add('SELECT * FROM listings WHERE id = ?', [1], 0.2, $origin);
        $queries->add('SELECT * FROM listings WHERE id = ?', [2], 0.2, $origin);
        $queries->add('SELECT * FROM listings WHERE id = ?', [3], 0.2, $origin);

        $this->assertNPlusOneHitCount($queries, 1);

        try {
            $this->assertNoNPlusOne($queries);
            self::fail('Expected assertNoNPlusOne to fail.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            self::assertStringContainsString('Unexpected N+1', $e->getMessage());
            self::assertStringContainsString('3x', $e->getMessage());
        }
    }
}
