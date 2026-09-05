<?php

declare(strict_types=1);

namespace Modules\Feeds\Application\Drivers;

use Modules\Feeds\Domain\FeedDriverInterface;

final class HeurekaDriver implements FeedDriverInterface
{
    public function key(): string
    {
        return 'heureka';
    }

    public function contentType(): string
    {
        return 'application/xml; charset=UTF-8';
    }

    public function render(array $config, array $products): string
    {
        $items = '';
        foreach ($products as $product) {
            $items .= $this->shopItem($product);
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<SHOP>
{$items}</SHOP>

XML;
    }

    /** @param array<string, mixed> $product */
    private function shopItem(array $product): string
    {
        $id = $this->e((string) ($product['id'] ?? $product['sku'] ?? ''));
        $title = $this->e((string) ($product['title'] ?? $product['name'] ?? ''));
        $description = $this->e((string) ($product['description'] ?? ''));
        $url = $this->e((string) ($product['link'] ?? $product['url'] ?? ''));
        $img = $this->e((string) ($product['image_link'] ?? $product['imgurl'] ?? ''));
        $price = $this->e((string) ($product['price'] ?? $product['price_vat'] ?? ''));
        $availability = $this->e((string) ($product['availability'] ?? '0'));
        $manufacturer = $this->e((string) ($product['brand'] ?? $product['manufacturer'] ?? ''));

        return <<<XML
  <SHOPITEM>
    <ITEM_ID>{$id}</ITEM_ID>
    <PRODUCTNAME>{$title}</PRODUCTNAME>
    <DESCRIPTION>{$description}</DESCRIPTION>
    <URL>{$url}</URL>
    <IMGURL>{$img}</IMGURL>
    <PRICE_VAT>{$price}</PRICE_VAT>
    <DELIVERY_DATE>{$availability}</DELIVERY_DATE>
    <MANUFACTURER>{$manufacturer}</MANUFACTURER>
  </SHOPITEM>

XML;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
