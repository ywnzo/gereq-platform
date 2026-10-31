<?php

declare(strict_types=1);

namespace Modules\WebPlus\Application\Health;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Foundation\Gereq;
use Gereq\Http\Storefront;
use Gereq\Media\Domain\MediaAsset;
use Gereq\Setting\Settings;
use Modules\Cms\Application\CmsProvider;
use Modules\Cms\Domain\Page;
use Modules\WebPlus\Application\WebPlusProvider;
use Modules\WebPlus\Domain\WebMeta;

/**
 * Evaluates Web+ / FE health: SEO, OG, GEO, discovery, and frontend availability.
 */
final class WebHealthChecker
{
    /** @var list<string> */
    private const SAMPLE_PATHS = ['/', '/o-nas', '/galerie', '/kontakt'];

    private ?HealthReport $cachedFullReport = null;

    private ?HealthReport $cachedBadgeReport = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly WebPlusProvider $webplus,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function run(bool $includeFrontend = true): HealthReport
    {
        if ($includeFrontend) {
            return $this->cachedFullReport ??= $this->executeRun(true);
        }

        return $this->cachedBadgeReport ??= $this->executeRun(false);
    }

    private function executeRun(bool $includeFrontend): HealthReport
    {
        $frontend = $this->frontendUrl();
        $checks = [];

        $checks = array_merge($checks, $this->checkSettings($frontend));
        $checks = array_merge($checks, $this->checkMetas());
        $checks = array_merge($checks, $this->checkResolvedPackages());
        $checks = array_merge($checks, $this->checkDiscovery());
        $checks = array_merge($checks, $this->checkCmsMediaContract());
        $checks = array_merge($checks, $this->checkStorefrontFontBudget());
        // HTTP probes only for the full health page — never for the topbar badge.
        // Outbound HEAD/GET to /media (or APP_URL) while php -S serves this request deadlocks
        // until CURLOPT_TIMEOUT (6s) on every admin HTML render.
        if ($includeFrontend) {
            $checks = array_merge($checks, $this->checkMediaDelivery());
            $checks = array_merge($checks, $this->checkFrontend($frontend));
        }

        $totalWeight = 0;
        $earned = 0;
        foreach ($checks as $check) {
            $totalWeight += $check->weight;
            $earned += $check->score;
        }
        $score = $totalWeight > 0 ? (int) round(($earned / $totalWeight) * 100) : 0;

        $recommendations = [];
        foreach ($checks as $check) {
            if ($check->fix !== null && $check->fix !== '' && in_array($check->status, ['fail', 'warn'], true)) {
                $recommendations[] = $check->fix;
            }
        }
        $recommendations = array_values(array_unique($recommendations));

        return new HealthReport(
            score: $score,
            grade: HealthReport::gradeFor($score),
            frontendUrl: $frontend,
            checks: $checks,
            recommendations: $recommendations,
            checkedAt: new \DateTimeImmutable(),
        );
    }

    /** Quick score for topbar (no HTTP to FE). */
    public function runForBadge(): HealthReport
    {
        return $this->run(false);
    }

    private function frontendUrl(): string
    {
        $fe = rtrim(trim((string) $this->settings->get('webplus.frontend_url', '')), '/');
        if ($fe !== '') {
            return $fe;
        }

        return rtrim(trim((string) $this->settings->get('webplus.base_url', '')), '/');
    }

