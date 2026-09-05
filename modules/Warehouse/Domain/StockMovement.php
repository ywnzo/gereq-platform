<?php

declare(strict_types=1);

namespace Modules\Warehouse\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'warehouse_stock_movements')]
#[ORM\Index(name: 'idx_movements_warehouse', columns: ['warehouse_id'])]
#[ORM\Index(name: 'idx_movements_product', columns: ['product_id'])]
#[ORM\Index(name: 'idx_movements_created', columns: ['created_at'])]
class StockMovement
{
    public const TYPES = ['in', 'out', 'transfer', 'adjust'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'warehouse_id', length: 36)]
    private string $warehouseId;

    #[ORM\Column(name: 'product_id', length: 36)]
    private string $productId;

    #[ORM\Column(length: 16)]
    private string $type;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 4)]
    private string $qty;

    #[ORM\Column(name: 'target_warehouse_id', length: 36, nullable: true)]
    private ?string $targetWarehouseId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_by', length: 36, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $warehouseId,
        string $productId,
        string $type,
        string $qty,
        ?string $createdBy = null,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Neplatný typ pohybu skladu.');
        }

        $this->id = Uuid::uuid7()->toString();
        $this->warehouseId = $warehouseId;
        $this->productId = $productId;
        $this->type = $type;
        $this->qty = number_format(abs((float) $qty), 4, '.', '');
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
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

    public function type(): string
    {
        return $this->type;
    }

    public function qty(): string
    {
        return $this->qty;
    }

    public function targetWarehouseId(): ?string
    {
        return $this->targetWarehouseId;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function createdBy(): ?string
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setTargetWarehouseId(?string $id): void
    {
        $this->targetWarehouseId = $id !== '' ? $id : null;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note !== '' ? $note : null;
    }

    public function typeLabel(): string
    {
        return 'warehouse.movement.type.' . $this->type;
    }
}
