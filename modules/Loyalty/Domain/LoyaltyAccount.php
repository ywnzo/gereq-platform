<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'loyalty_accounts')]
#[ORM\UniqueConstraint(name: 'uniq_loyalty_accounts_customer', columns: ['customer_id'])]
class LoyaltyAccount
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'customer_id', length: 36)]
    private string $customerId;

    #[ORM\Column(name: 'tier_id', length: 36, nullable: true)]
    private ?string $tierId = null;

    #[ORM\Column(type: 'integer')]
    private int $points = 0;

    #[ORM\Column(name: 'lifetime_spend', type: 'decimal', precision: 14, scale: 2)]
    private string $lifetimeSpend = '0.00';

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $customerId)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->customerId = $customerId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    public function tierId(): ?string
    {
        return $this->tierId;
    }

    public function points(): int
    {
        return $this->points;
    }

    public function lifetimeSpend(): string
    {
        return $this->lifetimeSpend;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setTierId(?string $tierId): void
    {
        $this->tierId = $tierId !== null && $tierId !== '' ? $tierId : null;
        $this->touch();
    }

    public function setPoints(int $points): void
    {
        $this->points = max(0, $points);
        $this->touch();
    }

    public function adjustPoints(int $delta): void
    {
        $this->points = max(0, $this->points + $delta);
        $this->touch();
    }

    public function addLifetimeSpend(string|float|int $amount): void
    {
        $this->lifetimeSpend = number_format((float) $this->lifetimeSpend + (float) $amount, 2, '.', '');
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
