<?php

declare(strict_types=1);

namespace Modules\WebPlus\Application\DynamicMeta;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Hook\HookManager;
use Gereq\Setting\Settings;
use Modules\Cms\Domain\Page;
use Modules\Listings\Domain\Listing;
use Modules\WebPlus\Application\PathPattern;
use Modules\WebPlus\Domain\WebMeta;

/**
 * Resolves SEO/GEO/OG from live entities (listings, CMS) and `webplus.dynamic_meta` hook.
 */
final class DynamicMetaResolver
{
    private const RESERVED = ['nabidky', 'stranka', 'api', 'admin', 'media', 'assets'];

    /** @var array<string, Listing|false> */
    private array $listingBySlug = [];

    /** @var array<string, Page|false> */
    private array $pageBySlug = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly HookManager $hooks,
    ) {
    }

    public function listingsPathTemplate(): string
    {
        $tpl = trim((string) $this->settings->get('webplus.google_feed_listings_path', '/nabidky/{slug}'));

        return $tpl !== '' ? WebMeta::normalizePath($tpl) : '/nabidky/{slug}';
    }

    public function cmsPathTemplate(): string
    {
        $tpl = trim((string) $this->settings->get('webplus.cms_pages_path', '/stranka/{slug}'));

        return $tpl !== '' ? WebMeta::normalizePath($tpl) : '/stranka/{slug}';
    }

    /**
     * @param array<string, string> $params
     */
    public function resolve(string $path, array $params = []): ?DynamicMetaPayload
    {
        $path = WebMeta::normalizePath($path);
        $params = $params + PathPattern::segmentVars($path);

        $payload = $this->fromListing($path, $params)
            ?? $this->fromCms($path, $params);

        /** @var mixed $filtered */
        $filtered = $this->hooks->filter('webplus.dynamic_meta', $payload, $path, $params);
        if ($filtered instanceof DynamicMetaPayload) {
            return $filtered;
        }

        return $payload;
    }

    /**
     * Prefetch CMS/listing rows for many paths (one IN query each).
     *
     * @param list<string> $paths
     */
    public function warmForPaths(array $paths): void
    {
        $cmsSlugs = [];
        $listingSlugs = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $path = WebMeta::normalizePath($path);
            $params = PathPattern::segmentVars($path);
            $cms = $this->cmsSlug($path, $params);
            if ($cms !== null && $cms !== '' && !in_array($cms, self::RESERVED, true)) {
                $cmsSlugs[$cms] = true;
            }
            $listing = $this->listingSlug($path, $params);
            if ($listing !== null && $listing !== '') {
                $listingSlugs[$listing] = true;
            }
        }
        $this->warmPagesBySlugs(array_keys($cmsSlugs));
        $this->warmListingsBySlugs(array_keys($listingSlugs));
    }

    /**
     * @return list<array{path: string, lastmod: string, priority: string, changefreq: string}>
     */
    public function sitemapEntries(): array
    {
        $entries = array_merge($this->listingSitemapEntries(), $this->cmsSitemapEntries());

        /** @var mixed $filtered */
        $filtered = $this->hooks->filter('webplus.dynamic_sitemap', $entries);
        if (!is_array($filtered)) {
            return $entries;
        }

        $out = [];
        foreach ($filtered as $row) {
            if (!is_array($row) || !is_string($row['path'] ?? null) || $row['path'] === '') {
                continue;
            }
            $out[] = [
                'path' => WebMeta::normalizePath((string) $row['path']),
                'lastmod' => is_string($row['lastmod'] ?? null) ? $row['lastmod'] : date('Y-m-d'),
                'priority' => is_string($row['priority'] ?? null) ? $row['priority'] : '0.6',
                'changefreq' => is_string($row['changefreq'] ?? null) ? $row['changefreq'] : 'weekly',
            ];
        }

        return $out;
    }

    /**
     * @param array<string, string> $params
     */
    private function fromListing(string $path, array $params): ?DynamicMetaPayload
    {
        if (!class_exists(Listing::class)) {
            return null;
        }

        $slug = $this->listingSlug($path, $params);
        if ($slug === null) {
            return null;
        }

        $listing = $this->listingBySlug($slug);
        if ($listing === null || $listing->status() !== 'published') {
            return null;
        }

        $title = $listing->title();
        $city = $listing->city();
        $price = $this->formatPrice((float) $listing->price(), $listing->currency(), $listing->offerType());
        $excerpt = $this->excerpt($listing->description())
            ?? trim(implode(' · ', array_filter([$city, $price], static fn (string $v): bool => $v !== '')));
        $excerpt = $excerpt !== '' ? $excerpt : null;
        $image = $listing->coverImageUrl() ?: ($listing->images()[0] ?? null);
        $placename = $this->listingPlacename($listing);
        $position = $listing->latitude() !== null && $listing->longitude() !== null
            ? $listing->latitude() . ';' . $listing->longitude()
            : null;
        $summary = $this->excerpt($listing->description(), 400)
            ?? ($title . ($city !== '' ? ' — ' . $city : ''));

        $vars = array_merge($params, [
            'title' => $title,
            'slug' => $listing->slug(),
            'city' => $city,
            'city_part' => (string) ($listing->cityPart() ?? ''),
            'street' => (string) ($listing->street() ?? ''),
            'price' => $price,
            'description' => (string) $excerpt,
            'offer' => $listing->offerType() === 'rent' ? 'Pronájem' : 'Prodej',
        ]);

        return new DynamicMetaPayload(
            source: 'listings',
            path: $path,
            title: $title,
            description: $excerpt,
            image: is_string($image) && $image !== '' ? $image : null,
            geoRegion: $city !== '' || $position !== null ? 'CZ' : null,
            geoPlacename: $placename,
            geoPosition: $position,
            aiSummary: $summary,
            jsonLd: $this->listingJsonLd($listing, $path, $excerpt, $image),
            vars: $vars,
            ogType: 'website',
            updatedAt: $listing->updatedAt(),
        );
    }

    /**
     * @param array<string, string> $params
     */
    private function fromCms(string $path, array $params): ?DynamicMetaPayload
    {
        if (!class_exists(Page::class)) {
            return null;
        }

        $slug = $this->cmsSlug($path, $params);
        if ($slug === null || in_array($slug, self::RESERVED, true)) {
            return null;
        }

        $page = $this->pageBySlug($slug);
        if ($page === null || $page->status() !== 'published') {
            return null;
        }

        $title = $page->title();
        $description = $this->excerpt($page->metaDescription());
        $vars = array_merge($params, [
            'title' => $title,
            'slug' => $page->slug(),
            'description' => (string) $description,
        ]);

        return new DynamicMetaPayload(
            source: 'cms',
            path: $path,
            title: $title,
            description: $description,
            jsonLd: [
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => $title,
                'description' => $description,
                'url' => $this->absolutePath($path),
            ],
            vars: $vars,
            updatedAt: $page->updatedAt(),
        );
    }

    /**
     * Seed request memo so a later pageBySlug() does not re-query (Doctrine
     * identity map is by id, not slug).
     */
    public function primePage(Page $page): void
    {
        $this->pageBySlug[strtolower($page->slug())] = $page;
    }

    private function listingBySlug(string $slug): ?Listing
    {
        if (array_key_exists($slug, $this->listingBySlug)) {
            $hit = $this->listingBySlug[$slug];

            return $hit instanceof Listing ? $hit : null;
        }

        try {
            $listing = $this->em->getRepository(Listing::class)->findOneBy(['slug' => $slug]);
        } catch (\Throwable) {
            $this->listingBySlug[$slug] = false;

            return null;
        }
        $this->listingBySlug[$slug] = $listing instanceof Listing ? $listing : false;

        return $listing instanceof Listing ? $listing : null;
    }

    private function pageBySlug(string $slug): ?Page
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return null;
        }

        if (array_key_exists($slug, $this->pageBySlug)) {
            $hit = $this->pageBySlug[$slug];

            return $hit instanceof Page ? $hit : null;
        }

        $page = $this->em->getRepository(Page::class)->findOneBy(['slug' => $slug]);
        $this->pageBySlug[$slug] = $page instanceof Page ? $page : false;

        return $page instanceof Page ? $page : null;
    }

    /**
     * @param list<string> $slugs
     */
    private function warmPagesBySlugs(array $slugs): void
    {
        if (!class_exists(Page::class)) {
            return;
        }
        $missing = [];
        foreach ($slugs as $slug) {
            if (!is_string($slug) || $slug === '') {
                continue;
            }
            $key = strtolower($slug);
            if (!array_key_exists($key, $this->pageBySlug)) {
                $missing[] = $key;
            }
        }
        if ($missing === []) {
            return;
        }

        try {
            /** @var list<Page> $pages */
            $pages = $this->em->getRepository(Page::class)->findBy(['slug' => $missing]);
        } catch (\Throwable) {
            foreach ($missing as $slug) {
                $this->pageBySlug[$slug] = false;
            }

            return;
        }

        $found = [];
        foreach ($pages as $page) {
            $found[strtolower($page->slug())] = $page;
        }
        foreach ($missing as $slug) {
            $key = strtolower($slug);
            $this->pageBySlug[$key] = $found[$key] ?? false;
        }
    }

    /**
     * @param list<string> $slugs
     */
    private function warmListingsBySlugs(array $slugs): void
    {
        if (!class_exists(Listing::class)) {
            return;
        }
        $missing = [];
        foreach ($slugs as $slug) {
            if (is_string($slug) && $slug !== '' && !array_key_exists($slug, $this->listingBySlug)) {
                $missing[] = $slug;
            }
        }
        if ($missing === []) {
            return;
        }

        try {
            /** @var list<Listing> $listings */
            $listings = $this->em->getRepository(Listing::class)->findBy(['slug' => $missing]);
        } catch (\Throwable) {
            foreach ($missing as $slug) {
                $this->listingBySlug[$slug] = false;
            }

            return;
        }

        $found = [];
        foreach ($listings as $listing) {
            $found[$listing->slug()] = $listing;
        }
        foreach ($missing as $slug) {
            $this->listingBySlug[$slug] = $found[$slug] ?? false;
        }
    }

    /**
     * @param array<string, string> $params
     */
    private function listingSlug(string $path, array $params): ?string
    {
        $matched = PathPattern::match($this->listingsPathTemplate(), $path);
        if ($matched !== null && isset($matched['slug']) && $matched['slug'] !== '') {
            return $matched['slug'];
        }
        if (isset($params['slug']) && $params['slug'] !== '' && str_starts_with($path, '/nabidky/')) {
            return $params['slug'];
        }
        if (($params['page'] ?? '') === 'nabidky' && isset($params['x']) && $params['x'] !== '') {
            return $params['x'];
        }

        return null;
    }

    /**
     * @param array<string, string> $params
     */
    private function cmsSlug(string $path, array $params): ?string
    {
        $matched = PathPattern::match($this->cmsPathTemplate(), $path);
        if ($matched !== null && isset($matched['slug']) && $matched['slug'] !== '') {
            return $matched['slug'];
        }
        if (preg_match('#^/stranka/([^/]+)$#', $path, $m) === 1) {
            return rawurldecode($m[1]);
        }
        if (preg_match('#^/([^/]+)$#', $path, $m) === 1) {
            return rawurldecode($m[1]);
        }

        return $params['slug'] ?? null;
    }

    /**
     * @return list<array{path: string, lastmod: string, priority: string, changefreq: string}>
     */
    private function listingSitemapEntries(): array
    {
        if (!class_exists(Listing::class)) {
            return [];
        }

        try {
            /** @var list<Listing> $listings */
            $listings = $this->em->getRepository(Listing::class)->findBy(
                ['status' => 'published'],
                ['updatedAt' => 'DESC'],
                5000,
            );
        } catch (\Throwable) {
            return [];
        }

        $tpl = $this->listingsPathTemplate();
        $out = [];
        foreach ($listings as $listing) {
            $path = PathPattern::interpolate($tpl, [
                'slug' => $listing->slug(),
                'id' => $listing->id(),
            ]);
            $out[] = [
                'path' => WebMeta::normalizePath($path),
                'lastmod' => $listing->updatedAt()->format('Y-m-d'),
                'priority' => $listing->featured() ? '0.8' : '0.7',
                'changefreq' => 'weekly',
            ];
        }

        return $out;
    }

    /**
     * @return list<array{path: string, lastmod: string, priority: string, changefreq: string}>
     */
    private function cmsSitemapEntries(): array
    {
        if (!class_exists(Page::class)) {
            return [];
        }

        try {
            /** @var list<Page> $pages */
            $pages = $this->em->getRepository(Page::class)->findBy(
                ['status' => 'published'],
                ['updatedAt' => 'DESC'],
                2000,
            );
        } catch (\Throwable) {
            return [];
        }

        $tpl = $this->cmsPathTemplate();
        $out = [];
        foreach ($pages as $page) {
            $slug = $page->slug();
            if ($slug === 'home' || $slug === 'uvod') {
                continue;
            }
            $path = PathPattern::interpolate($tpl, ['slug' => $slug, 'id' => $page->id()]);
            $out[] = [
                'path' => WebMeta::normalizePath($path),
                'lastmod' => $page->updatedAt()->format('Y-m-d'),
                'priority' => '0.5',
                'changefreq' => 'weekly',
            ];
        }

        return $out;
    }

    private function listingPlacename(Listing $listing): ?string
    {
        $parts = array_values(array_filter([
            $listing->street(),
            $listing->cityPart(),
            $listing->city(),
        ], static fn (?string $v): bool => is_string($v) && $v !== ''));

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function listingJsonLd(Listing $listing, string $path, ?string $excerpt, ?string $image): array
    {
        $availability = match ($listing->marketStatus()) {
            'sold', 'rented' => 'https://schema.org/SoldOut',
            'reserved' => 'https://schema.org/PreOrder',
            default => 'https://schema.org/InStock',
        };
        $address = array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $listing->street(),
            'addressLocality' => $listing->city(),
            'addressRegion' => $listing->cityPart(),
            'postalCode' => $listing->zip(),
            'addressCountry' => 'CZ',
        ], static fn (mixed $v): bool => $v !== null && $v !== '');

        $about = array_filter([
            '@type' => $listing->propertyType() === 'land' ? 'Landform' : 'Accommodation',
            'name' => $listing->title(),
            'numberOfRooms' => $listing->rooms(),
            'address' => $address !== [] ? $address : null,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');

        if ($listing->areaM2() !== null && $listing->areaM2() > 0) {
            $about['floorSize'] = [
                '@type' => 'QuantitativeValue',
                'value' => $listing->areaM2(),
                'unitCode' => 'MTK',
            ];
        }
        if ($listing->latitude() !== null && $listing->longitude() !== null) {
            $about['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => $listing->latitude(),
                'longitude' => $listing->longitude(),
            ];
        }

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'RealEstateListing',
            'name' => $listing->title(),
            'description' => $excerpt,
            'url' => $this->absolutePath($path),
            'datePosted' => $listing->publishedAt()?->format('Y-m-d'),
            'offers' => [
                '@type' => 'Offer',
                'price' => number_format((float) $listing->price(), 2, '.', ''),
                'priceCurrency' => strtoupper($listing->currency() ?: 'CZK'),
                'availability' => $availability,
            ],
            'about' => $about,
        ];
        if (is_string($image) && $image !== '') {
            $data['image'] = $this->absoluteMedia($image);
        }

        return array_filter($data, static fn (mixed $v): bool => $v !== null && $v !== '');
    }

    private function formatPrice(float $price, string $currency, string $offerType): string
    {
        $formatted = number_format($price, 0, ',', ' ') . ' ' . strtoupper($currency ?: 'CZK');

        return $offerType === 'rent' ? $formatted . ' / měs.' : $formatted;
    }

    private function excerpt(?string $text, int $max = 155): ?string
    {
        $text = trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }

    private function publicBase(): string
    {
        $fe = rtrim((string) $this->settings->get('webplus.frontend_url', ''), '/');
        if ($fe !== '') {
            return $fe;
        }

        return rtrim((string) $this->settings->get('webplus.base_url', ''), '/');
    }

    private function absolutePath(string $path): ?string
    {
        $base = $this->publicBase();
        if ($base === '') {
            return $path;
        }

        return $base . ($path === '/' ? '' : $path);
    }

    private function absoluteMedia(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (preg_match('#^https?://(?:127\.0\.0\.1|localhost|0\.0\.0\.0|\[::1\])(?::\d+)?(/.*)$#i', $url, $m) === 1) {
            $url = $m[1];
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        $base = $this->publicBase();
        if ($base === '') {
            return $url;
        }

        return $base . (str_starts_with($url, '/') ? $url : '/' . $url);
    }
}
