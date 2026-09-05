<?php

declare(strict_types=1);

namespace Modules\Coupons\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'coupons')]
#[ORM\UniqueConstraint(name: 'uniq_coupons_code', columns: ['code'])]
class Coupon
{
    public const TYPES = [
        'percent',
        'fixed',
    ];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(length: 16)]
    private string $type;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    private string $value = '0.00';

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(name: 'min_order', type: 'decimal', precision: 14, scale: 2, nullable: true)]
    private ?string $minOrder = null;

    #[ORM\Column(name: 'max_uses', type: 'integer', nullable: true)]
    private ?int $maxUses = null;

    #[ORM\Column(name: 'used_count', type: 'integer')]
    private int $usedCount = 0;

    #[ORM\Column(name: 'starts_at', nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(name: 'ends_at', nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code, string $name, string $type = 'percent', string $value = '0')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->code = strtoupper(trim($code));
        $this->name = $name;
        $this->type = $this->normalizeType($type);
        $this->value = number_format((float) $value, 2, '.', '');
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function type(): string
    {
        return $this->type;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function currency(): ?string
    {
        return $this->currency;
    }

    public function minOrder(): ?string
    {
        return $this->minOrder;
    }

    public function maxUses(): ?int
    {
        return $this->maxUses;
    }

    public function usedCount(): int
    {
        return $this->usedCount;
    }

    public function startsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function endsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setCode(string $code): void
    {
        $this->code = strtoupper(trim($code));
        $this->touch();
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function setType(string $type): void
    {
        $this->type = $this->normalizeType($type);
        $this->touch();
    }

    public function setValue(string $value): void
    {
        $this->value = number_format((float) $value, 2, '.', '');
        $this->touch();
    }

    public function setCurrency(?string $currency): void
    {
        if ($currency === null || trim($currency) === '') {
            $this->currency = null;
        } else {
            $this->currency = strtoupper(trim($currency));
        }
        $this->touch();
    }

    public function setMinOrder(?string $minOrder): void
    {
        if ($minOrder === null || trim($minOrder) === '') {
            $this->minOrder = null;
        } else {
            $this->minOrder = number_format((float) $minOrder, 2, '.', '');
        }
        $this->touch();
    }

    public function setMaxUses(?int $maxUses): void
    {
        $this->maxUses = $maxUses;
        $this->touch();
    }

    public function setUsedCount(int $usedCount): void
    {
        $this->usedCount = max(0, $usedCount);
        $this->touch();
    }

    public function incrementUsedCount(): void
    {
        $this->usedCount++;
        $this->touch();
    }

    public function setStartsAt(?\DateTimeImmutable $startsAt): void
    {
        $this->startsAt = $startsAt;
        $this->touch();
    }

    public function setEndsAt(?\DateTimeImmutable $endsAt): void
    {
        $this->endsAt = $endsAt;
        $this->touch();
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, self::TYPES, true)) {
            return 'percent';
        }

        return $type;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
