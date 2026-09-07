<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Coupons;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Modules\Coupons\Application\CouponsProvider;
use Modules\Coupons\Domain\Coupon;
use PHPUnit\Framework\TestCase;

final class CouponsProviderTest extends TestCase
{
    public function testNotFoundAndInactive(): void
    {
        $provider = $this->provider(null);
        self::assertFalse($provider->validateForCart('MISSING', 100)['ok']);

        $inactive = new Coupon('OFF', 'Off', 'percent', '10');
        $inactive->setActive(false);
        self::assertSame('Coupon is inactive', $this->provider($inactive)->validateForCart('OFF', 100)['message']);
    }

    public function testDateLimitsUsesAndMinOrder(): void
    {
        $future = new Coupon('FUT', 'Future', 'percent', '10');
        $future->setStartsAt(new \DateTimeImmutable('+1 day'));
        self::assertSame('Coupon is not active yet', $this->provider($future)->validateForCart('FUT', 100)['message']);

        $expired = new Coupon('OLD', 'Old', 'percent', '10');
        $expired->setEndsAt(new \DateTimeImmutable('-1 day'));
        self::assertSame('Coupon has expired', $this->provider($expired)->validateForCart('OLD', 100)['message']);

        $limited = new Coupon('MAX', 'Max', 'percent', '10');
        $limited->setMaxUses(2);
        $limited->setUsedCount(2);
        self::assertSame('Coupon usage limit reached', $this->provider($limited)->validateForCart('MAX', 100)['message']);

        $min = new Coupon('MIN', 'Min', 'percent', '10');
        $min->setMinOrder('200');
        self::assertSame('Order subtotal below minimum', $this->provider($min)->validateForCart('MIN', 100)['message']);
    }

    public function testPercentAndFixedDiscountCappedAtSubtotal(): void
    {
        $percent = new Coupon('P10', 'Ten', 'percent', '10');
        $result = $this->provider($percent)->validateForCart('P10', 200);
        self::assertTrue($result['ok']);
        self::assertSame('20.00', $result['discount']);

        $fixed = new Coupon('F50', 'Fifty', 'fixed', '50');
        self::assertSame('50.00', $this->provider($fixed)->validateForCart('F50', 200)['discount']);

        $tooBig = new Coupon('BIG', 'Big', 'fixed', '500');
        self::assertSame('100.00', $this->provider($tooBig)->validateForCart('BIG', 100)['discount']);
    }

    public function testIncrementUsedCount(): void
    {
        $coupon = new Coupon('USE', 'Use', 'percent', '5');
        $coupon->incrementUsedCount();
        self::assertSame(1, $coupon->usedCount());
    }

    private function provider(?Coupon $coupon): CouponsProvider
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($coupon);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return new CouponsProvider($em);
    }
}
