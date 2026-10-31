<?php

declare(strict_types=1);

namespace Modules\WebPlus\Application\GoogleFeed;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Module\CapabilityRegistry;
use Modules\Listings\Domain\Listing;
use Modules\Shop\Submodules\Products\Domain\Product;
use Modules\Shop\Submodules\Products\Domain\ProductPrice;

/**
 * Built-in collectors (Products + Listings). Other modules add items
 * via hook `webplus.google_feed.items`.
 */
final class BuiltInGoogleFeedCollectors
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CapabilityRegistry $capabilities,
    ) {
    }

    /**
     * @return list<array{id: string, label: string, default_path?: string, module?: string}>
     */
    public function sources(): array
    {
        $sources = [];
        // Only when module is enabled (capability registered) — class_exists alone would leak disabled modules.
        if ($this->capabilities->has('products')) {
            $sources[] = [
                'id' => 'products',
                'label' => 'Products',
                'default_path' => '/p/{sku}',
                'module' => 'products',
            ];
        }
        if ($this->capabilities->has('listings')) {
            $sources[] = [
                'id' => 'listings',
                'label' => 'Listings (reality)',
                'default_path' => '/nabidky/{slug}',
                'module' => 'listings',
            ];
        }

        return $sources;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public function collect(array $items, GoogleFeedContext $ctx): array
    {
        if ($ctx->isEnabled('products') && $this->capabilities->has('products')) {
            $items = array_merge($items, $this->products($ctx, $ctx->remaining(count($items))));
        }
        if ($ctx->isEnabled('listings') && $this->capabilities->has('listings')) {
            $items = array_merge($items, $this->listings($ctx, $ctx->remaining(count($items))));
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function products(GoogleFeedContext $ctx, int $limit): array
    {
        if ($limit < 1 || !$this->capabilities->has('products') || !class_exists(Product::class)) {
            return [];
        }

        try {
            /** @var list<Product> $products */
            $products = $this->em->getRepository(Product::class)->findBy(
                ['status' => 'active'],
                ['name' => 'ASC'],
                $limit,
            );
        } catch (\Throwable) {
            return [];
        }

        $prices = $this->productPricesByProductId(
            array_map(static fn (Product $p): string => $p->id(), $products),
            $ctx->currency,
        );

        $rows = [];
        foreach ($products as $product) {
            $sku = $product->sku();
            $price = $prices[$product->id()] ?? null;
            $rows[] = [
                'id' => 'products:' . $product->id(),
                'sku' => $sku,
                'title' => $product->name(),
                'description' => $product->description() ?? '',
                'link' => $ctx->buildLink('products', [
                    'sku' => $sku,
                    'id' => $product->id(),
                    'slug' => $sku,
                ], '/p/{sku}'),
                'image_link' => '',
                'price' => $price !== null ? $price . ' ' . $ctx->currency : '',
                'availability' => 'in_stock',
                'brand' => $ctx->brand,
                'condition' => 'new',
                'source' => 'products',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listings(GoogleFeedContext $ctx, int $limit): array
    {
        if ($limit < 1 || !$this->capabilities->has('listings') || !class_exists(Listing::class)) {
            return [];
        }

        try {
            /** @var list<Listing> $listings */
            $listings = $this->em->getRepository(Listing::class)->findBy(
                ['status' => 'published'],
                ['publishedAt' => 'DESC'],
                $limit,
            );
        } catch (\Throwable) {
            return [];
        }

        $rows = [];
        foreach ($listings as $listing) {
            $currency = strtoupper($listing->currency() ?: $ctx->currency);
            $availability = match ($listing->marketStatus()) {
                'sold', 'rented' => 'out_of_stock',
                'reserved' => 'preorder',
                default => 'in_stock',
            };
            $desc = trim((string) ($listing->description() ?? ''));
            if ($desc === '') {
                $desc = $listing->title() . ' — ' . $listing->city();
            }
            $amount = number_format((float) $listing->price(), 2, '.', '');

            $rows[] = [
                'id' => 'listings:' . $listing->id(),
                'sku' => $listing->slug(),
                'title' => $listing->title(),
                'description' => mb_substr($desc, 0, 5000),
                'link' => $ctx->buildLink('listings', [
                    'slug' => $listing->slug(),
                    'id' => $listing->id(),
                    'sku' => $listing->slug(),
                ], '/nabidky/{slug}'),
                'image_link' => $ctx->absoluteMedia($listing->coverImageUrl()),
                'price' => $amount . ' ' . $currency,
                'availability' => $availability,
                'brand' => $ctx->brand !== '' ? $ctx->brand : 'RealEstates',
                'condition' => 'used',
                'source' => 'listings',
            ];
        }

        return $rows;
    }

    /**
     * Single IN(...) loads prices for all products (prefers requested currency).
     *
     * @param list<string> $productIds
     * @return array<string, string> productId => amount formatted
     */
    private function productPricesByProductId(array $productIds, string $currency): array
    {
        $productIds = array_values(array_unique(array_filter($productIds, static fn (string $id): bool => $id !== '')));
        if ($productIds === [] || !class_exists(ProductPrice::class)) {
            return [];
        }

        $currency = strtoupper($currency);

        try {
            /** @var list<ProductPrice> $rows */
            $rows = $this->em->createQueryBuilder()
                ->select('pp')
                ->from(ProductPrice::class, 'pp')
                ->where('pp.productId IN (:ids)')
                ->setParameter('ids', $productIds)
                ->getQuery()
                ->getResult();
        } catch (\Throwable) {
            return [];
        }

        $preferred = [];
        $fallback = [];
        foreach ($rows as $price) {
            if (!$price instanceof ProductPrice) {
                continue;
            }
            $formatted = number_format((float) $price->amount(), 2, '.', '');
            $pid = $price->productId();
            if ($price->currencyCode() === $currency) {
                $preferred[$pid] = $formatted;
            } elseif (!isset($fallback[$pid])) {
                $fallback[$pid] = $formatted;
            }
        }

        return $preferred + array_diff_key($fallback, $preferred);
    }
}
