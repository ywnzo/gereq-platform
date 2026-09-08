<?php

declare(strict_types=1);

namespace Gereq\Publish\Adapter;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Publish\ChangeCard;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\PublishableAdapterInterface;
use Gereq\Publish\StagingStore;
use Modules\Products\Domain\ProductCategory;

final class ProductCategoryPublishAdapter implements PublishableAdapterInterface
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
            $entry = $this->store->find('products.categories', $key);
            if ($entry !== null) {
                $out[$key] = $entry->payload()['production_snapshot'] ?? null;
                continue;
            }
            $category = $this->em->find(ProductCategory::class, $key);
            if ($category instanceof ProductCategory) {
                $out[$key] = self::capture($category);
            }
        }

        return $out;
    }

    public function apply(ContentStagingEntry $entry): void
    {
        if ($entry->action() === ContentStagingEntry::ACTION_DELETE) {
            $category = $this->em->find(ProductCategory::class, $entry->resourceKey());
            if ($category instanceof ProductCategory) {
                $this->em->remove($category);
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
        $slug = is_array($snap) ? (string) ($snap['slug'] ?? '') : '';

        if ($entry->action() === ContentStagingEntry::ACTION_DELETE) {
            return new ChangeCard('Smazat: ' . $name, $slug);
        }
        if ($entry->action() === ContentStagingEntry::ACTION_CREATE) {
            return new ChangeCard('Nové: ' . $name, $slug);
        }

        return new ChangeCard($name, $slug);
    }

    /** @return array<string, mixed> */
    public static function capture(ProductCategory $category): array
    {
        return [
            'id' => $category->id(),
            'name' => $category->name(),
            'slug' => $category->slug(),
        ];
    }
}
