<?php

declare(strict_types=1);

namespace Modules\Warehouse\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Warehouse\Domain\StockItem;

final class InventoryProvider
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function qtyForProduct(string $productId, ?string $warehouseId = null): string
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(s.qty), 0)')
            ->from(StockItem::class, 's')
            ->where('s.productId = :pid')
            ->setParameter('pid', $productId);

        if ($warehouseId !== null && $warehouseId !== '') {
            $qb->andWhere('s.warehouseId = :wid')->setParameter('wid', $warehouseId);
        }

        return number_format((float) $qb->getQuery()->getSingleScalarResult(), 4, '.', '');
    }

    /** @return list<StockItem> */
    public function stockForProduct(string $productId): array
    {
        return $this->em->getRepository(StockItem::class)->findBy(
            ['productId' => $productId],
            ['warehouseId' => 'ASC'],
        );
    }
}
