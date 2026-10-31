<?php

declare(strict_types=1);

namespace Modules\WebPlus\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Hook\HookManager;
use Modules\Cms\Domain\Page;
use Modules\Listings\Domain\Listing;
use Modules\WebPlus\Application\DynamicMeta\DynamicMetaResolver;
use Modules\WebPlus\Domain\WebMeta;

/**
 * FE route → Web+ template → live entity source (listings / CMS / static).
 *
 * @phpstan-type MappingRow array{
 *   id: string,
 *   fe_route: string,
 *   template: string,
 *   source: string,
 *   dynamic: bool,
 *   tokens: list<string>,
 *   example: string,
 *   live_count: int,
 *   override_count: int,
 *   meta_id: string|null,
 *   meta_title: string|null,
 *   seedable: bool
 * }
 */
final class RouteMappingCatalog
{
    /** @var list<array<string, mixed>>|null */
    private ?array $cache = null;

    /** @var array<string, int> */
    private array $liveCountCache = [];

    /** @var list<WebMeta>|null */
    private ?array $activeMetasCache = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DynamicMetaResolver $dynamic,
        private readonly WebPlusProvider $webplus,
        private readonly HookManager $hooks,
        private readonly UrlTemplateDiscoverer $discoverer,
    ) {
    }

    /**
     * @return list<MappingRow>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $listingsTpl = $this->dynamic->listingsPathTemplate();
        $cmsTpl = $this->dynamic->cmsPathTemplate();
        $listingExample = $this->exampleListingPath($listingsTpl);
        $cmsExample = $this->exampleCmsPath($cmsTpl);

        // Prefetch all template metas in one IN(...) before per-row lookups.
        $this->webplus->findByPaths([
            '/',
            '/nabidky',
            $listingsTpl,
            $cmsTpl,
            '/{slug}',
            '/{page}/{x}',
            '/{page}/{x}/{y}',
        ]);
        $this->warmActiveMetas();

        $rows = [
            $this->row('home', '/', '/', 'static', false, [], '/', false),
            $this->row('listings.index', '/nabidky', '/nabidky', 'listings', false, [], '/nabidky', false),
            $this->row(
                'listings.detail',
                '/nabidky/:slug',
                $listingsTpl,
                'listings',
                true,
                ['title', 'city', 'slug', 'price', 'offer', 'description'],
                $listingExample,
                true,
            ),
            $this->row(
                'cms.page',
                '/stranka/:slug',
                $cmsTpl,
                'cms',
                true,
                ['title', 'slug', 'description'],
                $cmsExample,
                true,
            ),
            $this->row('cms.alias', '/:slug', '/{slug}', 'cms', true, ['title', 'slug'], '/o-nas', false),
            $this->row('generic.xy', '/:page/:x', '/{page}/{x}', 'generic', true, ['page', 'x', 'title', 'slug'], '/nabidky/chalupa-rymarov', true),
            $this->row('generic.xyz', '/:page/:x/:y', '/{page}/{x}/{y}', 'generic', true, ['page', 'x', 'y'], '/blog/tipy/2026', true),
        ];

        /** @var mixed $filtered */
        $filtered = $this->hooks->filter('webplus.route_mappings', $rows);
        if (!is_array($filtered)) {
            $this->cache = $rows;

            return $rows;
        }

        $out = [];
        foreach ($filtered as $row) {
            if (is_array($row) && is_string($row['id'] ?? null) && is_string($row['template'] ?? null)) {
                $out[] = $row;
            }
        }
        $this->cache = $out;

        return $out;
    }

    /**
     * Best mapping for an exact stored path (template or concrete URL).
     *
     * @return MappingRow|null
     */
    public function cover(string $path): ?array
    {
        $path = WebMeta::normalizePath($path);
        $best = null;
        $bestScore = -1;
        foreach ($this->all() as $row) {
            if (!PathPattern::isTemplate($row['template'])) {
                if ($row['template'] === $path) {
                    return $row;
                }
                continue;
            }
            if (PathPattern::match($row['template'], $path) === null) {
                continue;
            }
            $score = PathPattern::specificity($row['template']);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        return $best;
    }

    /**
     * Infer templates from concrete Web+ paths and create missing template metas.
     *
     * @return array{created: int, skipped: int, templates: list<string>}
     */
    public function autoMapFromUrls(int $minHits = 2): array
    {
        $paths = [];
        try {
            /** @var list<WebMeta> $rows */
            $rows = $this->em->getRepository(WebMeta::class)->findAll();
        } catch (\Throwable) {
            return ['created' => 0, 'skipped' => 0, 'templates' => []];
        }
        foreach ($rows as $meta) {
            $paths[] = $meta->path();
        }

        $discovered = $this->discoverer->discover($paths, $minHits);
        $created = 0;
        $skipped = 0;
        $templates = [];
        $this->webplus->findByPaths(array_map(
            static fn (array $row): string => (string) $row['template'],
            $discovered,
        ));
        foreach ($discovered as $row) {
            $template = $row['template'];
            $templates[] = $template;
            if ($this->webplus->findByPath($template) instanceof WebMeta) {
                $skipped++;
                continue;
            }
            $meta = new WebMeta($template);
            $title = $this->titleFromTokens($row['tokens']);
            $description = $this->descriptionFromTokens($row['tokens']);
            $meta->setSeoTitle($title);
            $meta->setSeoDescription($description);
            $meta->setOgTitle($title);
            $meta->setSitemapInclude(false);
            $this->em->persist($meta);
            $created++;
        }
        if ($created > 0) {
            $this->em->flush();
            $this->invalidateCaches();
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'templates' => $templates,
        ];
    }

    /**
     * Preview URL-derived templates (without writing).
     *
     * @return list<array{template: string, tokens: list<string>, samples: list<string>, hit_count: int, exists: bool}>
     */
    public function discoveredFromUrls(int $minHits = 2): array
    {
        $paths = [];
        try {
            /** @var list<WebMeta> $rows */
            $rows = $this->em->getRepository(WebMeta::class)->findAll();
        } catch (\Throwable) {
            return [];
        }
        foreach ($rows as $meta) {
            $paths[] = $meta->path();
        }

        $discovered = $this->discoverer->discover($paths, $minHits);
        $this->webplus->findByPaths(array_map(
            static fn (array $row): string => (string) $row['template'],
            $discovered,
        ));

        $out = [];
        foreach ($discovered as $row) {
            $out[] = [
                'template' => $row['template'],
                'tokens' => $row['tokens'],
                'samples' => $row['samples'],
                'hit_count' => $row['hit_count'],
                'exists' => $this->webplus->findByPath($row['template']) instanceof WebMeta,
            ];
        }

        return $out;
    }

    /** Create missing seedable template metas. @return int created */
    public function ensureTemplates(): int
    {
        $rows = $this->all();
        $this->webplus->findByPaths(array_values(array_filter(
            array_map(static fn (array $row): string => (string) ($row['template'] ?? ''), $rows),
            static fn (string $template): bool => $template !== '',
        )));

        $created = 0;
        foreach ($rows as $row) {
            if (!($row['seedable'] ?? false) || !PathPattern::isTemplate($row['template'])) {
                continue;
            }
            if ($this->webplus->findByPath($row['template']) instanceof WebMeta) {
                continue;
            }

            $meta = new WebMeta($row['template']);
            $meta->setSeoTitle($this->defaultTitle($row['source']));
            $meta->setSeoDescription($this->defaultDescription($row['source']));
            $meta->setOgTitle($this->defaultTitle($row['source']));
            $meta->setSitemapInclude(false);
            $this->em->persist($meta);
            $created++;
        }
        if ($created > 0) {
            $this->em->flush();
            $this->invalidateCaches();
        }

        return $created;
    }

    /**
     * @param list<string> $tokens
     * @return MappingRow
     */
    private function row(
        string $id,
        string $feRoute,
        string $template,
        string $source,
        bool $dynamic,
        array $tokens,
        string $example,
        bool $seedable,
    ): array {
        $meta = $this->webplus->findByPath($template);

        return [
            'id' => $id,
            'fe_route' => $feRoute,
            'template' => $template,
            'source' => $source,
            'dynamic' => $dynamic,
            'tokens' => $tokens,
            'example' => $example,
            'live_count' => $dynamic ? $this->liveCount($source) : ($meta !== null ? 1 : 0),
            'override_count' => $dynamic ? $this->overrideCount($template) : 0,
            'meta_id' => $meta?->id(),
            'meta_title' => $meta?->seoTitle(),
            'seedable' => $seedable,
        ];
    }

    private function liveCount(string $source): int
    {
        if (array_key_exists($source, $this->liveCountCache)) {
            return $this->liveCountCache[$source];
        }

        try {
            if ($source === 'listings' && class_exists(Listing::class)) {
                return $this->liveCountCache[$source] = (int) $this->em->createQueryBuilder()
                    ->select('COUNT(l.id)')
                    ->from(Listing::class, 'l')
                    ->andWhere('l.status = :status')
                    ->setParameter('status', 'published')
                    ->getQuery()
                    ->getSingleScalarResult();
            }
            if ($source === 'cms' && class_exists(Page::class)) {
                return $this->liveCountCache[$source] = (int) $this->em->createQueryBuilder()
                    ->select('COUNT(p.id)')
                    ->from(Page::class, 'p')
                    ->andWhere('p.status = :status')
                    ->andWhere('p.slug NOT IN (:skip)')
                    ->setParameter('status', 'published')
                    ->setParameter('skip', ['home', 'uvod'])
                    ->getQuery()
                    ->getSingleScalarResult();
            }
        } catch (\Throwable) {
            return $this->liveCountCache[$source] = 0;
        }

        return $this->liveCountCache[$source] = 0;
    }

    private function overrideCount(string $template): int
    {
        if (!PathPattern::isTemplate($template)) {
            return 0;
        }

        $count = 0;
        foreach ($this->warmActiveMetas() as $meta) {
            if ($meta->path() === $template || PathPattern::isTemplate($meta->path())) {
                continue;
            }
            if (PathPattern::match($template, $meta->path()) !== null) {
                $count++;
            }
        }

        return $count;
    }

    /** @return list<WebMeta> */
    private function warmActiveMetas(): array
    {
        if ($this->activeMetasCache !== null) {
            return $this->activeMetasCache;
        }

        try {
            /** @var list<WebMeta> $rows */
            $rows = $this->em->getRepository(WebMeta::class)->findBy(['active' => true]);
            $this->activeMetasCache = $rows;
        } catch (\Throwable) {
            $this->activeMetasCache = [];
        }

        return $this->activeMetasCache;
    }

    private function invalidateCaches(): void
    {
        $this->cache = null;
        $this->liveCountCache = [];
        $this->activeMetasCache = null;
    }

    private function exampleListingPath(string $template): string
    {
        $slug = 'chalupa-rymarov';
        if (class_exists(Listing::class)) {
            try {
                /** @var Listing|null $listing */
                $listing = $this->em->getRepository(Listing::class)->findOneBy(
                    ['status' => 'published'],
                    ['featured' => 'DESC', 'updatedAt' => 'DESC'],
                );
                if ($listing instanceof Listing) {
                    $slug = $listing->slug();
                }
            } catch (\Throwable) {
                // keep demo slug
            }
        }

        return PathPattern::interpolate($template, ['slug' => $slug, 'id' => $slug]);
    }

    private function exampleCmsPath(string $template): string
    {
        $slug = 'o-nas';
        if (class_exists(Page::class)) {
            try {
                /** @var list<Page> $pages */
                $pages = $this->em->getRepository(Page::class)->findBy(
                    ['status' => 'published'],
                    ['updatedAt' => 'DESC'],
                    8,
                );
                foreach ($pages as $page) {
                    if (!in_array($page->slug(), ['home', 'uvod'], true)) {
                        $slug = $page->slug();
                        break;
                    }
                }
            } catch (\Throwable) {
                // keep demo slug
            }
        }

        return PathPattern::interpolate($template, ['slug' => $slug, 'id' => $slug]);
    }

    /** @param list<string> $tokens */
    private function titleFromTokens(array $tokens): string
    {
        if (in_array('slug', $tokens, true)) {
            return '{slug}';
        }
        if (in_array('x', $tokens, true)) {
            return '{x}';
        }
        if (in_array('page', $tokens, true)) {
            return '{page}';
        }

        return $tokens !== [] ? '{' . $tokens[array_key_last($tokens)] . '}' : '{x}';
    }

    /** @param list<string> $tokens */
    private function descriptionFromTokens(array $tokens): string
    {
        if ($tokens === []) {
            return '{page} / {x}';
        }
        $parts = [];
        foreach ($tokens as $token) {
            $parts[] = '{' . $token . '}';
        }

        return implode(' / ', $parts);
    }

    private function defaultTitle(string $source): string
    {
        return match ($source) {
            'listings' => '{title}',
            'cms' => '{title}',
            default => '{x}',
        };
    }

    private function defaultDescription(string $source): string
    {
        return match ($source) {
            'listings' => '{description}',
            'cms' => '{description}',
            default => '{page} / {x}',
        };
    }
}
