<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Debug\NPlusOneLog;
use PHPUnit\Framework\TestCase;

final class NPlusOneLogTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/gereq-nplusone-' . uniqid('', true) . '.log';
        NPlusOneLog::setPath($this->tmp);
    }

    protected function tearDown(): void
    {
        NPlusOneLog::setPath(null);
        if (is_file($this->tmp)) {
            @unlink($this->tmp);
        }
    }

    public function testRecordAndRecentFullSql(): void
    {
        $sql = str_repeat('SELECT * FROM huge_table WHERE id = ? /* ' . str_repeat('x', 300) . ' */', 2);
        NPlusOneLog::record('GET', '/api/public/listings', [
            [
                'fingerprint' => 'fp1',
                'sql' => $sql,
                'count' => 12,
                'totalMs' => 4.5,
                'origins' => ['ListingsProvider.php:10'],
            ],
        ]);

        $hits = NPlusOneLog::recent(20);
        self::assertCount(1, $hits);
        self::assertSame($sql, $hits[0]['sql']);
        self::assertSame(12, $hits[0]['count']);
        self::assertSame('/api/public/listings', $hits[0]['path']);

        $lines = NPlusOneLog::formatRecent(20);
        self::assertStringContainsString('1 zásah', $lines[0]);
        self::assertTrue((bool) array_filter($lines, static fn (string $l): bool => str_contains($l, $sql)));
    }

    public function testRecentFiltersByMinutes(): void
    {
        $old = [
            'ts' => date('c', time() - 3600),
            'unix' => time() - 3600,
            'method' => 'GET',
            'path' => '/old',
            'count' => 5,
            'totalMs' => 1.0,
            'origins' => [],
            'fingerprint' => 'old',
            'sql' => 'SELECT 1',
        ];
        file_put_contents(
            $this->tmp,
            json_encode($old, JSON_THROW_ON_ERROR) . "\n",
        );

        NPlusOneLog::record('GET', '/new', [
            [
                'fingerprint' => 'new',
                'sql' => 'SELECT 2',
                'count' => 3,
                'totalMs' => 2.0,
                'origins' => [],
            ],
        ]);

        $hits = NPlusOneLog::recent(20);
        self::assertCount(1, $hits);
        self::assertSame('/new', $hits[0]['path']);
    }
}
