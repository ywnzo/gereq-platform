<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Loyalty\Domain\LoyaltyAccount;
use Modules\Loyalty\Domain\LoyaltyTier;

final class LoyaltyProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function accountFor(string $customerId): LoyaltyAccount
    {
        $customerId = trim($customerId);
        $account = $this->em->getRepository(LoyaltyAccount::class)->findOneBy(['customerId' => $customerId]);
        if ($account instanceof LoyaltyAccount) {
            return $account;
        }

        $account = new LoyaltyAccount($customerId);
        $tier = $this->tierForSpend('0');
        if ($tier !== null) {
            $account->setTierId($tier->id());
        }
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    public function tierForSpend(string|float|int $spend): ?LoyaltyTier
    {
        $amount = (float) $spend;
        /** @var list<LoyaltyTier> $tiers */
        $tiers = $this->em->getRepository(LoyaltyTier::class)->findBy(
            ['active' => true],
            ['minSpend' => 'DESC', 'sortOrder' => 'DESC'],
        );

        foreach ($tiers as $tier) {
            if ($amount >= (float) $tier->minSpend()) {
                return $tier;
            }
        }

        return null;
    }

    public function applyEarn(string $customerId, string|float|int $orderTotal): LoyaltyAccount
    {
        $account = $this->accountFor($customerId);
        $total = max(0, (float) $orderTotal);
        $points = (int) floor($total);
        if ($points > 0) {
            $account->adjustPoints($points);
        }
        if ($total > 0) {
            $account->addLifetimeSpend($total);
        }

        $tier = $this->tierForSpend($account->lifetimeSpend());
        $account->setTierId($tier?->id());
        $this->em->flush();

        return $account;
    }

    public function findTier(string $id): ?LoyaltyTier
    {
        $tier = $this->em->find(LoyaltyTier::class, $id);

        return $tier instanceof LoyaltyTier ? $tier : null;
    }

    public function findAccount(string $id): ?LoyaltyAccount
    {
        $account = $this->em->find(LoyaltyAccount::class, $id);

        return $account instanceof LoyaltyAccount ? $account : null;
    }
}
