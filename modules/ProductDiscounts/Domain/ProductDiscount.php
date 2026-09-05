<?php

declare(strict_types=1);

namespace Modules\ProductDiscounts\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * Catalog sale on a single product (baby-naty product_discounts).
 * Applied to list/unit price before cart coupons.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_discounts')]
#[ORM\UniqueConstraint(name: 'uniq_product_discounts_product', columns: ['product_id'])]
#[ORM\Index(name: 'idx_product_discounts_active_window', columns: ['is_active', 'starts_at', 'ends_at'])]
class ProductDiscount
{
    public const KINDS = ['percent', 'amount'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'product_id', length: 36)]
    private string $productId;

    #[ORM\Column(length: 16)]
    private string $kind = 'percent';

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    private string $value = '0.00';

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $description = null;

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

    public function __construct(string $productId, string $kind = 'percent', string $value = '0')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->productId = $productId;
        $this->kind = $this->normalizeKind($kind);
        $this->value = number_format((float) $value, 2, '.', '');
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function productId(): string
    {
        return $this->productId;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function description(): ?string
    {
        return $this->description;
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

    public function isCurrentlyActive(?\DateTimeImmutable $now = null): bool
    {
        if (!$this->active) {
            return false;
        }
        $now ??= new \DateTimeImmutable();
        if ($this->startsAt !== null && $this->startsAt > $now) {
            return false;
        }
        if ($this->endsAt !== null && $this->endsAt < $now) {
            return false;
        }

        return true;
    }

    public function setKind(string $kind): void
    {
        $this->kind = $this->normalizeKind($kind);
        $this->touch();
    }

    public function setValue(string $value): void
    {
        $this->value = number_format((float) $value, 2, '.', '');
        $this->touch();
    }

    public function setDescription(?string $description): void
    {
        $description = $description !== null ? trim($description) : null;
        $this->description = $description !== '' ? $description : null;
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

    private function normalizeKind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        if ($kind === 'fixed') {
            return 'amount';
        }

        return in_array($kind, self::KINDS, true) ? $kind : 'percent';
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
