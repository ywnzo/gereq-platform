<?php

declare(strict_types=1);

namespace Gereq\Publish\Adapter;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Publish\ChangeCard;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\PublishableAdapterInterface;
use Gereq\Publish\StagingStore;
use Modules\Products\Domain\Product;
use Modules\Products\Domain\ProductImage;
use Modules\Products\Domain\ProductPrice;

final class ProductPublishAdapter implements PublishableAdapterInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StagingStore $store,
    ) {
    }

    public function settingsKeys(): array
    {
        return [];
    }

    public function snapshot(array $resourceKeys): array
    {
        $out = [];
        foreach ($resourceKeys as $key) {
            $entry = $this->store->find('products', $key);
            if ($entry !== null) {
                $out[$key] = $entry->payload()['production_snapshot'] ?? null;
                continue;
            }
            $product = $this->em->find(Product::class, $key);
            if ($product instanceof Product) {
                $out[$key] = self::capture($product);
            }
        }

        return $out;
    }

    public function apply(ContentStagingEntry $entry): void
    {
        if ($entry->action() === ContentStagingEntry::ACTION_DELETE) {
            $this->hardDelete($entry->resourceKey());

            return;
        }

        if ($entry->action() === ContentStagingEntry::ACTION_CREATE) {
            $product = $this->em->find(Product::class, $entry->resourceKey());
            if ($product instanceof Product && $product->status() !== 'active') {
                $product->setStatus('active');
                $this->em->flush();
            }
        }
    }

    public function describe(ContentStagingEntry $entry): ChangeCard
    {
        $snap = $entry->payload()['production_snapshot'] ?? [];
        $name = is_array($snap) ? (string) ($snap['name'] ?? $entry->label()) : $entry->label();
        if ($name === '') {
            $name = $entry->label();
        }
        $sku = is_array($snap) ? (string) ($snap['sku'] ?? '') : '';

        if ($entry->action() === ContentStagingEntry::ACTION_DELETE) {
            return new ChangeCard('Smazat: ' . $name, $sku);
        }
        if ($entry->action() === ContentStagingEntry::ACTION_CREATE) {
            return new ChangeCard('Nové: ' . $name, $sku);
        }

        return new ChangeCard($name, $sku);
    }

    private function hardDelete(string $productId): void
    {
        foreach ($this->em->getRepository(ProductPrice::class)->findBy(['productId' => $productId]) as $price) {
            $this->em->remove($price);
        }
        foreach ($this->em->getRepository(ProductImage::class)->findBy(['productId' => $productId]) as $image) {
            $this->em->remove($image);
        }
        $product = $this->em->find(Product::class, $productId);
        if ($product instanceof Product) {
            $product->clearCategories();
            $this->em->remove($product);
        }
        $this->em->flush();
    }

    /** @return array<string, mixed> */
    public static function capture(Product $product): array
    {
        return [
            'id' => $product->id(),
            'sku' => $product->sku(),
            'name' => $product->name(),
            'description' => $product->description(),
            'status' => $product->status(),
            'unit' => $product->unit(),
            'category_ids' => array_map(
                static fn ($c): string => $c->id(),
                $product->categories()->toArray(),
            ),
        ];
    }
}
