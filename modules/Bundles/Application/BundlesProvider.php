<?php

declare(strict_types=1);

namespace Modules\Bundles\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Bundles\Domain\ProductBundle;
use Modules\Bundles\Domain\ProductBundleItem;

final class BundlesProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function find(string $id): ?ProductBundle
    {
        $bundle = $this->em->find(ProductBundle::class, $id);

        return $bundle instanceof ProductBundle ? $bundle : null;
    }

    public function findByCode(string $code): ?ProductBundle
    {
        $bundle = $this->em->getRepository(ProductBundle::class)->findOneBy([
            'code' => strtoupper(trim($code)),
        ]);

        return $bundle instanceof ProductBundle ? $bundle : null;
    }

    /** @return list<ProductBundle> */
    public function activeBundles(): array
    {
        return $this->em->getRepository(ProductBundle::class)->findBy(
            ['active' => true],
            ['code' => 'ASC'],
        );
    }

    /** @return array<string, mixed>|null */
    public function resolve(string $bundleId): ?array
    {
        $bundle = $this->find($bundleId);
        if ($bundle === null || !$bundle->isActive()) {
            return null;
        }

        /** @var list<ProductBundleItem> $items */
        $items = $this->em->getRepository(ProductBundleItem::class)->findBy(
            ['bundleId' => $bundle->id()],
            ['sortOrder' => 'ASC'],
        );

        return [
            'id' => $bundle->id(),
            'code' => $bundle->code(),
            'name' => $bundle->name(),
            'description' => $bundle->description(),
            'discount_type' => $bundle->discountType(),
            'discount_value' => $bundle->discountValue(),
            'currency' => $bundle->currency(),
            'min_items' => $bundle->minItems(),
            'max_items' => $bundle->maxItems(),
            'max_same_product' => $bundle->maxSameProduct(),
            'items' => array_map(static fn (ProductBundleItem $item): array => [
                'id' => $item->id(),
                'product_id' => $item->productId(),
                'title' => $item->title(),
                'qty' => $item->qty(),
                'sort_order' => $item->sortOrder(),
            ], $items),
        ];
    }
}
