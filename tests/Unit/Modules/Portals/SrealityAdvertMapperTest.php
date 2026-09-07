<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Portals;

use Modules\Portals\Application\Sreality\SrealityAdvertMapper;
use PHPUnit\Framework\TestCase;

final class SrealityAdvertMapperTest extends TestCase
{
    public function testMapsSaleFlatDefaults(): void
    {
        $mapper = new SrealityAdvertMapper();
        $data = $mapper->map([
            'offer_type' => 'sale',
            'property_type' => 'flat',
            'currency' => 'CZK',
            'price' => 4500000,
            'title' => 'Byt 2+kk',
        ]);

        self::assertSame(1, $data['advert_function']);
        self::assertSame(1, $data['advert_type']);
        self::assertArrayHasKey('advert_price', $data);
    }

    public function testMapsRentHouse(): void
    {
        $mapper = new SrealityAdvertMapper();
        $data = $mapper->map([
            'offer_type' => 'pronajem',
            'property_type' => 'dum',
            'price' => 35000,
        ]);

        self::assertSame(2, $data['advert_function']);
        self::assertSame(2, $data['advert_type']);
        self::assertSame(2, $data['advert_price_unit']);
    }
}
