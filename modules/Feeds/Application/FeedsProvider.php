<?php

declare(strict_types=1);

namespace Modules\Feeds\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Feeds\Application\Drivers\CustomDriver;
use Modules\Feeds\Application\Drivers\GoogleMerchantDriver;
use Modules\Feeds\Application\Drivers\HeurekaDriver;
use Modules\Feeds\Domain\FeedDefinition;
use Modules\Feeds\Domain\FeedDriverInterface;
use Modules\Products\Domain\Product;
use Modules\Products\Domain\ProductPrice;

final class FeedsProvider
{
    /** @var array<string, FeedDriverInterface> */
    private array $drivers;

    public function __construct(
        private readonly EntityManagerInterface $em,
        GoogleMerchantDriver $googleMerchant,
        HeurekaDriver $heureka,
        CustomDriver $custom,
    ) {
        $this->drivers = [
            $googleMerchant->key() => $googleMerchant,
            $heureka->key() => $heureka,
            $custom->key() => $custom,
        ];
    }

    public function find(string $id): ?FeedDefinition
    {
        $feed = $this->em->find(FeedDefinition::class, $id);

        return $feed instanceof FeedDefinition ? $feed : null;
    }

    public function findByCode(string $code): ?FeedDefinition
    {
        $feed = $this->em->getRepository(FeedDefinition::class)->findOneBy([
            'code' => strtolower(trim($code)),
        ]);

        return $feed instanceof FeedDefinition ? $feed : null;
    }

    /**
     * @return array{content_type: string, body: string}|null
     */
    public function generate(string $code): ?array
    {
        $feed = $this->findByCode($code);
        if ($feed === null || !$feed->isActive()) {
            return null;
        }

        $driver = $this->drivers[$feed->driver()] ?? null;
        if ($driver === null) {
            return null;
        }

        $products = $this->collectProducts($feed->config());
        $body = $driver->render($feed->config(), $products);

        return [
            'content_type' => $driver->contentType(),
            'body' => $body,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function collectProducts(array $config): array
    {
        $currency = strtoupper((string) ($config['currency'] ?? 'CZK'));
        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');

        /** @var list<Product> $products */
        $products = $this->em->getRepository(Product::class)->findBy(
            ['status' => 'active'],
            ['name' => 'ASC'],
            (int) ($config['limit'] ?? 5000),
        );

        $rows = [];
        foreach ($products as $product) {
            $price = $this->priceFor($product->id(), $currency);
            $rows[] = [
                'id' => $product->id(),
                'sku' => $product->sku(),
                'title' => $product->name(),
                'name' => $product->name(),
                'description' => $product->description() ?? '',
                'link' => $baseUrl !== '' ? $baseUrl . '/p/' . $product->sku() : '',
                'url' => $baseUrl !== '' ? $baseUrl . '/p/' . $product->sku() : '',
                'image_link' => '',
                'imgurl' => '',
                'price' => $price !== null ? $price . ' ' . $currency : '',
                'price_vat' => $price ?? '',
                'availability' => 'in_stock',
                'brand' => (string) ($config['default_brand'] ?? ''),
                'manufacturer' => (string) ($config['default_brand'] ?? ''),
            ];
        }

        return $rows;
    }

    private function priceFor(string $productId, string $currency): ?string
    {
        $price = $this->em->getRepository(ProductPrice::class)->findOneBy([
            'productId' => $productId,
            'currencyCode' => $currency,
        ]);

        if (!$price instanceof ProductPrice) {
            $price = $this->em->getRepository(ProductPrice::class)->findOneBy(['productId' => $productId]);
        }

        return $price instanceof ProductPrice ? number_format((float) $price->amount(), 2, '.', '') : null;
    }
}
