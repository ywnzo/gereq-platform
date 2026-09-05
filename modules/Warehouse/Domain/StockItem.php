<?php

declare(strict_types=1);

namespace Modules\Warehouse\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'warehouse_stock_items')]
#[ORM\UniqueConstraint(name: 'uniq_stock_wh_product', columns: ['warehouse_id', 'product_id'])]
#[ORM\Index(name: 'idx_stock_product', columns: ['product_id'])]
class StockItem
{
    public const LOW_THRESHOLD = 5.0;

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'warehouse_id', length: 36)]
    private string $warehouseId;

    #[ORM\Column(name: 'product_id', length: 36)]
    private string $productId;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 4)]
    private string $qty = '0.0000';

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $warehouseId, string $productId, string $qty = '0')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->warehouseId = $warehouseId;
        $this->productId = $productId;
        $this->qty = $this->normalize($qty);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function warehouseId(): string
    {
        return $this->warehouseId;
    }

    public function productId(): string
    {
        return $this->productId;
    }

    public function qty(): string
    {
        return $this->qty;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setQty(string $qty): void
    {
        $this->qty = $this->normalize($qty);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function addQty(string $delta): void
    {
        $this->setQty((string) ((float) $this->qty + (float) $delta));
    }

    public function isLow(?float $threshold = null): bool
    {
        return (float) $this->qty < ($threshold ?? self::LOW_THRESHOLD);
    }

    private function normalize(string $qty): string
    {
        return number_format((float) $qty, 4, '.', '');
    }
}
