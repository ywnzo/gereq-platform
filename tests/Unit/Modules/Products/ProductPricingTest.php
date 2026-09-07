<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Products;

use Modules\Products\Domain\ProductOptionValue;
use Modules\Products\Domain\QuantityPriceTier;
use PHPUnit\Framework\TestCase;

final class ProductPricingTest extends TestCase
{
    public function testOptionValuePriceAdjust(): void
    {
        $value = new ProductOptionValue('grp-1', 'XL', 'Extra large');
        self::assertFalse($value->hasPriceAdjust());
        self::assertSame(100.0, $value->applyToPrice(100.0));

        $value->setPriceAdjust(ProductOptionValue::ADJUST_AMOUNT, 25);
        self::assertTrue($value->hasPriceAdjust());
        self::assertSame(125.0, $value->applyToPrice(100.0));

        $value->setPriceAdjust(ProductOptionValue::ADJUST_PERCENT, -10);
        self::assertSame(90.0, $value->applyToPrice(100.0));

        $value->setPriceAdjust(ProductOptionValue::ADJUST_AMOUNT, -200);
        self::assertSame(0.0, $value->applyToPrice(50.0));
    }

    public function testQuantityPriceTierMatchRange(): void
    {
        $tier = new QuantityPriceTier('product', 'prod-1', 10, '80.00');
        $tier->setMaxQty(49);

        self::assertFalse($tier->matches(9));
        self::assertTrue($tier->matches(10));
        self::assertTrue($tier->matches(49));
        self::assertFalse($tier->matches(50));

        $open = new QuantityPriceTier('category', 'cat-1', 50, '70');
        self::assertTrue($open->matches(999));
    }
}
