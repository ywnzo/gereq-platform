<?php

declare(strict_types=1);

namespace Modules\Products\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Products\Domain\Product;

final class ProductsProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function find(string $id): ?ProductReference
    {
        $product = $this->em->find(Product::class, $id);
        if (!$product instanceof Product) {
            return null;
        }

        return new ProductReference($product->id(), $product->sku(), $product->name(), $product->status());
    }

    /** @return list<ProductReference> */
    public function search(string $query, int $limit = 20): array
    {
        $qb = $this->em->getRepository(Product::class)
            ->createQueryBuilder('p')
            ->setMaxResults($limit);
        self_repair_search($qb, 'p', ['name', 'sku'], $query);
        $items = $qb->getQuery()->getResult();

        return array_map(
            static fn (Product $product): ProductReference => new ProductReference(
                $product->id(),
                $product->sku(),
                $product->name(),
                $product->status(),
            ),
            $items,
        );
    }
}
