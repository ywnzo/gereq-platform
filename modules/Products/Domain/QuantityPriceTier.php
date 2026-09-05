<?php

declare(strict_types=1);

namespace Modules\Products\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/** Quantity-based price tiers for a category or product (generic wholesale/retail rules). */
#[ORM\Entity]
#[ORM\Table(name: 'quantity_price_tiers')]
#[ORM\Index(name: 'idx_qty_tiers_scope', columns: ['scope_type', 'scope_id'])]
class QuantityPriceTier
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    /** category | product */
    #[ORM\Column(name: 'scope_type', length: 32)]
    private string $scopeType;

    #[ORM\Column(name: 'scope_id', length: 36)]
    private string $scopeId;

    #[ORM\Column(name: 'min_qty', type: 'integer')]
    private int $minQty;

    #[ORM\Column(name: 'max_qty', type: 'integer', nullable: true)]
    private ?int $maxQty = null;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    private string $unitPrice;

    #[ORM\Column(length: 3)]
    private string $currency = 'CZK';

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder = 0;

    public function __construct(string $scopeType, string $scopeId, int $minQty, string $unitPrice, string $currency = 'CZK')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->scopeType = in_array($scopeType, ['category', 'product'], true) ? $scopeType : 'product';
        $this->scopeId = $scopeId;
        $this->minQty = max(1, $minQty);
        $this->unitPrice = number_format((float) $unitPrice, 2, '.', '');
        $this->currency = strtoupper($currency);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function scopeType(): string
    {
        return $this->scopeType;
    }

    public function scopeId(): string
    {
        return $this->scopeId;
    }

    public function minQty(): int
    {
        return $this->minQty;
    }

    public function maxQty(): ?int
    {
        return $this->maxQty;
    }

    public function unitPrice(): string
    {
        return $this->unitPrice;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setMinQty(int $minQty): void
    {
        $this->minQty = max(1, $minQty);
    }

    public function setMaxQty(?int $maxQty): void
    {
        $this->maxQty = $maxQty;
    }

    public function setUnitPrice(string $unitPrice): void
    {
        $this->unitPrice = number_format((float) $unitPrice, 2, '.', '');
    }

    public function setCurrency(string $currency): void
    {
        $this->currency = strtoupper($currency);
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    public function matches(int $qty): bool
    {
        if ($qty < $this->minQty) {
            return false;
        }

        return $this->maxQty === null || $qty <= $this->maxQty;
    }
}
