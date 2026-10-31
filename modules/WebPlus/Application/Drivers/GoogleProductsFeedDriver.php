<?php

declare(strict_types=1);

namespace Modules\WebPlus\Application\Drivers;

/**
 * Google Merchant Center / Google Products RSS XML (g: namespace).
 */
final class GoogleProductsFeedDriver
{
    public function contentType(): string
    {
        return 'application/xml; charset=UTF-8';
    }

    /**
     * @param array<string, mixed> $channel
     * @param list<array<string, mixed>> $products
     */
    public function render(array $channel, array $products): string
    {
        $title = $this->e((string) ($channel['title'] ?? 'Product feed'));
        $link = $this->e((string) ($channel['link'] ?? ''));
        $description = $this->e((string) ($channel['description'] ?? ''));

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
        $condition = $this->e((string) ($product['condition'] ?? 'new'));
        $gtin = $this->e((string) ($product['gtin'] ?? ''));
        $mpn = $this->e((string) ($product['mpn'] ?? ''));

        $extra = '';
        if ($gtin !== '') {
            $extra .= "      <g:gtin>{$gtin}</g:gtin>\n";
        }
        if ($mpn !== '') {
            $extra .= "      <g:mpn>{$mpn}</g:mpn>\n";
        }

        return <<<XML
    <item>
      <g:id>{$id}</g:id>
      <g:title>{$title}</g:title>
      <g:description>{$description}</g:description>
      <g:link>{$link}</g:link>
      <g:image_link>{$image}</g:image_link>
      <g:condition>{$condition}</g:condition>
      <g:availability>{$availability}</g:availability>
      <g:price>{$price}</g:price>
      <g:brand>{$brand}</g:brand>
{$extra}    </item>

XML;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
