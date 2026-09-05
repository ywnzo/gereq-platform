<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Http;

use Gereq\Http\ListQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ListQueryTest extends TestCase
{
    public function testCapsPerPageAtMax100(): void
    {
        $request = Request::create('/admin/items', 'GET', [
            'per_page' => 500,
            'page' => 2,
        ]);

        $query = ListQuery::fromRequest(
            $request,
            sortable: ['name'],
            defaultSort: 'name',
            perPage: 20,
        );

        self::assertSame(100, $query->perPage);
        self::assertSame(2, $query->page);
        self::assertSame(100, $query->offset());
    }

    public function testUsesDefaultPerPageWhenMissing(): void
    {
        $request = Request::create('/admin/items', 'GET');

        $query = ListQuery::fromRequest(
            $request,
            sortable: ['name'],
            defaultSort: 'name',
            perPage: 100,
        );

        self::assertSame(100, $query->perPage);
        self::assertSame(1, $query->page);
    }
}
