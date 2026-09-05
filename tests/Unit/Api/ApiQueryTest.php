<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Api;

use Gereq\Api\ApiQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ApiQueryTest extends TestCase
{
    public function testParsesWhitelistedFiltersAndSort(): void
    {
        $request = Request::create('/api/v1/customers', 'GET', [
            'page' => 2,
            'per_page' => 10,
            'sort' => 'name',
            'direction' => 'desc',
            'search' => 'gereq',
            'filter' => ['status' => 'active', 'hack' => '1'],
        ]);

        $query = ApiQuery::fromRequest(
            $request,
            sortable: ['name', 'created_at'],
            filterable: ['status'],
        );

        self::assertSame(2, $query->page);
        self::assertSame(10, $query->perPage);
        self::assertSame('name', $query->sort);
        self::assertSame('desc', $query->direction);
        self::assertSame('gereq', $query->search);
        self::assertSame(['status' => 'active'], $query->filters);
        self::assertArrayNotHasKey('hack', $query->filters);
    }
}
