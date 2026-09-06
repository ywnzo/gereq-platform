<?php

declare(strict_types=1);

namespace Modules\Bundles\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'product_bundles')]
#[ORM\UniqueConstraint(name: 'uniq_product_bundles_code', columns: ['code'])]
class ProductBundle
{
    public const DISCOUNT_TYPES = [
        'percent',
        'fixed',
        'none',
    ];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'discount_type', length: 16)]
    private string $discountType = 'none';

    #[ORM\Column(name: 'discount_value', type: 'decimal', precision: 14, scale: 2)]
    private string $discountValue = '0.00';

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(name: 'min_items', type: 'integer')]
    private int $minItems = 1;

    #[ORM\Column(name: 'max_items', type: 'integer', nullable: true)]
    private ?int $maxItems = null;

    #[ORM\Column(name: 'max_same_product', type: 'integer', nullable: true)]
    private ?int $maxSameProduct = null;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code, string $name)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->code = strtoupper(trim($code));
        $this->name = $name;
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

    public function description(): ?string
    {
        return $this->description;
    }

    public function discountType(): string
    {
        return $this->discountType;
    }

    public function discountValue(): string
    {
        return $this->discountValue;
    }

    public function currency(): ?string
    {
        return $this->currency;
    }

    public function minItems(): int
    {
        return $this->minItems;
    }

    public function maxItems(): ?int
    {
        return $this->maxItems;
    }

    public function maxSameProduct(): ?int
    {
        return $this->maxSameProduct;
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

    public function setDescription(?string $description): void
    {
        $description = $description !== null ? trim($description) : null;
        $this->description = $description === '' ? null : $description;
        $this->touch();
    }

    public function setDiscountType(string $discountType): void
    {
        $this->discountType = $this->normalizeDiscountType($discountType);
        $this->touch();
    }

    public function setDiscountValue(string $discountValue): void
    {
        $this->discountValue = number_format((float) $discountValue, 2, '.', '');
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

    public function setMinItems(int $minItems): void
    {
        $this->minItems = max(1, $minItems);
        $this->touch();
    }

    public function setMaxItems(?int $maxItems): void
    {
        $this->maxItems = $maxItems !== null && $maxItems > 0 ? $maxItems : null;
        $this->touch();
    }

    public function setMaxSameProduct(?int $maxSameProduct): void
    {
        $this->maxSameProduct = $maxSameProduct !== null && $maxSameProduct > 0 ? $maxSameProduct : null;
        $this->touch();
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    private function normalizeDiscountType(string $type): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, self::DISCOUNT_TYPES, true)) {
            return 'none';
        }

        return $type;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
