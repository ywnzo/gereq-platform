<?php

declare(strict_types=1);

namespace Modules\WebPlus\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Hook\HookManager;
use Gereq\Module\CapabilityRegistry;
use Gereq\Setting\Settings;
use Modules\WebPlus\Application\Drivers\GoogleProductsFeedDriver;
use Modules\WebPlus\Application\DynamicMeta\DynamicMetaPayload;
use Modules\WebPlus\Application\DynamicMeta\DynamicMetaResolver;
use Modules\WebPlus\Application\GoogleFeed\BuiltInGoogleFeedCollectors;
use Modules\WebPlus\Application\GoogleFeed\GoogleFeedContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Modules\WebPlus\Domain\WebMeta;

final class WebPlusProvider
{
    /** @var list<WebMeta>|null */
    private ?array $templateCache = null;

    /** @var array<string, WebMeta|false> request memo for findByPath */
    private array $pathCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly GoogleProductsFeedDriver $googleFeed,
        private readonly HookManager $hooks,
        private readonly BuiltInGoogleFeedCollectors $googleFeedCollectors,
        private readonly DynamicMetaResolver $dynamic,
        private readonly CapabilityRegistry $capabilities,
        private readonly RequestStack $requests,
    ) {
    }

    public function find(string $id): ?WebMeta
    {
        $meta = $this->em->find(WebMeta::class, $id);

        return $meta instanceof WebMeta ? $meta : null;
    }

    public function findByPath(string $path): ?WebMeta
    {
        $path = WebMeta::normalizePath($path);
        if (array_key_exists($path, $this->pathCache)) {
            $hit = $this->pathCache[$path];

            return $hit instanceof WebMeta ? $hit : null;
        }

        $meta = $this->em->getRepository(WebMeta::class)->findOneBy(['path' => $path]);
        $this->pathCache[$path] = $meta instanceof WebMeta ? $meta : false;

        return $meta instanceof WebMeta ? $meta : null;
    }

    /**
     * @param list<string> $paths
     * @return array<string, WebMeta> keyed by normalized path
     */
    public function findByPaths(array $paths): array
    {
        $normalized = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $normalized[WebMeta::normalizePath($path)] = true;
        }
        $keys = array_keys($normalized);
        if ($keys === []) {
            return [];
        }

        $missing = [];
        foreach ($keys as $path) {
            if (!array_key_exists($path, $this->pathCache)) {
                $missing[] = $path;
            }
        }

        if ($missing !== []) {
            try {
                /** @var list<WebMeta> $rows */
                $rows = $this->em->createQueryBuilder()
                    ->select('m')
                    ->from(WebMeta::class, 'm')
                    ->where('m.path IN (:paths)')
                    ->setParameter('paths', $missing)
                    ->getQuery()
                    ->getResult();
            } catch (\Throwable) {
                $rows = [];
            }

            $found = [];
            foreach ($rows as $meta) {
                if ($meta instanceof WebMeta) {
                    $found[$meta->path()] = $meta;
                }
            }
            foreach ($missing as $path) {
                $this->pathCache[$path] = $found[$path] ?? false;
            }
        }

        $out = [];
        foreach ($keys as $path) {
            $hit = $this->pathCache[$path] ?? false;
            if ($hit instanceof WebMeta) {
                $out[$path] = $hit;
            }
        }

        return $out;
    }

    public function findByCmsPageId(string $pageId): ?WebMeta
    {
        // Prefer canonical `/` when a CMS page was accidentally linked to both `/` and `/home`.
        try {
            /** @var list<WebMeta> $rows */
            $rows = $this->em->createQueryBuilder()
                ->select('m')
                ->from(WebMeta::class, 'm')
                ->where('m.cmsPageId = :id')
                ->setParameter('id', $pageId)
                ->getQuery()
                ->getResult();
        } catch (\Throwable) {
            return null;
        }

        if ($rows === []) {
            return null;
        }

        foreach ($rows as $meta) {
            if ($meta instanceof WebMeta && $meta->path() === '/') {
                return $meta;
            }
        }

        $first = $rows[0];

        return $first instanceof WebMeta ? $first : null;
    }

    /**
     * @param list<string> $pageIds
     * @return array<string, WebMeta> keyed by cmsPageId
     */
    public function findByCmsPageIds(array $pageIds): array
    {
        $ids = array_values(array_unique(array_filter(
            $pageIds,
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        )));
        if ($ids === []) {
            return [];
        }

        try {
            /** @var list<WebMeta> $rows */
            $rows = $this->em->createQueryBuilder()
                ->select('m')
                ->from(WebMeta::class, 'm')
                ->where('m.cmsPageId IN (:ids)')
                ->setParameter('ids', $ids)
                ->getQuery()
                ->getResult();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $meta) {
            if ($meta instanceof WebMeta) {
                $cmsId = $meta->cmsPageId();
                if ($cmsId !== null && $cmsId !== '') {
                    $out[$cmsId] = $meta;
                }
            }
        }

        return $out;
    }

    public function findActiveByPath(string $path): ?WebMeta
    {
        $meta = $this->findByPath($path);
        if ($meta === null || !$meta->isActive()) {
            return null;
        }

        return $meta;
    }

    /**
     * @param list<string> $paths
     * @return array<string, WebMeta> keyed by normalized path (only active)
     */
    public function findActiveByPaths(array $paths): array
    {
        $out = [];
        foreach ($this->findByPaths($paths) as $path => $meta) {
            if ($meta->isActive()) {
                $out[$path] = $meta;
            }
        }

        return $out;
    }

    /** @return list<WebMeta> */
    public function allActiveForSitemap(): array
    {
        /** @var list<WebMeta> $items */
        $items = $this->em->getRepository(WebMeta::class)->findBy(
            ['active' => true, 'sitemapInclude' => true],
            ['path' => 'ASC'],
        );

        return $items;
    }

    /** @return array<string, mixed> */
    public function siteDefaults(): array
    {
        $defaultOg = (string) $this->settings->get('webplus.default_og_image', '');
        if ($defaultOg !== '') {
            $defaultOg = $this->absolutePublicUrl($defaultOg);
        }

        return [
            'site_name' => (string) $this->settings->get('webplus.site_name', ''),
            'base_url' => rtrim((string) $this->settings->get('webplus.base_url', ''), '/'),
            'default_og_image' => $defaultOg,
            'twitter_site' => (string) $this->settings->get('webplus.twitter_site', ''),
            'default_robots' => (string) $this->settings->get('webplus.default_robots', 'index,follow'),
            'default_og_locale' => (string) $this->settings->get('webplus.default_og_locale', 'cs_CZ'),
            'organization_json_ld' => $this->decodeJsonSetting('webplus.organization_json_ld'),
        ];
    }


    public function llmsTxt(): string
    {
        $body = (string) $this->settings->get('webplus.llms_txt', '');

        return (string) $this->hooks->filter('webplus.llms_txt', $body);
    }

    /**
     * Public site discovery endpoints (filterable by sibling modules, e.g. Web+AI).
     *
     * @param array<string, string> $endpoints
     * @return array<string, string>
     */
    public function publicSiteEndpoints(array $endpoints): array
    {
        /** @var array<string, string> $filtered */
        $filtered = $this->hooks->filter('webplus.public_site_endpoints', $endpoints);

        return $filtered;
    }

    public function robotsTxt(): string
    {
        $custom = trim((string) $this->settings->get('webplus.robots_txt', ''));
        if ($custom !== '') {
            return $custom . (str_ends_with($custom, "\n") ? '' : "\n");
        }

        $base = rtrim((string) $this->settings->get('webplus.base_url', ''), '/');
        if ($base === '') {
            $base = rtrim((string) $this->settings->get('webplus.frontend_url', ''), '/');
        }
        $sitemap = $base !== '' ? $base . '/sitemap.xml' : '/sitemap.xml';

        return "User-agent: *\nAllow: /\n\nSitemap: {$sitemap}\n";
    }

    /**
     * Resolve full meta package for a path (exact record, `{slug}` template, then live entity).
     *
     * @return array<string, mixed>
     */
    public function resolveForPath(string $path, ?string $fallbackTitle = null, ?string $fallbackDescription = null): array
    {
        $path = WebMeta::normalizePath($path);
        // Legacy FE/CMS alias — home lives at `/`.
        if (in_array($path, ['/home', '/uvod', '/index'], true)) {
            $path = '/';
        }
        $params = PathPattern::segmentVars($path);
        $meta = $this->findActiveByPath($path);
        if ($meta !== null && PathPattern::isTemplate($meta->path())) {
            $meta = null;
        }
        if ($meta === null) {
            $hit = $this->findActiveTemplateForPath($path);
            if ($hit !== null) {
                $meta = $hit['meta'];
                $params = $hit['params'] + $params;
            }
        }

        $dynamic = $this->dynamic->resolve($path, $params);
        $title = $dynamic?->title ?? $fallbackTitle;
        $description = $dynamic?->description ?? $fallbackDescription;

        $pkg = $meta !== null
            ? $this->serializeResolved($meta, $title, $description)
            : $this->packageFromDefaults($path, $title, $description);

        $vars = $params + ($dynamic?->vars ?? []);
        $pkg['path'] = $path;
        $pkg = $this->interpolatePackage($pkg, $vars);
        $pkg = $this->mergeDynamic($pkg, $dynamic);
        $pkg = $this->absolutizePackage($pkg);

        return $this->rebuildTags($pkg);
    }

    /**
     * Batch resolve — one IN(...) for exact metas, then per-path resolve from memo.
     *
     * @param list<string> $paths
     * @return array<string, array<string, mixed>> keyed by normalized path
     */
    public function resolveForPaths(array $paths): array
    {
        $normalized = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $normalized[WebMeta::normalizePath($path)] = true;
        }
        $keys = array_keys($normalized);
        if ($keys === []) {
            return [];
        }

        $this->findByPaths($keys);
        $this->dynamic->warmForPaths($keys);

        $out = [];
        foreach ($keys as $path) {
            $out[$path] = $this->resolveForPath($path);
        }

        return $out;
    }

    /**
     * Resolve meta for a CMS page id (by link or by derived path from slug).
     *
     * @return array<string, mixed>|null
     */
    public function resolveForCmsPage(
        string $pageId,
        string $slug,
        ?string $title = null,
        ?string $description = null,
        mixed $page = null,
    ): ?array {
        if ($page instanceof \Modules\Cms\Domain\Page) {
            $this->dynamic->primePage($page);
        }

        $meta = $this->findByCmsPageId($pageId);
        $slugNorm = strtolower(trim($slug, '/'));
        if (in_array($slugNorm, ['home', 'uvod', 'index', ''], true)) {
            $path = '/';
        } else {
            $path = $meta !== null ? $meta->path() : WebMeta::normalizePath($slug);
        }

        return $this->resolveForPath($path, $title, $description);
    }

    /** @return array<string, mixed> */
    public function serialize(WebMeta $meta): array
    {
        return [
            'id' => $meta->id(),
            'path' => $meta->path(),
            'cms_page_id' => $meta->cmsPageId(),
            'active' => $meta->isActive(),
            'seo' => [
                'title' => $meta->seoTitle(),
                'description' => $meta->seoDescription(),
                'canonical_url' => $meta->canonicalUrl(),
                'robots' => $meta->robots(),
                'keywords' => $meta->keywords(),
            ],
            'geo' => [
                'region' => $meta->geoRegion(),
                'placename' => $meta->geoPlacename(),
                'position' => $meta->geoPosition(),
                'ai_summary' => $meta->aiSummary(),
                'json_ld' => $meta->jsonLd(),
            ],
            'og' => [
                'title' => $meta->ogTitle(),
                'description' => $meta->ogDescription(),
                'image' => $meta->ogImageUrl(),
                'type' => $meta->ogType(),
                'url' => $meta->ogUrl(),
                'locale' => $meta->ogLocale(),
            ],
            'twitter' => [
                'card' => $meta->twitterCard(),
                'title' => $meta->twitterTitle(),
                'description' => $meta->twitterDescription(),
                'image' => $meta->twitterImageUrl(),
            ],
            'sitemap' => [
                'include' => $meta->sitemapInclude(),
                'priority' => $meta->sitemapPriority(),
                'changefreq' => $meta->sitemapChangefreq(),
            ],
            'created_at' => $meta->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $meta->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Flat tag map ready for custom FE `<head>` rendering.
     *
     * @return array<string, mixed>
     */
    public function serializeResolved(WebMeta $meta, ?string $fallbackTitle = null, ?string $fallbackDescription = null): array
    {
        $site = $this->siteDefaults();
        $title = $meta->seoTitle() ?: $fallbackTitle;
        $description = $meta->seoDescription() ?: $fallbackDescription;
        $ogTitle = $meta->ogTitle() ?: $title;
        $ogDescription = $meta->ogDescription() ?: $description;
        $ogImage = $meta->ogImageUrl() ?: ($site['default_og_image'] !== '' ? $site['default_og_image'] : null);
        $canonical = $meta->canonicalUrl();
        if ($canonical === null && $site['base_url'] !== '') {
            $canonical = $site['base_url'] . ($meta->path() === '/' ? '' : $meta->path());
        }
        $ogUrl = $meta->ogUrl() ?: $canonical;
        $ogLocale = $meta->ogLocale() ?: ($site['default_og_locale'] !== '' ? $site['default_og_locale'] : null);

        $jsonLd = $meta->jsonLd();
        $org = $site['organization_json_ld'];
        $jsonLdList = [];
        if (is_array($org) && $org !== []) {
            $jsonLdList[] = $org;
        }
        if (is_array($jsonLd) && $jsonLd !== []) {
            $jsonLdList[] = $jsonLd;
        }

        return [
            'path' => $meta->path(),
            'cms_page_id' => $meta->cmsPageId(),
            'site_name' => $site['site_name'] !== '' ? $site['site_name'] : null,
            'seo' => [
                'title' => $title,
                'description' => $description,
                'canonical_url' => $canonical,
                'robots' => $meta->robots() ?: $site['default_robots'],
                'keywords' => $meta->keywords(),
            ],
            'geo' => [
                'region' => $meta->geoRegion(),
                'placename' => $meta->geoPlacename(),
                'position' => $meta->geoPosition(),
                'ai_summary' => $meta->aiSummary(),
            ],
            'og' => [
                'title' => $ogTitle,
                'description' => $ogDescription,
                'image' => $ogImage,
                'type' => $meta->ogType(),
                'url' => $ogUrl,
                'locale' => $ogLocale,
                'site_name' => $site['site_name'] !== '' ? $site['site_name'] : null,
            ],
            'twitter' => [
                'card' => $meta->twitterCard(),
                'site' => $site['twitter_site'] !== '' ? $site['twitter_site'] : null,
                'title' => $meta->twitterTitle() ?: $ogTitle,
                'description' => $meta->twitterDescription() ?: $ogDescription,
                'image' => $meta->twitterImageUrl() ?: $ogImage,
            ],
            'json_ld' => $jsonLdList,
            /** Convenience: HTML meta/link tags as name=>content for FE */
            'tags' => $this->buildTags([
                'title' => $title,
                'description' => $description,
                'canonical' => $canonical,
                'robots' => $meta->robots() ?: $site['default_robots'],
                'keywords' => $meta->keywords(),
                'geo_region' => $meta->geoRegion(),
                'geo_placename' => $meta->geoPlacename(),
                'geo_position' => $meta->geoPosition(),
                'icbm' => $this->icbmFromPosition($meta->geoPosition()),
                'og_title' => $ogTitle,
                'og_description' => $ogDescription,
                'og_image' => $ogImage,
                'og_type' => $meta->ogType(),
                'og_url' => $ogUrl,
                'og_locale' => $ogLocale,
                'og_site_name' => $site['site_name'] !== '' ? $site['site_name'] : null,
                'twitter_card' => $meta->twitterCard(),
                'twitter_site' => $site['twitter_site'] !== '' ? $site['twitter_site'] : null,
                'twitter_title' => $meta->twitterTitle() ?: $ogTitle,
                'twitter_description' => $meta->twitterDescription() ?: $ogDescription,
                'twitter_image' => $meta->twitterImageUrl() ?: $ogImage,
            ]),
        ];
    }

    public function renderSitemapXml(): string
    {
        $base = rtrim((string) $this->settings->get('webplus.frontend_url', ''), '/');
        if ($base === '') {
            $base = rtrim((string) $this->settings->get('webplus.base_url', ''), '/');
        }
        $urls = '';
        $seen = [];
        foreach ($this->allActiveForSitemap() as $meta) {
            if (PathPattern::isTemplate($meta->path())) {
                continue;
            }
            $seen[$meta->path()] = true;
            $loc = $base !== ''
                ? $base . ($meta->path() === '/' ? '/' : $meta->path())
                : $meta->path();
            $loc = $this->xml($loc);
            $lastmod = $meta->updatedAt()->format('Y-m-d');
            $changefreq = $this->xml((string) ($meta->sitemapChangefreq() ?: 'weekly'));
            $priority = $this->xml((string) ($meta->sitemapPriority() ?: '0.5'));
            $urls .= "  <url>\n    <loc>{$loc}</loc>\n    <lastmod>{$lastmod}</lastmod>\n    <changefreq>{$changefreq}</changefreq>\n    <priority>{$priority}</priority>\n  </url>\n";
        }
        foreach ($this->dynamic->sitemapEntries() as $entry) {
            if (isset($seen[$entry['path']])) {
                continue;
            }
            $seen[$entry['path']] = true;
            $loc = $base !== ''
                ? $base . ($entry['path'] === '/' ? '/' : $entry['path'])
                : $entry['path'];
            $loc = $this->xml($loc);
            $lastmod = $this->xml($entry['lastmod']);
            $changefreq = $this->xml($entry['changefreq']);
            $priority = $this->xml($entry['priority']);
            $urls .= "  <url>\n    <loc>{$loc}</loc>\n    <lastmod>{$lastmod}</lastmod>\n    <changefreq>{$changefreq}</changefreq>\n    <priority>{$priority}</priority>\n  </url>\n";
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
{$urls}</urlset>

XML;
    }

    /**
     * @return array{content_type: string, body: string}|null
     */
    public function generateGoogleProductsFeed(): ?array
    {
        if (!(bool) $this->settings->get('webplus.google_feed_enabled', true)) {
            return null;
        }

        $base = rtrim((string) $this->settings->get('webplus.base_url', ''), '/');
        $channel = [
            'title' => (string) $this->settings->get('webplus.google_feed_title', 'Google Products'),
            'link' => $base,
            'description' => (string) $this->settings->get('webplus.google_feed_description', ''),
        ];

        $products = $this->collectGoogleFeedItems();
        $body = $this->googleFeed->render($channel, $products);

        return [
            'content_type' => $this->googleFeed->contentType(),
            'body' => $body,
        ];
    }

    /**
     * Available feed sources (built-in + hook `webplus.google_feed.sources`).
     *
     * @return list<array{id: string, label: string, default_path?: string, module?: string}>
     */
    public function googleFeedSources(): array
    {
        /** @var mixed $sources */
        $sources = $this->hooks->filter(
            'webplus.google_feed.sources',
            $this->googleFeedCollectors->sources(),
        );
        if (!is_array($sources)) {
            return [];
        }

        $out = [];
        foreach ($sources as $source) {
            if (!is_array($source) || !is_string($source['id'] ?? null) || $source['id'] === '') {
                continue;
            }
            $module = is_string($source['module'] ?? null) ? $source['module'] : null;
            // Skip sources whose module/capability is not enabled.
            if ($module !== null && $module !== '' && !$this->capabilities->has($module)) {
                continue;
            }
            $out[] = [
                'id' => $source['id'],
                'label' => is_string($source['label'] ?? null) ? $source['label'] : $source['id'],
                'default_path' => is_string($source['default_path'] ?? null) ? $source['default_path'] : '/{id}',
                'module' => $module,
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function collectGoogleFeedItems(): array
    {
        $ctx = $this->googleFeedContext();

        /** @var mixed $items */
        $items = $this->hooks->filter(
            'webplus.google_feed.items',
            $this->googleFeedCollectors->collect([], $ctx),
            $ctx,
        );

        if (!is_array($items)) {
            return [];
        }

        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['id'] ?? ''));
            $title = trim((string) ($item['title'] ?? $item['name'] ?? ''));
            if ($id === '' || $title === '') {
                continue;
            }
            $rows[] = $item;
            if (count($rows) >= $ctx->limit) {
                break;
            }
        }

        return $rows;
    }

    private function googleFeedContext(): GoogleFeedContext
    {
        $base = rtrim((string) $this->settings->get('webplus.base_url', ''), '/');
        $currency = strtoupper((string) $this->settings->get('webplus.google_feed_currency', 'CZK'));
        $brand = (string) $this->settings->get('webplus.google_feed_brand', '');
        $limit = max(1, min(10000, (int) $this->settings->get('webplus.google_feed_limit', 5000)));

        $enabledRaw = trim((string) $this->settings->get('webplus.google_feed_sources', '*'));
        $enabled = $enabledRaw === '' || $enabledRaw === '*'
            ? ['*']
            : array_values(array_filter(array_map(
                static fn (string $s): string => strtolower(trim($s)),
                explode(',', $enabledRaw),
            )));

        $paths = [
            'products' => (string) $this->settings->get('webplus.google_feed_product_path', '/p/{sku}'),
            'listings' => (string) $this->settings->get('webplus.google_feed_listings_path', '/nabidky/{slug}'),
        ];

        $extra = $this->decodeJsonSetting('webplus.google_feed_extra_paths');
        if (is_array($extra)) {
            foreach ($extra as $sourceId => $path) {
                if (is_string($sourceId) && is_string($path) && $path !== '') {
                    $paths[$sourceId] = $path;
                }
            }
        }

        foreach ($this->googleFeedSources() as $source) {
            if (!isset($paths[$source['id']]) || $paths[$source['id']] === '') {
                $paths[$source['id']] = $source['default_path'] ?? '/{id}';
            }
        }

        return new GoogleFeedContext($enabled, $base, $currency, $brand, $limit, $paths);
    }

    /** Sync path from CMS page slug when meta is linked. */
    public function syncPathFromCmsPage(string $pageId, string $slug): void
    {
        $meta = $this->findByCmsPageId($pageId);
        if ($meta === null) {
            return;
        }
        $path = WebMeta::normalizePath($slug);
        if ($meta->path() === $path) {
            return;
        }
        $conflict = $this->findByPath($path);
        if ($conflict !== null && $conflict->id() !== $meta->id()) {
            return;
        }
        $meta->setPath($path);
        $this->em->flush();
    }

    /**
     * @param array<string, mixed|null> $values
     * @return list<array{kind: string, key: string, value: string}>
     */
    private function buildTags(array $values): array
    {
        $map = [
            'description' => ['name', 'description'],
            'robots' => ['name', 'robots'],
            'keywords' => ['name', 'keywords'],
            'geo_region' => ['name', 'geo.region'],
            'geo_placename' => ['name', 'geo.placename'],
            'geo_position' => ['name', 'geo.position'],
            'icbm' => ['name', 'ICBM'],
            'og_title' => ['property', 'og:title'],
            'og_description' => ['property', 'og:description'],
            'og_image' => ['property', 'og:image'],
            'og_image_alt' => ['property', 'og:image:alt'],
            'og_image_width' => ['property', 'og:image:width'],
            'og_image_height' => ['property', 'og:image:height'],
            'og_type' => ['property', 'og:type'],
            'og_url' => ['property', 'og:url'],
            'og_locale' => ['property', 'og:locale'],
            'og_site_name' => ['property', 'og:site_name'],
            'twitter_card' => ['name', 'twitter:card'],
            'twitter_site' => ['name', 'twitter:site'],
            'twitter_title' => ['name', 'twitter:title'],
            'twitter_description' => ['name', 'twitter:description'],
            'twitter_image' => ['name', 'twitter:image'],
        ];

        $tags = [];
        if (!empty($values['title']) && is_string($values['title'])) {
            $tags[] = ['kind' => 'title', 'key' => 'title', 'value' => $values['title']];
        }
        if (!empty($values['canonical']) && is_string($values['canonical'])) {
            $tags[] = ['kind' => 'link', 'key' => 'canonical', 'value' => $values['canonical']];
        }
        foreach ($map as $field => [$kind, $key]) {
            $value = $values[$field] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            $tags[] = ['kind' => $kind, 'key' => $key, 'value' => $value];
        }

        return $tags;
    }

    /** @return array<string, mixed> */
    private function packageFromDefaults(string $path, ?string $title, ?string $description): array
    {
        $site = $this->siteDefaults();
        $path = WebMeta::normalizePath($path);
        $canonical = $site['base_url'] !== ''
            ? $site['base_url'] . ($path === '/' ? '' : $path)
            : null;
        $ogImage = $site['default_og_image'] !== '' ? $site['default_og_image'] : null;
        $jsonLdList = [];
        if (is_array($site['organization_json_ld']) && $site['organization_json_ld'] !== []) {
            $jsonLdList[] = $site['organization_json_ld'];
        }

        return [
            'path' => $path,
            'cms_page_id' => null,
            'site_name' => $site['site_name'] !== '' ? $site['site_name'] : null,
            'seo' => [
                'title' => $title,
                'description' => $description,
                'canonical_url' => $canonical,
                'robots' => $site['default_robots'],
                'keywords' => null,
            ],
            'geo' => [
                'region' => null,
                'placename' => null,
                'position' => null,
                'ai_summary' => null,
            ],
            'og' => [
                'title' => $title,
                'description' => $description,
                'image' => $ogImage,
                'type' => 'website',
                'url' => $canonical,
                'locale' => $site['default_og_locale'] !== '' ? $site['default_og_locale'] : null,
                'site_name' => $site['site_name'] !== '' ? $site['site_name'] : null,
            ],
            'twitter' => [
                'card' => 'summary_large_image',
                'site' => $site['twitter_site'] !== '' ? $site['twitter_site'] : null,
                'title' => $title,
                'description' => $description,
                'image' => $ogImage,
            ],
            'json_ld' => $jsonLdList,
            'tags' => $this->buildTags([
                'title' => $title,
                'description' => $description,
                'canonical' => $canonical,
                'robots' => $site['default_robots'],
                'keywords' => null,
                'geo_region' => null,
                'geo_placename' => null,
                'geo_position' => null,
                'icbm' => null,
                'og_title' => $title,
                'og_description' => $description,
                'og_image' => $ogImage,
                'og_type' => 'website',
                'og_url' => $canonical,
                'og_locale' => $site['default_og_locale'] !== '' ? $site['default_og_locale'] : null,
                'og_site_name' => $site['site_name'] !== '' ? $site['site_name'] : null,
                'twitter_card' => 'summary_large_image',
                'twitter_site' => $site['twitter_site'] !== '' ? $site['twitter_site'] : null,
                'twitter_title' => $title,
                'twitter_description' => $description,
                'twitter_image' => $ogImage,
            ]),
        ];
    }

    /**
     * @return array{meta: WebMeta, params: array<string, string>}|null
     */
    private function findActiveTemplateForPath(string $path): ?array
    {
        $best = null;
        $bestScore = -1;
        foreach ($this->activeTemplates() as $meta) {
            $params = PathPattern::match($meta->path(), $path);
            if ($params === null) {
                continue;
            }
            $score = PathPattern::specificity($meta->path());
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['meta' => $meta, 'params' => $params];
            }
        }

        return $best;
    }

    /** @return list<WebMeta> */
    private function activeTemplates(): array
    {
        if ($this->templateCache !== null) {
            return $this->templateCache;
        }

        try {
            /** @var list<WebMeta> $rows */
            $rows = $this->em->getRepository(WebMeta::class)->findBy(['active' => true]);
        } catch (\Throwable) {
            $this->templateCache = [];

            return [];
        }

        $out = [];
        foreach ($rows as $meta) {
            if (PathPattern::isTemplate($meta->path())) {
                $out[] = $meta;
            }
        }
        $this->templateCache = $out;

        return $out;
    }

    /**
     * @param array<string, mixed> $pkg
     * @return array<string, mixed>
     */
    private function mergeDynamic(array $pkg, ?DynamicMetaPayload $dynamic): array
    {
        if ($dynamic === null) {
            return $pkg;
        }

        $pkg['dynamic_source'] = $dynamic->source;
        $seo = is_array($pkg['seo'] ?? null) ? $pkg['seo'] : [];
        $geo = is_array($pkg['geo'] ?? null) ? $pkg['geo'] : [];
        $og = is_array($pkg['og'] ?? null) ? $pkg['og'] : [];
        $twitter = is_array($pkg['twitter'] ?? null) ? $pkg['twitter'] : [];
        $jsonLd = is_array($pkg['json_ld'] ?? null) ? $pkg['json_ld'] : [];

        // Live entity data wins over seed/template placeholders (Unsplash, old FE hosts).
        $seo['title'] = $this->firstString($dynamic->title, $seo['title'] ?? null);
        $seo['description'] = $this->firstString($dynamic->description, $seo['description'] ?? null);
        $seo['canonical_url'] = $this->firstString(
            $dynamic->canonical,
            $seo['canonical_url'] ?? null,
            $this->canonicalForPath(is_string($pkg['path'] ?? null) ? $pkg['path'] : null),
        );
        $geo['region'] = $this->firstString($dynamic->geoRegion, $geo['region'] ?? null);
        $geo['placename'] = $this->firstString($dynamic->geoPlacename, $geo['placename'] ?? null);
        $geo['position'] = $this->firstString($dynamic->geoPosition, $geo['position'] ?? null);
        $geo['ai_summary'] = $this->firstString($dynamic->aiSummary, $geo['ai_summary'] ?? null);
        $og['title'] = $this->firstString($dynamic->title, $og['title'] ?? null);
        $og['description'] = $this->firstString($dynamic->description, $og['description'] ?? null);
        // Keep site default_og_image (or meta row) — do not replace with listing/CMS covers.
        $og['url'] = $this->firstString(
            $dynamic->canonical,
            $seo['canonical_url'] ?? null,
            $this->canonicalForPath(is_string($pkg['path'] ?? null) ? $pkg['path'] : null),
            $og['url'] ?? null,
        );
        if ($dynamic->ogType !== null && ($og['type'] ?? '') === 'website') {
            $og['type'] = $dynamic->ogType;
        }
        $twitter['title'] = $this->firstString($twitter['title'] ?? null, $og['title'] ?? null);
        $twitter['description'] = $this->firstString($twitter['description'] ?? null, $og['description'] ?? null);
        $twitter['image'] = $this->firstString($twitter['image'] ?? null, $og['image'] ?? null);

        if (is_array($dynamic->jsonLd) && $dynamic->jsonLd !== []) {
            $jsonLd[] = $dynamic->jsonLd;
        }

        $pkg['seo'] = $seo;
        $pkg['geo'] = $geo;
        $pkg['og'] = $og;
        $pkg['twitter'] = $twitter;
        $pkg['json_ld'] = $jsonLd;

        return $pkg;
    }

    /**
     * @param array<string, mixed> $pkg
     * @param array<string, string|int|float|null> $vars
     * @return array<string, mixed>
     */
    private function interpolatePackage(array $pkg, array $vars): array
    {
        $walk = function (mixed $value) use (&$walk, $vars): mixed {
            if (is_string($value)) {
                return PathPattern::interpolate($value, $vars);
            }
            if (is_array($value)) {
                $out = [];
                foreach ($value as $key => $item) {
                    $out[$key] = $walk($item);
                }

                return $out;
            }

            return $value;
        };

        /** @var array<string, mixed> $out */
        $out = $walk($pkg);

        return $out;
    }

    /**
     * @param array<string, mixed> $pkg
     * @return array<string, mixed>
     */
    private function absolutizePackage(array $pkg): array
    {
        foreach (['seo' => ['canonical_url'], 'og' => ['image', 'url'], 'twitter' => ['image']] as $group => $keys) {
            if (!is_array($pkg[$group] ?? null)) {
                continue;
            }
            foreach ($keys as $key) {
                $current = $pkg[$group][$key] ?? null;
                if (is_string($current) && $current !== '') {
                    $fromBackend = $group !== 'seo' && $key === 'image';
                    $pkg[$group][$key] = $this->absolutePublicUrl($current, $fromBackend);
                }
            }
        }

        return $pkg;
    }

    /**
     * @param array<string, mixed> $pkg
     * @return array<string, mixed>
     */
    private function rebuildTags(array $pkg): array
    {
        $seo = is_array($pkg['seo'] ?? null) ? $pkg['seo'] : [];
        $geo = is_array($pkg['geo'] ?? null) ? $pkg['geo'] : [];
        $og = is_array($pkg['og'] ?? null) ? $pkg['og'] : [];
        $twitter = is_array($pkg['twitter'] ?? null) ? $pkg['twitter'] : [];

        $imageUrl = is_string($og['image'] ?? null) ? $og['image'] : null;
        $imageMeta = $this->ogImageMeta(
            $imageUrl,
            $this->firstString(
                is_string($og['title'] ?? null) ? $og['title'] : null,
                is_string($pkg['site_name'] ?? null) ? $pkg['site_name'] : null,
            ),
        );
        if ($imageUrl !== null) {
            $og['image_alt'] = $imageMeta['alt'];
            $og['image_width'] = $imageMeta['width'];
            $og['image_height'] = $imageMeta['height'];
            $pkg['og'] = $og;
        }

        $pkg['tags'] = $this->buildTags([
            'title' => $seo['title'] ?? null,
            'description' => $seo['description'] ?? null,
            'canonical' => $seo['canonical_url'] ?? null,
            'robots' => $seo['robots'] ?? null,
            'keywords' => $seo['keywords'] ?? null,
            'geo_region' => $geo['region'] ?? null,
            'geo_placename' => $geo['placename'] ?? null,
            'geo_position' => $geo['position'] ?? null,
            'icbm' => $this->icbmFromPosition(is_string($geo['position'] ?? null) ? $geo['position'] : null),
            'og_title' => $og['title'] ?? null,
            'og_description' => $og['description'] ?? null,
            'og_image' => $og['image'] ?? null,
            'og_image_alt' => $imageUrl !== null ? $imageMeta['alt'] : null,
            'og_image_width' => $imageUrl !== null && $imageMeta['width'] !== null ? (string) $imageMeta['width'] : null,
            'og_image_height' => $imageUrl !== null && $imageMeta['height'] !== null ? (string) $imageMeta['height'] : null,
            'og_type' => $og['type'] ?? null,
            'og_url' => $og['url'] ?? null,
            'og_locale' => $og['locale'] ?? null,
            'og_site_name' => $og['site_name'] ?? ($pkg['site_name'] ?? null),
            'twitter_card' => $twitter['card'] ?? null,
            'twitter_site' => $twitter['site'] ?? null,
            'twitter_title' => $twitter['title'] ?? null,
            'twitter_description' => $twitter['description'] ?? null,
            'twitter_image' => $twitter['image'] ?? null,
        ]);

        return $pkg;
    }

    /**
     * @return array{alt: ?string, width: ?int, height: ?int}
     */
    private function ogImageMeta(?string $imageUrl, ?string $altFallback): array
    {
        $alt = $altFallback !== null && $altFallback !== '' ? $altFallback : null;
        $width = null;
        $height = null;

        if (is_string($imageUrl) && $imageUrl !== ''
            && preg_match('#/media/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/file#i', $imageUrl, $m) === 1
            && class_exists(\Gereq\Media\Domain\MediaAsset::class)
        ) {
            try {
                $asset = $this->em->find(\Gereq\Media\Domain\MediaAsset::class, $m[1]);
            } catch (\Throwable) {
                $asset = null;
            }
            if ($asset instanceof \Gereq\Media\Domain\MediaAsset) {
                $width = $asset->width();
                $height = $asset->height();
                $assetAlt = $asset->alt();
                if (is_string($assetAlt) && $assetAlt !== '') {
                    $alt = $assetAlt;
                }
            }
        }

        // Open Graph recommended share size when dimensions are unknown.
        if ($imageUrl !== null && $imageUrl !== '' && ($width === null || $height === null)) {
            $width ??= 1200;
            $height ??= 630;
        }

        return ['alt' => $alt, 'width' => $width, 'height' => $height];
    }

    private function firstString(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function canonicalForPath(?string $path): ?string
    {
        $base = $this->publicBase();
        if ($base === '' || $path === null || $path === '') {
            return null;
        }
        $path = WebMeta::normalizePath($path);

        return $base . ($path === '/' ? '' : $path);
    }

    private function icbmFromPosition(?string $position): ?string
    {
        if ($position === null || $position === '') {
            return null;
        }
        if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*[;,]\s*(-?\d+(?:\.\d+)?)\s*$/', $position, $m) !== 1) {
            return null;
        }

        return $m[1] . ', ' . $m[2];
    }

    private function publicBase(): string
    {
        $fe = rtrim((string) $this->settings->get('webplus.frontend_url', ''), '/');
        if ($fe !== '') {
            return $fe;
        }

        return rtrim((string) $this->settings->get('webplus.base_url', ''), '/');
    }

    private function backendBase(): string
    {
        $app = rtrim((string) env_value('APP_URL', ''), '/');
        $request = $this->requests->getCurrentRequest();
        if ($request === null || $app === '') {
            return $app;
        }

        $origin = (string) ($request->headers->get('Origin') ?: $request->headers->get('Referer') ?: '');
        if ($origin === '') {
            return $app;
        }
        $parts = parse_url($origin);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || in_array($host, ['127.0.0.1', 'localhost', '0.0.0.0', '::1'], true)) {
            return $app;
        }

        $appParts = parse_url($app) ?: [];
        $scheme = (string) ($appParts['scheme'] ?? $parts['scheme'] ?? 'http');
        $port = $appParts['port'] ?? null;
        $base = $scheme . '://' . $host;
        if ($port !== null && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $base .= ':' . $port;
        }

        return $base;
    }

    private function absolutePublicUrl(string $url, bool $fromBackend = false): string
    {
        if (preg_match('#^https?://(?:127\.0\.0\.1|localhost|0\.0\.0\.0|\[::1\])(?::\d+)?(/.*)$#i', $url, $m) === 1) {
            $url = $m[1];
        }

        $base = $fromBackend ? $this->backendBase() : $this->publicBase();
        if (preg_match('#^https?://#i', $url) === 1) {
            $parts = parse_url($url) ?: [];
            $host = strtolower((string) ($parts['host'] ?? ''));
            $path = (string) ($parts['path'] ?? '/');
            $query = isset($parts['query']) ? '?' . $parts['query'] : '';
            if ($fromBackend) {
                return $base . ($path === '/' ? '' : $path) . $query;
            }
            if ($base !== '' && $host !== '' && (
                str_contains($host, 'gereq.cz')
                || str_starts_with($host, 'admin.')
            )) {
                $path = preg_replace('#^/realityV3/(?:frontend|admin)#', '', $path) ?: '/';

                return $base . ($path === '/' ? '' : $path) . $query;
            }

            return $url;
        }
        if ($base === '') {
            return $url;
        }

        return $base . (str_starts_with($url, '/') ? $url : '/' . $url);
    }

    /** @return array<string, mixed>|null */
    private function decodeJsonSetting(string $key): ?array
    {
        $raw = trim((string) $this->settings->get($key, ''));
        if ($raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
