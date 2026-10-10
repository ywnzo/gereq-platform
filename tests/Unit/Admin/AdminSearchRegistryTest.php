<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Admin;

use Gereq\Admin\Search\AdminSearchHit;
use Gereq\Admin\Search\AdminSearchRegistry;
use PHPUnit\Framework\TestCase;

final class AdminSearchRegistryTest extends TestCase
{
    public function testDescribeReturnsRegisteredSources(): void
    {
        $registry = new AdminSearchRegistry();
        $registry->add(
            'alpha',
            ['name'],
            static fn (string $q, int $limit): array => [],
            group: 'nav.alpha',
            icon: 'home',
        );

        $desc = $registry->describe();
        self::assertCount(1, $desc);
        self::assertSame('alpha', $desc[0]['id']);
        self::assertSame(['name'], $desc[0]['fields']);
        self::assertSame('home', $desc[0]['icon']);
    }

    public function testSearchRequiresTwoCharacters(): void
    {
        $registry = new AdminSearchRegistry();
        $registry->add('alpha', ['name'], static function (string $q, int $limit): array {
            return [new AdminSearchHit('a', 'A', '/a', 'g')];
        });

        self::assertSame([], $registry->search('x'));
    }

    public function testFairBudgetDoesNotStarveLaterSources(): void
    {
        $registry = new AdminSearchRegistry();
        $registry->add(
            'early',
            ['name'],
            static function (string $q, int $limit): array {
                $hits = [];
                for ($i = 0; $i < $limit; $i++) {
                    $hits[] = new AdminSearchHit('e' . $i, 'Early ' . $i, '/e/' . $i, 'early');
                }

                return $hits;
            },
            limit: 20,
        );
        $registry->add(
            'late',
            ['name'],
            static function (string $q, int $limit): array {
                $hits = [];
                for ($i = 0; $i < $limit; $i++) {
                    $hits[] = new AdminSearchHit('l' . $i, 'Late ' . $i, '/l/' . $i, 'late');
                }

                return $hits;
            },
            limit: 20,
        );

        $hits = $registry->search('ab', maxTotal: 6);
        self::assertCount(6, $hits);

        $early = array_filter($hits, static fn (AdminSearchHit $h): bool => str_starts_with($h->id, 'e'));
        $late = array_filter($hits, static fn (AdminSearchHit $h): bool => str_starts_with($h->id, 'l'));
        self::assertCount(3, $early);
        self::assertCount(3, $late);
    }
}
