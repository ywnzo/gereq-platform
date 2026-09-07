<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\ProductDiscounts;

use Doctrine\ORM\EntityManagerInterface;
use Modules\ProductDiscounts\Application\ProductDiscountsProvider;
use Modules\ProductDiscounts\Domain\ProductDiscount;
use PHPUnit\Framework\TestCase;

final class ProductDiscountTest extends TestCase
{
    public function testActiveWindowAndStockConditions(): void
    {
        $discount = ProductDiscount::forProduct('prod-1', 'percent', '15');
        $now = new \DateTimeImmutable('2026-06-01 12:00:00');

        self::assertTrue($discount->isCurrentlyActive($now));

        $discount->setStartsAt(new \DateTimeImmutable('2026-07-01'));
        self::assertFalse($discount->isCurrentlyActive($now));

        $discount->setStartsAt(new \DateTimeImmutable('2026-01-01'));
        $discount->setEndsAt(new \DateTimeImmutable('2026-05-01'));
        self::assertFalse($discount->isCurrentlyActive($now));

        $discount->setEndsAt(null);
        $discount->setActive(false);
        self::assertFalse($discount->isCurrentlyActive($now));

        $discount->setActive(true);
        $discount->setCondition(ProductDiscount::CONDITION_STOCK_LTE, 5);
        self::assertTrue($discount->matchesStockCondition(3));
        self::assertFalse($discount->matchesStockCondition(10));
        self::assertFalse($discount->matchesStockCondition(null));

        $discount->setCondition(ProductDiscount::CONDITION_STOCK_GTE, 20);
        self::assertTrue($discount->matchesStockCondition(25));
        self::assertFalse($discount->matchesStockCondition(5));
    }

    public function testNormalizeKindFixedToAmount(): void
    {
        $discount = ProductDiscount::forCategory('cat-1', 'fixed', '50');
        self::assertSame(ProductDiscount::SCOPE_CATEGORY, $discount->scopeType());
        self::assertSame('amount', $discount->kind());
        self::assertNull($discount->productId());
    }

    public function testApplyCatalogDiscountPercentAndAmount(): void
    {
        $provider = new ProductDiscountsProvider($this->createMock(EntityManagerInterface::class));

        $percent = $provider->applyCatalogDiscount('100.00', 'percent', 10.0);
        self::assertSame('90.00', $percent['price']);
        self::assertSame('100.00', $percent['original_price']);

        $amount = $provider->applyCatalogDiscount('100.00', 'amount', 25.0);
        self::assertSame('75.00', $amount['price']);

        $tooBig = $provider->applyCatalogDiscount('10.00', 'amount', 50.0);
        self::assertSame('0.00', $tooBig['price']);

        $noop = $provider->applyCatalogDiscount('100.00', null, null);
        self::assertSame('100.00', $noop['price']);
        self::assertNull($noop['original_price']);
    }
}