    /** @return list<HealthCheck> */
    private function checkSettings(string $frontend): array
    {
        $out = [];
        $siteName = trim((string) $this->settings->get('webplus.site_name', ''));
        $out[] = $this->boolCheck(
            'settings.site_name',
            'settings',
            'Název webu',
            4,
            $siteName !== '',
            $siteName !== '' ? 'Vyplněno: ' . $siteName : 'Chybí webplus.site_name.',
            'Vyplňte „Název webu“ v Nastavení Web+.',
        );

        $out[] = $this->boolCheck(
            'settings.frontend_url',
            'settings',
            'URL frontendu',
            8,
            $frontend !== '' && preg_match('#^https?://#i', $frontend) === 1,
            $frontend !== '' ? $frontend : 'Chybí URL FE.',
            'Do Nastavení Web+ zadejte „URL frontendu“ (např. http://localhost:5174).',
        );

        $og = trim((string) $this->settings->get('webplus.default_og_image', ''));
        if ($og !== '' && preg_match('#^https?://#i', $og) !== 1) {
            $base = rtrim($frontend !== '' ? $frontend : (string) $this->settings->get('webplus.base_url', ''), '/');
            if ($base !== '') {
                $og = $base . (str_starts_with($og, '/') ? $og : '/' . $og);
            }
        }
        $out[] = $this->boolCheck(
            'settings.og_image',
            'settings',
            'Výchozí OG image',
            5,
            $og !== '' && preg_match('#^https?://#i', $og) === 1,
            $og !== '' ? 'Nastaveno: ' . $og : 'Chybí výchozí OG image.',
            'Nastavte výchozí OG obrázek (upload / média) — health ho zabsolutizuje přes URL frontendu.',
        );

        $llms = trim((string) $this->settings->get('webplus.llms_txt', ''));
        $out[] = $this->boolCheck(
            'settings.llms',
            'geo',
            'llms.txt obsah',
            5,
            strlen($llms) >= 40,
            strlen($llms) >= 40 ? 'GEO discovery text je vyplněný.' : 'llms.txt je prázdný nebo příliš krátký.',
            'Doplňte llms.txt — shrnutí webu pro AI enginy (GEO).',
        );

        return $out;
    }

