<?php

declare(strict_types=1);

namespace Modules\Feeds\Application\Drivers;

use Modules\Feeds\Domain\FeedDriverInterface;

final class GoogleMerchantDriver implements FeedDriverInterface
{
    public function key(): string
    {
        return 'google_merchant';
    }

    public function contentType(): string
    {
        return 'application/xml; charset=UTF-8';
    }

    public function render(array $config, array $products): string
    {
        $title = htmlspecialchars((string) ($config['title'] ?? 'Product feed'), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $link = htmlspecialchars((string) ($config['link'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars((string) ($config['description'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $items = '';
        foreach ($products as $product) {
            $items .= $this->item($product);
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">
  <channel>
    <title>{$title}</title>
    <link>{$link}</link>
    <description>{$description}</description>
{$items}  </channel>
</rss>

XML;
    }

    /** @param array<string, mixed> $product */
    private function item(array $product): string
    {
        $id = $this->e((string) ($product['id'] ?? $product['sku'] ?? ''));
        $title = $this->e((string) ($product['title'] ?? $product['name'] ?? ''));
        $description = $this->e((string) ($product['description'] ?? ''));
        $link = $this->e((string) ($product['link'] ?? ''));
        $image = $this->e((string) ($product['image_link'] ?? ''));
        $price = $this->e((string) ($product['price'] ?? ''));
        $availability = $this->e((string) ($product['availability'] ?? 'in_stock'));
        $brand = $this->e((string) ($product['brand'] ?? ''));

        return <<<XML
    <item>
      <g:id>{$id}</g:id>
      <g:title>{$title}</g:title>
      <g:description>{$description}</g:description>
      <g:link>{$link}</g:link>
      <g:image_link>{$image}</g:image_link>
      <g:price>{$price}</g:price>
      <g:availability>{$availability}</g:availability>
      <g:brand>{$brand}</g:brand>
    </item>

XML;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
