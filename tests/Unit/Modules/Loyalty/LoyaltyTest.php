<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Loyalty;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Modules\Loyalty\Application\LoyaltyProvider;
use Modules\Loyalty\Domain\LoyaltyAccount;
use Modules\Loyalty\Domain\LoyaltyTier;
use PHPUnit\Framework\TestCase;

final class LoyaltyTest extends TestCase
{
    public function testAccountPointsAndSpend(): void
    {
        $account = new LoyaltyAccount('cust-1');
        $account->adjustPoints(50);
        $account->adjustPoints(-10);
        self::assertSame(40, $account->points());

        $account->adjustPoints(-100);
        self::assertSame(0, $account->points());

        $account->addLifetimeSpend(99.5);
        $account->addLifetimeSpend('0.5');
        self::assertSame('100.00', $account->lifetimeSpend());
    }

    public function testTierForSpendPicksHighestMatching(): void
    {
        $bronze = new LoyaltyTier('BRONZE', 'Bronze');
        $bronze->setMinSpend('0');
        $bronze->setSortOrder(1);

        $silver = new LoyaltyTier('SILVER', 'Silver');
        $silver->setMinSpend('1000');
        $silver->setSortOrder(2);

        $gold = new LoyaltyTier('GOLD', 'Gold');
        $gold->setMinSpend('5000');
        $gold->setSortOrder(3);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn([$gold, $silver, $bronze]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $provider = new LoyaltyProvider($em);
        self::assertSame($gold, $provider->tierForSpend(6000));
        self::assertSame($silver, $provider->tierForSpend(1500));
        self::assertSame($bronze, $provider->tierForSpend(10));
    }
}