    /** @return list<HealthCheck> */
    private function checkMetas(): array
    {
        $out = [];
        try {
            $count = (int) $this->em->getRepository(WebMeta::class)->count(['active' => true]);
        } catch (\Throwable) {
            $count = 0;
        }

        $out[] = $this->tierCheck(
            'metas.count',
            'seo',
            'Aktivní Web+ meta',
            8,
            $count,
            [1 => 40, 3 => 70, 5 => 100],
            $count > 0
                ? sprintf('%d aktivních meta záznamů.', $count)
                : 'Žádné aktivní meta.',
            'Vytvořte SEO/OG meta alespoň pro úvod (/), /o-nas, /galerie a /kontakt.',
        );

        $homeMeta = $this->webplus->findActiveByPath('/');
        $missingHome = $homeMeta === null;
        $out[] = $this->boolCheck(
            'metas.home',
            'seo',
            'Meta pro úvod (/)',
            6,
            !$missingHome,
            $missingHome ? 'Chybí aktivní meta pro /.' : 'Úvod má Web+ meta.',
            'Přidejte Web+ meta pro path „/“ (nebo napojte CMS stránku home).',
        );

        $cmsLinked = 0;
        $cmsPublished = 0;
        if (class_exists(Page::class)) {
            try {
                /** @var list<Page> $pages */
                $pages = $this->em->getRepository(Page::class)->findBy(['status' => 'published']);
                $cmsPublished = count($pages);
                if ($cmsPublished > 0) {
                    $pageIds = array_map(static fn (Page $page): string => $page->id(), $pages);
                    $cmsLinked = count($this->webplus->findByCmsPageIds($pageIds));
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        if ($cmsPublished > 0) {
            $ratio = (int) round(($cmsLinked / $cmsPublished) * 100);
            $out[] = $this->tierCheck(
                'metas.cms_link',
                'seo',
                'CMS stránky s Web+ meta',
                8,
                $ratio,
                [1 => 20, 50 => 60, 80 => 85, 100 => 100],
                sprintf('%d / %d publikovaných stránek má Web+ meta.', $cmsLinked, $cmsPublished),
                'U CMS stránek použijte tlačítko „SEO / OG (Web+)“ a doplňte title, description a OG.',
            );
        }

        return $out;
    }

    /** @return list<HealthCheck> */
    private function checkResolvedPackages(): array
    {
        $out = [];
        $seoHits = 0;
        $ogHits = 0;
        $geoHits = 0;
        $seoMax = 0;
        $ogMax = 0;
        $geoMax = 0;
        $gaps = [];

        $packages = $this->webplus->resolveForPaths(self::SAMPLE_PATHS);
        foreach (self::SAMPLE_PATHS as $path) {
            $pkg = $packages[$path] ?? [];
            $seo = is_array($pkg['seo'] ?? null) ? $pkg['seo'] : [];
            $og = is_array($pkg['og'] ?? null) ? $pkg['og'] : [];
            $geo = is_array($pkg['geo'] ?? null) ? $pkg['geo'] : [];

            $seoMax += 2;
            if (!empty($seo['title'])) {
                $seoHits++;
            } else {
                $gaps[] = "SEO title chybí na {$path}";
            }
            if (!empty($seo['description']) && strlen((string) $seo['description']) >= 40) {
                $seoHits++;
            } else {
                $gaps[] = "SEO description je krátký/chybí na {$path}";
            }

            $ogMax += 3;
            if (!empty($og['title'])) {
                $ogHits++;
            } else {
                $gaps[] = "og:title chybí na {$path}";
            }
            if (!empty($og['description'])) {
                $ogHits++;
            }
            if (!empty($og['image'])) {
                $ogHits++;
            } else {
                $gaps[] = "og:image chybí na {$path}";
            }

            $geoMax += 2;
            if (!empty($geo['ai_summary']) && strlen((string) $geo['ai_summary']) >= 40) {
                $geoHits++;
            } else {
                $gaps[] = "AI/GEO shrnutí chybí na {$path}";
            }
            if (!empty($geo['placename']) || !empty($geo['region'])) {
                $geoHits++;
            }
        }

        $out[] = $this->ratioCheck(
            'package.seo',
            'seo',
            'SEO balíčky (vzorek cest)',
            14,
            $seoHits,
            $seoMax,
            sprintf('SEO pole: %d / %d.', $seoHits, $seoMax),
            'Doplňte seo_title a seo_description u klíčových cest (/, /o-nas, /galerie, /kontakt).',
        );
        $out[] = $this->ratioCheck(
            'package.og',
            'og',
            'Open Graph (vzorek cest)',
            12,
            $ogHits,
            $ogMax,
            sprintf('OG pole: %d / %d.', $ogHits, $ogMax),
            'Doplňte og:title, og:description a absolutní og:image u meta záznamů.',
        );
        $out[] = $this->ratioCheck(
            'package.geo',
            'geo',
            'GEO / AI shrnutí',
            12,
            $geoHits,
            $geoMax,
            sprintf('GEO pole: %d / %d.', $geoHits, $geoMax),
            'Vyplňte AI/GEO shrnutí a geo.placename (město) — pomáhá citacím v AI enginách.',
        );

        return $out;
    }

    /** @return list<HealthCheck> */
    private function checkDiscovery(): array
    {
        $out = [];
        $sitemap = $this->webplus->allActiveForSitemap();
        $out[] = $this->boolCheck(
            'discovery.sitemap',
            'discovery',
            'Sitemap položky',
            5,
            count($sitemap) > 0,
            count($sitemap) > 0
                ? sprintf('%d URL ve sitemapě.', count($sitemap))
                : 'Sitemap nemá žádné položky.',
            'Zapněte „Zahrnout do sitemap“ u aktivních Web+ meta.',
        );

        $robots = $this->webplus->robotsTxt();
        $out[] = $this->boolCheck(
            'discovery.robots',
            'discovery',
            'robots.txt',
            3,
            str_contains($robots, 'Sitemap:') || str_contains($robots, 'User-agent:'),
            'robots.txt je dostupné.',
            'Zkontrolujte robots.txt v Nastavení Web+.',
        );

        // Score Google feed only when a catalogue module (products/listings/…) is actually available.
        $feedSources = $this->webplus->googleFeedSources();
        if ($feedSources !== []) {
            $feedEnabled = (bool) $this->settings->get('webplus.google_feed_enabled', false);
            $feedItems = 0;
            if ($feedEnabled) {
                try {
                    $generated = $this->webplus->generateGoogleProductsFeed();
                    if ($generated !== null) {
                        $feedItems = substr_count($generated['body'], '<item>');
                    }
                } catch (\Throwable) {
                    $feedItems = 0;
                }
            }
            $out[] = $this->tierCheck(
                'discovery.google_feed',
                'discovery',
                'Google Products feed',
                6,
                $feedEnabled ? $feedItems : 0,
                [1 => 50, 5 => 80, 10 => 100],
                !$feedEnabled
                    ? 'Feed je vypnutý.'
                    : ($feedItems > 0 ? sprintf('%d položek ve feedu.', $feedItems) : 'Feed je zapnutý, ale prázdný.'),
                'Zapněte Google feed, nastavte zdroje (listings/products) a zkontrolujte URL šablony.',
            );
        }

        return $out;
    }

    /** @return list<HealthCheck> */
    private function checkFrontend(string $frontend): array
    {
        $out = [];
        if ($frontend === '' || preg_match('#^https?://#i', $frontend) !== 1) {
            $out[] = new HealthCheck(
                'fe.reach',
                'frontend',
                'Dostupnost FE',
                'fail',
                12,
                0,
                'Bez platné URL frontendu nelze ověřit render.',
                'Nastavte URL frontendu a spusťte kontrolu znovu.',
            );

            return $out;
        }

        $probe = $this->resolveFrontendProbeUrl($frontend);
        $home = $this->httpGet($probe['url'] . '/', $probe['resolve_ip']);
        // Public URL may sit behind a cookie gate — prefer internal SPA HTML when login page is returned.
        if ($home['ok'] && $this->looksLikeAuthGate($home['body'])) {
            $internal = $this->internalFrontendProbeUrl($frontend);
            if ($internal !== null) {
                $alt = $this->httpGet($internal . '/');
                if ($alt['ok'] && !$this->looksLikeAuthGate($alt['body'])) {
                    $home = $alt;
                    $probe = ['url' => $internal, 'rewritten' => true, 'resolve_ip' => null];
                }
            }
        }
        $reachable = $home['ok'];
        $detail = $reachable
            ? sprintf('HTTP %d (nastaveno: %s%s)', $home['status'], $frontend, $probe['rewritten'] ? ', probe: ' . $probe['url'] : '')
            : sprintf('FE neodpovídá (%s).', $home['error'] ?: 'HTTP ' . $home['status']);

        $out[] = $this->boolCheck(
            'fe.reach',
            'frontend',
            'FE odpovídá (HTTP)',
            10,
            $reachable,
            $detail,
            'Spusťte FE (`./start --https`). Pokud PHP běží v Dockeru, health check sahá na FE přes host.docker.internal (extra_hosts).',
        );

        if (!$reachable) {
            return $out;
        }

        $base = $probe['url'];
        $resolveIp = $probe['resolve_ip'];
        $html = $home['body'];
        $facts = FrontendHtmlPagespeed::analyze($html);

        $headScore = ($facts['has_title'] ? 25 : 0)
            + ($facts['has_description'] ? 25 : 0)
            + ($facts['has_og_title'] ? 25 : 0)
            + ($facts['has_og_image'] ? 25 : 0);
        if ($facts['has_spa_inject']) {
            $headScore = max($headScore, 100);
        }

        if ($facts['spa_shell'] && !$facts['has_spa_inject'] && !$facts['has_og_title'] && $headScore < 50) {
            $out[] = new HealthCheck(
                'fe.head',
                'frontend',
                'Meta tagy v HTML FE',
                'warn',
                10,
                40,
                'SPA shell bez Web+ inject / OG v prvním HTML.',
                'Zapněte modul Web+ (≥1.100) a ověřte Storefront::html inject (`storefront.spa_html`). Rebuild + deploy public/storefront.',
            );
        } else {
            $out[] = $this->tierCheck(
                'fe.head',
                'frontend',
                'Meta tagy v HTML FE',
                10,
                $headScore,
                [25 => 40, 50 => 65, 75 => 85, 100 => 100],
                sprintf(
                    'inject=%s, title=%s, description=%s, og:title=%s, og:image=%s',
                    $facts['has_spa_inject'] ? 'ano' : 'ne',
                    $facts['has_title'] ? 'ano' : 'ne',
                    $facts['has_description'] ? 'ano' : 'ne',
                    $facts['has_og_title'] ? 'ano' : 'ne',
                    $facts['has_og_image'] ? 'ano' : 'ne',
                ),
                'První HTML by mělo obsahovat Web+ meta (Storefront inject) — title, description a Open Graph.',
            );
        }

        $out[] = $this->boolCheck(
            'fe.lcp_preload',
            'pagespeed',
            'LCP image preload v prvním HTML',
            6,
            $facts['has_lcp_preload'],
            $facts['has_lcp_preload']
                ? 'Nalezen <link rel="preload" as="image"> (nebo data-gereq-lcp).'
                : 'Chybí preload LCP obrázku v prvním HTML.',
            'Nastavte og:image u meta pro „/“ — SpaHtmlInjector přidá preload. Případně zkontrolujte boot-cache po první návštěvě.',
        );

        $out[] = new HealthCheck(
            'fe.eager_fonts',
            'pagespeed',
            'Eager Material Symbols v prvním HTML',
            $facts['eager_rounded_sharp'] ? 'fail' : ($facts['eager_outlined_woff2'] ? 'warn' : 'pass'),
            6,
            $facts['eager_rounded_sharp'] ? 0 : ($facts['eager_outlined_woff2'] ? 3 : 6),
            $facts['eager_rounded_sharp']
                ? 'V prvním HTML je odkaz na rounded/sharp woff2/css (eager ~MB).'
                : ($facts['eager_outlined_woff2']
                    ? 'Outlined woff2 je preloadnutý v prvním HTML — zvažte lazy load až při ikoně.'
                    : 'Žádný eager Material Symbols font v prvním HTML.'),
            $facts['eager_rounded_sharp'] || $facts['eager_outlined_woff2']
                ? 'Použijte front-vue ≥0.1.1 / cms-core ≥0.2 — fonty jen dynamic import (outlined on demand).'
                : null,
        );

        $paths = ['/o-nas', '/galerie', '/kontakt', '/stranka/cookies'];
        $okPaths = 0;
        foreach ($paths as $path) {
            $res = $this->httpGet($base . $path, $resolveIp);
            if ($res['ok']) {
                $okPaths++;
            }
        }
        $out[] = $this->ratioCheck(
            'fe.routes',
            'frontend',
            'Klíčové FE routy',
            8,
            $okPaths,
            count($paths),
            sprintf('%d / %d rout vrací HTTP 2xx.', $okPaths, count($paths)),
            'Ověřte FE routy /o-nas, /galerie, /kontakt a /stranka/… — musí být dostupné bez 404.',
        );

        return $out;
    }

    /** @return list<HealthCheck> */
    private function checkMediaDelivery(): array
    {
        if (!class_exists(MediaAsset::class)) {
            return [];
        }

        try {
            /** @var MediaAsset|null $asset */
            $asset = $this->em->createQueryBuilder()
                ->select('a')
                ->from(MediaAsset::class, 'a')
                ->andWhere('a.isPublic = true')
                ->andWhere('a.mime LIKE :img')
                ->setParameter('img', 'image/%')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        } catch (\Throwable) {
            return [];
        }

        if (!$asset instanceof MediaAsset) {
            return [];
        }

        $base = rtrim($this->frontendUrl(), '/');
        if ($base === '') {
            $base = rtrim((string) env_value('APP_URL', ''), '/');
        }
        if ($base === '') {
            return [];
        }

        $url = $base . '/media/' . $asset->id() . '/file?w=160';
        $res = $this->httpHead($url);
        if (!$res['ok']) {
            return [
                new HealthCheck(
                    'media.cache_control',
                    'pagespeed',
                    'Cache-Control na /media/*/file',
                    'warn',
                    4,
                    2,
                    sprintf('HEAD %s → HTTP %d (%s)', $url, $res['status'], $res['error'] !== '' ? $res['error'] : 'no response'),
                    'Ověřte, že MediaFileDelivery běží za nginx s X-Accel (`docker/nginx`) a CACHE_MEDIA_TTL.',
                ),
            ];
        }

        $ok = MediaDeliveryPagespeed::cacheControlIsImmutablePublic($res['cache_control']);

        return [
            new HealthCheck(
                'media.cache_control',
                'pagespeed',
                'Cache-Control na /media/*/file',
                $ok ? 'pass' : 'warn',
                4,
                $ok ? 4 : 1,
                $ok
                    ? 'Cache-Control obsahuje public + immutable (' . ($res['cache_control'] ?? '') . ').'
                    : 'Cache-Control postrádá immutable/public: ' . ($res['cache_control'] ?? '(chybí)'),
                $ok
                    ? null
                    : 'PHP MediaFileDelivery musí posílat Cache-Control; nginx /internal-media* nesmí hlavičku přepsat.',
            ),
        ];
    }

    /** @return list<HealthCheck> */
    private function checkStorefrontFontBudget(): array
    {
        $root = Storefront::root();
        $bytes = MediaDeliveryPagespeed::largestOutlinedFontBytes($root);
        if ($bytes === null) {
            return [
                new HealthCheck(
                    'fe.font_budget',
                    'pagespeed',
                    'Outlined Material Symbols budget',
                    'pass',
                    3,
                    3,
                    'V public/storefront/assets není material-symbols-outlined*.woff2 (font jen on-demand chunk, nebo SVG chrome).',
                    null,
                ),
            ];
        }

        $soft = MediaDeliveryPagespeed::OUTLINED_FONT_SOFT_BYTES;
        $ok = $bytes <= $soft;
        $kb = (int) round($bytes / 1024);

        return [
            new HealthCheck(
                'fe.font_budget',
                'pagespeed',
                'Outlined Material Symbols budget',
                $ok ? 'pass' : 'warn',
                3,
                $ok ? 3 : 1,
                sprintf('Největší outlined woff2 ve storefrontu: %d KB (soft limit %d KB).', $kb, (int) round($soft / 1024)),
                $ok
                    ? null
                    : 'Stále plný outlined font — doplňte site SVG ikony / glyph subset (cms-core chrome SVG už kryje gallery controls).',
            ),
        ];
    }

    /** @return list<HealthCheck> */
    private function checkCmsMediaContract(): array
    {
        if (!class_exists(Page::class) || !class_exists(CmsProvider::class) || !Gereq::has()) {
            return [];
        }

        try {
            /** @var CmsProvider $cms */
            $cms = Gereq::app()->make(CmsProvider::class);
            /** @var list<Page> $pages */
            $pages = $this->em->getRepository(Page::class)->findBy(['status' => 'published'], null, 12);
        } catch (\Throwable) {
            return [];
        }

        if ($pages === []) {
            return [];
        }

        $images = 0;
        $withSrcset = 0;
        $missingSrcset = 0;
        $missingDimensions = 0;

        foreach ($pages as $page) {
            try {
                $serialized = $cms->serializePage($page, withBlocks: true, expandVariables: false);
            } catch (\Throwable) {
                continue;
            }
            $blocks = is_array($serialized['blocks'] ?? null) ? $serialized['blocks'] : [];
            /** @var list<array<string, mixed>> $blocks */
            $summary = CmsMediaPagespeed::summarizeBlocks($blocks);
            $images += $summary['images'];
            $withSrcset += $summary['with_srcset'];
            $missingSrcset += $summary['missing_srcset'];
            $missingDimensions += $summary['missing_dimensions'];
        }

        if ($images === 0) {
            return [
                new HealthCheck(
                    'cms.media_srcset',
                    'pagespeed',
                    'CMS obrázky se srcset',
                    'pass',
                    4,
                    4,
                    'V publikovaných stránkách (vzorek) nejsou image/gallery bloky.',
                    null,
                ),
            ];
        }

        $out = [];
        $out[] = $this->ratioCheck(
            'cms.media_srcset',
            'pagespeed',
            'CMS obrázky se srcset / ?w=',
            8,
            $withSrcset,
            $images,
            sprintf('%d / %d obrázků má srcset nebo ?w= (Cms ≥2.14).', $withSrcset, $images),
            'Aktualizujte modul Cms (≥2.14) a znovu publikujte stránky — publicPayload doplní srcset.',
        );
        $out[] = $this->ratioCheck(
            'cms.media_dimensions',
            'pagespeed',
            'CMS obrázky s width/height',
            4,
            max(0, $images - $missingDimensions),
            $images,
            sprintf('%d / %d obrázků má width+height (CLS).', max(0, $images - $missingDimensions), $images),
            'Doplňte rozměry u media assetů (upload znovu / MediaUrlBuilder) — FE CmsResponsiveImage je potřebuje proti CLS.',
        );

        return $out;
    }

    /**
     * From Docker localhost / *.local → 127.0.0.1 is the container, not the host.
     *
     * @return array{url: string, rewritten: bool, resolve_ip: ?string}
     */
    private function resolveFrontendProbeUrl(string $configured): array
    {
        $configured = rtrim($configured, '/');
        $direct = $this->httpGet($configured . '/');
        if ($direct['ok'] && !$this->looksLikeAuthGate($direct['body'])) {
            return ['url' => $configured, 'rewritten' => false, 'resolve_ip' => null];
        }

        $internal = $this->internalFrontendProbeUrl($configured);
        if ($internal !== null) {
            $res = $this->httpGet($internal . '/');
            if ($res['ok'] && !$this->looksLikeAuthGate($res['body'])) {
                return ['url' => $internal, 'rewritten' => true, 'resolve_ip' => null];
            }
        }

        $parts = parse_url($configured);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = (string) ($parts['scheme'] ?? 'http');
        $port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : 80);
        $path = $parts['path'] ?? '';

        $needsDockerHost = in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '[::1]', '::1'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.dev')
            || !$direct['ok'];

        if (!$needsDockerHost) {
            return ['url' => $configured, 'rewritten' => false, 'resolve_ip' => null];
        }

        $candidates = [];
        $gateway = $this->dockerBridgeGateway();
        if ($gateway !== null) {
            $candidates[] = $gateway;
        }
        foreach (['host.docker.internal', '172.17.0.1', '172.18.0.1', '10.0.2.2'] as $alt) {
            $ip = filter_var($alt, FILTER_VALIDATE_IP) ? $alt : gethostbyname($alt);
            if (is_string($ip) && $ip !== '' && $ip !== $alt && filter_var($ip, FILTER_VALIDATE_IP)) {
                $candidates[] = $ip;
            } elseif (filter_var($alt, FILTER_VALIDATE_IP)) {
                $candidates[] = $alt;
            }
        }

        foreach (array_unique($candidates) as $resolveIp) {
            // Keep original Host/SNI (vhost), connect to Docker host IP.
            $res = $this->httpGet($configured . '/', $resolveIp);
            if ($res['ok'] && !$this->looksLikeAuthGate($res['body'])) {
                return ['url' => $configured, 'rewritten' => true, 'resolve_ip' => $resolveIp];
            }

            // Fallback: literal host rewrite + Host header for vhost
            $defaultPort = $scheme === 'https' ? 443 : 80;
            $portSuffix = $port !== $defaultPort ? ':' . $port : '';
            $altUrl = rtrim(sprintf('%s://%s%s%s', $scheme, $resolveIp, $portSuffix, $path), '/');
            $res2 = $this->httpGet($altUrl . '/', null, $host !== '' ? $host : null);
            if ($res2['ok'] && !$this->looksLikeAuthGate($res2['body'])) {
                return ['url' => $altUrl, 'rewritten' => true, 'resolve_ip' => null];
            }
        }

        return ['url' => $configured, 'rewritten' => false, 'resolve_ip' => null];
    }

    private function looksLikeAuthGate(string $body): bool
    {
        return str_contains($body, '/auth/realityv3/login')
            || str_contains($body, 'GEREQ · Clients')
            || str_contains($body, 'name="username"');
    }

    private function internalFrontendProbeUrl(string $configured): ?string
    {
        $path = (string) (parse_url($configured, PHP_URL_PATH) ?? '');
        $path = rtrim($path, '/');
        if ($path === '') {
            return null;
        }

        foreach (['realityv3-nginx', 'nginx'] as $host) {
            $url = 'http://' . $host . $path;
            $res = $this->httpGet($url . '/');
            if ($res['ok'] && !$this->looksLikeAuthGate($res['body'])) {
                return $url;
            }
        }

        return null;
    }

    private function dockerBridgeGateway(): ?string
    {
        // /etc/hosts often has host.docker.internal; gateway via /proc
        $route = @file_get_contents('/proc/net/route');
        if (!is_string($route) || $route === '') {
            return null;
        }
        foreach (explode("\n", $route) as $line) {
            $cols = preg_split('/\s+/', trim($line));
            if (!is_array($cols) || count($cols) < 3) {
                continue;
            }
            if (($cols[1] ?? '') !== '00000000') {
                continue;
            }
            $gatewayHex = $cols[2] ?? '';
            if (!preg_match('/^[0-9A-Fa-f]{8}$/', $gatewayHex)) {
                continue;
            }
            $bytes = array_reverse(str_split($gatewayHex, 2));
            $ip = implode('.', array_map(static fn (string $b): int => hexdec($b), $bytes));
            if ($ip !== '0.0.0.0' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return null;
    }

    private function boolCheck(
        string $id,
        string $group,
        string $label,
        int $weight,
        bool $pass,
        string $detail,
        string $fix,
    ): HealthCheck {
        return new HealthCheck(
            $id,
            $group,
            $label,
            $pass ? 'pass' : 'fail',
            $weight,
            $pass ? $weight : 0,
            $detail,
            $pass ? null : $fix,
        );
    }

    /**
     * @param array<int, int> $thresholds value => percent of weight
     */
    private function tierCheck(
        string $id,
        string $group,
        string $label,
        int $weight,
        int $value,
        array $thresholds,
        string $detail,
        string $fix,
    ): HealthCheck {
        ksort($thresholds);
        $pct = 0;
        foreach ($thresholds as $min => $scorePct) {
            if ($value >= $min) {
                $pct = $scorePct;
            }
        }
        $score = (int) round($weight * ($pct / 100));
        $status = $pct >= 85 ? 'pass' : ($pct >= 40 ? 'warn' : 'fail');

        return new HealthCheck(
            $id,
            $group,
            $label,
            $status,
            $weight,
            $score,
            $detail,
            $status === 'pass' ? null : $fix,
        );
    }

    private function ratioCheck(
        string $id,
        string $group,
        string $label,
        int $weight,
        int $hits,
        int $max,
        string $detail,
        string $fix,
    ): HealthCheck {
        $pct = $max > 0 ? (int) round(($hits / $max) * 100) : 0;
        $score = (int) round($weight * ($pct / 100));
        $status = $pct >= 85 ? 'pass' : ($pct >= 50 ? 'warn' : 'fail');

        return new HealthCheck(
            $id,
            $group,
            $label,
            $status,
            $weight,
            $score,
            $detail,
            $status === 'pass' ? null : $fix,
        );
    }

    /** @return array{ok: bool, status: int, body: string, error: string} */
    private function httpGet(string $url, ?string $resolveIp = null, ?string $hostHeader = null): array
    {
        $res = $this->httpRequest($url, false, $resolveIp, $hostHeader);

        return [
            'ok' => $res['ok'],
            'status' => $res['status'],
            'body' => $res['body'],
            'error' => $res['error'],
        ];
    }

    /** @return array{ok: bool, status: int, body: string, error: string, cache_control: ?string} */
    private function httpHead(string $url, ?string $resolveIp = null, ?string $hostHeader = null): array
    {
        return $this->httpRequest($url, true, $resolveIp, $hostHeader);
    }

    /** @return array{ok: bool, status: int, body: string, error: string, cache_control: ?string} */
    private function httpRequest(
        string $url,
        bool $head,
        ?string $resolveIp = null,
        ?string $hostHeader = null,
    ): array {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl missing', 'cache_control' => null];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl_init failed', 'cache_control' => null];
        }

        $headerBag = '';
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => 'GEREQ-WebPlus-Health/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headerBag): int {
                $headerBag .= $line;

                return strlen($line);
            },
        ];
        if ($head) {
            $opts[CURLOPT_NOBODY] = true;
            $opts[CURLOPT_CUSTOMREQUEST] = 'HEAD';
        }

        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        $scheme = (string) ($parts['scheme'] ?? 'http');
        $port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : 80);

        if ($resolveIp !== null && $resolveIp !== '' && $host !== '') {
            // Keep Host + SNI from URL; TCP connect to Docker host / gateway.
            $opts[CURLOPT_RESOLVE] = [sprintf('%s:%d:%s', $host, $port, $resolveIp)];
        }

        $headers = [];
        if ($hostHeader !== null && $hostHeader !== '') {
            $headers[] = 'Host: ' . $hostHeader;
        }
        if ($headers !== []) {
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $ok = $status >= 200 && $status < 400 && ($head || is_string($body));
        $cacheControl = null;
        if (preg_match('/^Cache-Control:\s*(.+)$/mi', $headerBag, $m) === 1) {
            $cacheControl = trim($m[1]);
        }

        return [
            'ok' => $ok,
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'error' => $error,
            'cache_control' => $cacheControl,
        ];
    }
}
