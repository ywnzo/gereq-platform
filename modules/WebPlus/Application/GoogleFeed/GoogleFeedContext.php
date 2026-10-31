<?php

declare(strict_types=1);

namespace Modules\WebPlus\Application\GoogleFeed;

/**
 * Context for collecting Google Merchant feed items.
 *
 * @phpstan-type FeedItem array{
 *   id: string,
 *   sku?: string,
 *   title: string,
 *   description?: string,
 *   link: string,
 *   image_link?: string,
 *   price: string,
 *   availability?: string,
 *   brand?: string,
 *   condition?: string,
 *   gtin?: string,
 *   mpn?: string,
 *   source?: string
 * }
 * @phpstan-type FeedSource array{
 *   id: string,
 *   label: string,
 *   default_path?: string,
 *   module?: string
 * }
 */
final class GoogleFeedContext
{
    /**
     * @param list<string> $enabledSources
     * @param array<string, string> $paths source id => URL path template
     */
    public function __construct(
        public readonly array $enabledSources,
        public readonly string $baseUrl,
        public readonly string $currency,
        public readonly string $brand,
        public readonly int $limit,
        public readonly array $paths,
    ) {
    }

    public function isEnabled(string $sourceId): bool
    {
        if ($this->enabledSources === [] || in_array('*', $this->enabledSources, true)) {
            return true;
        }

        return in_array($sourceId, $this->enabledSources, true);
    }

    public function pathTemplate(string $sourceId, string $fallback = '/{id}'): string
    {
        $tpl = trim($this->paths[$sourceId] ?? '');

        return $tpl !== '' ? $tpl : $fallback;
    }

    /**
     * @param array<string, string|int|float|null> $vars
     */
    public function buildLink(string $sourceId, array $vars, string $fallbackPath = '/{id}'): string
    {
        if ($this->baseUrl === '') {
            return '';
        }

        $path = $this->pathTemplate($sourceId, $fallbackPath);
        foreach ($vars as $key => $value) {
            $path = str_replace('{' . $key . '}', (string) $value, $path);
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return $this->baseUrl . $path;
    }

    public function remaining(int $already): int
    {
        return max(0, $this->limit - $already);
    }

    public function absoluteMedia(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        if ($this->baseUrl === '') {
            return $url;
        }

        return $this->baseUrl . (str_starts_with($url, '/') ? $url : '/' . $url);
    }
}
