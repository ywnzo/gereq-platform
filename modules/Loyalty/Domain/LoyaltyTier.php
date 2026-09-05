<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'loyalty_tiers')]
#[ORM\UniqueConstraint(name: 'uniq_loyalty_tiers_code', columns: ['code'])]
class LoyaltyTier
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(name: 'min_spend', type: 'decimal', precision: 14, scale: 2)]
    private string $minSpend = '0.00';

    #[ORM\Column(name: 'discount_percent', type: 'decimal', precision: 8, scale: 2)]
    private string $discountPercent = '0.00';

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $code, string $name)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->code = strtoupper(trim($code));
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function minSpend(): string
    {
        return $this->minSpend;
    }

    public function discountPercent(): string
    {
        return $this->discountPercent;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCode(string $code): void
    {
        $this->code = strtoupper(trim($code));
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setMinSpend(string $minSpend): void
    {
        $this->minSpend = number_format((float) $minSpend, 2, '.', '');
    }

    public function setDiscountPercent(string $discountPercent): void
    {
        $this->discountPercent = number_format((float) $discountPercent, 2, '.', '');
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }
}
