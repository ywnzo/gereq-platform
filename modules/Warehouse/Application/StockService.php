<?php

declare(strict_types=1);

namespace Modules\Warehouse\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Event\EventBus;
use Modules\Products\Domain\Product;
use Modules\Warehouse\Domain\StockItem;
use Modules\Warehouse\Domain\StockMovement;
use Modules\Warehouse\Domain\Warehouse;

final class StockService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
    ) {
    }

    /**
     * @return array{movement: StockMovement, items: list<StockItem>}
     */
    public function createMovement(
        string $warehouseId,
        string $productId,
        string $type,
        string $qty,
        ?string $targetWarehouseId = null,
        ?string $note = null,
        ?string $createdBy = null,
    ): array {
        if (!in_array($type, StockMovement::TYPES, true)) {
            throw new \InvalidArgumentException('warehouse.error.invalid_type');
        }

        $warehouse = $this->em->find(Warehouse::class, $warehouseId);
        if (!$warehouse instanceof Warehouse) {
            throw new \InvalidArgumentException('warehouse.error.not_found');
        }

        if (!$this->em->find(Product::class, $productId) instanceof Product) {
            throw new \InvalidArgumentException('warehouse.error.invalid_product');
        }

        $amount = abs((float) $qty);
        if ($amount <= 0 && $type !== 'adjust') {
            throw new \InvalidArgumentException('warehouse.error.invalid_qty');
        }

        $source = $this->findOrCreateStock($warehouseId, $productId);
        $affected = [$source];

        if ($type === 'out' || $type === 'transfer') {
            if ((float) $source->qty() < $amount) {
                throw new \RuntimeException('warehouse.error.insufficient_stock');
            }
        }

        if ($type === 'transfer') {
            if ($targetWarehouseId === null || $targetWarehouseId === '' || $targetWarehouseId === $warehouseId) {
                throw new \InvalidArgumentException('warehouse.error.invalid_target');
            }
            $targetWh = $this->em->find(Warehouse::class, $targetWarehouseId);
            if (!$targetWh instanceof Warehouse) {
                throw new \InvalidArgumentException('warehouse.error.invalid_target');
            }
            $target = $this->findOrCreateStock($targetWarehouseId, $productId);
            $source->addQty((string) (-$amount));
            $target->addQty((string) $amount);
            $affected[] = $target;
        } elseif ($type === 'in') {
            $source->addQty((string) $amount);
        } elseif ($type === 'out') {
            $source->addQty((string) (-$amount));
        } else { // adjust — qty is absolute target
            $source->setQty((string) ((float) $qty));
        }

        $movement = new StockMovement($warehouseId, $productId, $type, (string) $amount, $createdBy);
        if ($type === 'transfer') {
            $movement->setTargetWarehouseId($targetWarehouseId);
        }
        $movement->setNote($note);
        $this->em->persist($movement);
        $this->em->flush();

        $payload = $this->serializeMovement($movement);
        $this->events->emit('stock.movement_created', ['type' => 'stock_movement', 'id' => $movement->id()], $payload);

        foreach ($affected as $item) {
            if ($item->isLow()) {
                $this->events->emit('stock.low', ['type' => 'stock_item', 'id' => $item->id()], [
                    'warehouse_id' => $item->warehouseId(),
                    'product_id' => $item->productId(),
                    'qty' => $item->qty(),
                    'threshold' => StockItem::LOW_THRESHOLD,
                ]);
            }
        }

        return ['movement' => $movement, 'items' => $affected];
    }

    private function findOrCreateStock(string $warehouseId, string $productId): StockItem
    {
        $item = $this->em->getRepository(StockItem::class)->findOneBy([
            'warehouseId' => $warehouseId,
            'productId' => $productId,
        ]);
        if ($item instanceof StockItem) {
            return $item;
        }

        $item = new StockItem($warehouseId, $productId, '0');
        $this->em->persist($item);

        return $item;
    }

    /** @return array<string, mixed> */
    public function serializeMovement(StockMovement $m): array
    {
        return [
            'id' => $m->id(),
            'warehouse_id' => $m->warehouseId(),
            'product_id' => $m->productId(),
            'type' => $m->type(),
            'qty' => $m->qty(),
            'target_warehouse_id' => $m->targetWarehouseId(),
            'note' => $m->note(),
            'created_by' => $m->createdBy(),
            'created_at' => $m->createdAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function serializeStock(StockItem $item): array
    {
        return [
            'id' => $item->id(),
            'warehouse_id' => $item->warehouseId(),
            'product_id' => $item->productId(),
            'qty' => $item->qty(),
            'is_low' => $item->isLow(),
            'updated_at' => $item->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function serializeWarehouse(Warehouse $w): array
    {
        return [
            'id' => $w->id(),
            'code' => $w->code(),
            'name' => $w->name(),
            'active' => $w->isActive(),
            'created_at' => $w->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $w->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
