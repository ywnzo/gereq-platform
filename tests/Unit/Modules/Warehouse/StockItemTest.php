<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Warehouse;

use Modules\Warehouse\Domain\StockItem;
use PHPUnit\Framework\TestCase;

final class StockItemTest extends TestCase
{
    public function testAddQtyAndLowStock(): void
    {
        $item = new StockItem('wh-1', 'prod-1', '3');
        self::assertTrue($item->isLow());
        self::assertTrue($item->isLow(5));
        self::assertFalse($item->isLow(2));

        $item->addQty('10');
        self::assertSame('13.0000', $item->qty());
        self::assertFalse($item->isLow());

        $item->addQty('-5.5');
        self::assertSame('7.5000', $item->qty());
    }
}
