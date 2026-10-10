<?php

declare(strict_types=1);

namespace Modules\Events\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Hook\HookManager;
use Gereq\Http\PublicUrl;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Modules\Cms\Domain\Page;
use Modules\Events\Domain\CatalogEvent;
use Modules\WebPlus\Application\DynamicMeta\DynamicMetaPayload;
use Modules\WebPlus\Domain\WebMeta;
use Modules\WebPlusAi\Domain\AgentTool;

/** Seeds Web+ SEO + Web+AI tools and maps /akce/{slug} into dynamic meta. */
final class EventsWebPlus
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly EventCatalog $catalog,
        private readonly EventCategories $categories,
        private readonly HookManager $hooks,
    ) {
    }

    public function boot(): void
    {
        $this->registerHooks();
        try {
            $this->configureSettings();
            $this->normalizeStoredOgImage();
            $this->ensureMetas();
            $this->configureAiTools();
        } catch (\Throwable) {
            // SEO seed nesmí shodit veřejné API / storefront.
        }
    }

    private function registerHooks(): void
    {
        $this->hooks->addFilter('webplus.route_mappings', function (mixed $rows): mixed {
            if (!is_array($rows)) {
                return $rows;
            }
            $filtered = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (string) ($row['id'] ?? '');
                if (str_starts_with($id, 'listings.')) {
                    continue;
                }
                $filtered[] = $row;
            }

            $count = $this->publishedCount();
            $example = $this->exampleEventPath();
            $eventsRows = [
                [
                    'id' => 'events.index',
                    'fe_route' => '/',
                    'template' => '/',
                    'source' => 'events',
                    'dynamic' => false,
                    'tokens' => [],
                    'example' => '/',
                    'live_count' => $count,
                    'override_count' => 0,
                    'meta_id' => null,
                    'meta_title' => null,
                    'seedable' => false,
                ],
                [
                    'id' => 'events.saved',
                    'fe_route' => '/ulozene',
                    'template' => '/ulozene',
                    'source' => 'static',
                    'dynamic' => false,
                    'tokens' => [],
                    'example' => '/ulozene',
                    'live_count' => 1,
                    'override_count' => 0,
                    'meta_id' => null,
                    'meta_title' => null,
                    'seedable' => false,
                ],
                [
                    'id' => 'events.detail',
                    'fe_route' => '/akce/:slug',
                    'template' => '/akce/{slug}',
                    'source' => 'events',
                    'dynamic' => true,
                    'tokens' => ['title', 'place', 'region', 'slug', 'description', 'date'],
                    'example' => $example,
                    'live_count' => $count,
                    'override_count' => 0,
                    'meta_id' => null,
                    'meta_title' => null,
                    'seedable' => true,
                ],
            ];

            return array_merge($eventsRows, $filtered);
        });

        $this->hooks->addFilter('webplus.dynamic_meta', function (mixed $payload, mixed $path, mixed $params): mixed {
            if ($payload instanceof DynamicMetaPayload) {
                return $payload;
            }
            $path = is_string($path) ? $path : '';
            $params = is_array($params) ? $params : [];
            $slug = $this->eventSlug($path, $params);
            if ($slug === null) {
                return $payload;
            }
            $event = $this->catalog->findBySlug($slug);
            if ($event === null || $event->status() !== 'published') {
                return $payload;
            }

            return $this->payloadFor($event, $path, $params);
        });

        $this->hooks->addFilter('webplus.dynamic_sitemap', function (mixed $entries): mixed {
            if (!is_array($entries)) {
                $entries = [];
            }
            $result = $this->catalog->search('', [], 1, 200, 'startsAt', 'asc', true);
            foreach ($result['items'] as $event) {
                if (!$event instanceof CatalogEvent) {
                    continue;
                }
                $entries[] = [
                    'path' => '/akce/' . $event->slug(),
                    'lastmod' => $this->categories->hidesDates($event->category())
                        ? $event->updatedAt()->format('Y-m-d')
                        : $event->startsAt()->format('Y-m-d'),
                    'priority' => '0.7',
                    'changefreq' => 'weekly',
                ];
            }

            return $entries;
        });

        $this->hooks->addFilter('webplus.llms_txt', function (mixed $body): mixed {
            $text = is_string($body) ? $body : '';
            if (str_contains($text, '## Akce')) {
                return $text;
            }
            $fe = $this->frontendUrl();
            $text .= "\n## Akce\n- Katalog: {$fe}/\n- Detail akce: {$fe}/akce/{slug}\n- Uložené: {$fe}/ulozene\n";

            return $text;
        });
    }

    private function configureSettings(): void
    {
        $fe = $this->frontendUrl();
        $fe = explode(',', $fe)[0] ?? $fe;
        $fe = trim($fe);
        if ($fe === '') {
            $fe = PublicUrl::frontend();
        }
        $sitemap = $fe . '/sitemap.xml';
        $og = $this->ogImageUrl();
        $orgLd = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => 'KdeAco',
            'url' => $fe,
            'logo' => $og,
            'image' => $og,
            'areaServed' => 'Plzeňský kraj',
            'description' => 'Katalog akcí, koncertů a výletů v Plzni a okolí.',
        ];

        $pairs = [
            ['webplus.site_name', 'KdeAco', SettingRecord::TYPE_STRING],
            ['webplus.base_url', $fe, SettingRecord::TYPE_STRING],
            ['webplus.frontend_url', $fe, SettingRecord::TYPE_STRING],
            ['webplus.default_robots', 'index,follow', SettingRecord::TYPE_STRING],
            ['webplus.default_og_locale', 'cs_CZ', SettingRecord::TYPE_STRING],
            ['webplus.cms_pages_path', '/stranka/{slug}', SettingRecord::TYPE_STRING],
            ['webplus.cookies_enabled', true, SettingRecord::TYPE_BOOLEAN],
            ['webplus.cookies_policy_path', '/stranka/cookies', SettingRecord::TYPE_STRING],
            ['webplus.cookies_title', 'KdeAco používá cookies', SettingRecord::TYPE_STRING],
            ['webplus.cookies_text', 'Používáme nezbytné cookies a volitelně statistické a marketingové. Podrobnosti najdete v zásadách cookies.', SettingRecord::TYPE_STRING],
            ['webplus.google_feed_enabled', false, SettingRecord::TYPE_BOOLEAN],
            ['webplus.google_feed_listings_path', '/nabidky/{slug}', SettingRecord::TYPE_STRING],
            ['webplus.google_feed_title', 'KdeAco — akce', SettingRecord::TYPE_STRING],
            ['webplus.google_feed_description', 'Aktuální akce v Plzni a okolí', SettingRecord::TYPE_STRING],
            ['webplus.google_feed_brand', 'KdeAco', SettingRecord::TYPE_STRING],
            ['webplus.organization_json_ld', json_encode($orgLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', SettingRecord::TYPE_STRING],
            ['webplus.robots_txt', "User-agent: *\nAllow: /\n\nUser-agent: GPTBot\nAllow: /\n\nUser-agent: ChatGPT-User\nAllow: /\n\nUser-agent: Google-Extended\nAllow: /\n\nUser-agent: anthropic-ai\nAllow: /\n\nUser-agent: ClaudeBot\nAllow: /\n\nUser-agent: PerplexityBot\nAllow: /\n\nSitemap: {$sitemap}\n", SettingRecord::TYPE_STRING],
            ['webplus.llms_txt', "# KdeAco\n\n> Katalog akcí, koncertů, sportu a výletů v Plzni a okolí.\n\n## Site\n- Frontend: {$fe}\n- Katalog: {$fe}/\n- Detail: {$fe}/akce/{slug}\n- Lokalita: Plzeňský kraj, Česká republika\n", SettingRecord::TYPE_STRING],
        ];

        foreach ($pairs as [$key, $value, $type]) {
            $existing = $this->settings->getLive($key, null);
            if (is_string($existing) && trim($existing) !== '') {
                continue;
            }
            if ($existing !== null && $existing !== '' && $existing !== []) {
                continue;
            }
            $this->settings->setLive($key, $value, $type, 'webplus');
        }

        $this->promoteHttpsFrontend($fe);

        $policy = trim((string) $this->settings->getLive('webplus.cookies_policy_path', ''));
        if ($policy === '' || $policy === '/cookies') {
            $this->settings->setLive('webplus.cookies_policy_path', '/stranka/cookies', SettingRecord::TYPE_STRING, 'webplus');
        }

        $this->applyLegalPack();
    }

    private function applyLegalPack(): void
    {
        $revision = (int) $this->settings->getLive('webplus.legal_pack_revision', 0);
        if ($revision >= LegalDocs::REVISION) {
            return;
        }

        $this->settings->setLive('webplus.cookies_title', 'Cookies na KdeAco', SettingRecord::TYPE_STRING, 'webplus');
        $this->settings->setLive(
            'webplus.cookies_text',
            'Používáme jen technicky nezbytné cookies a úložiště (přihlášení, zabezpečení, uložení vaší volby). Volitelné statistiky a marketing nespouštíme, dokud k nim v liště nedáte souhlas — a jen pokud je provozovatel zapne.',
            SettingRecord::TYPE_STRING,
            'webplus',
        );
        $this->settings->setLive('webplus.cookies_btn_allow_all', 'Povolit volitelné', SettingRecord::TYPE_STRING, 'webplus');
        $this->settings->setLive('webplus.cookies_btn_deny', 'Pouze nezbytné', SettingRecord::TYPE_STRING, 'webplus');
        $this->settings->setLive('webplus.cookies_btn_settings', 'Nastavení', SettingRecord::TYPE_STRING, 'webplus');
        $this->settings->setLive('webplus.cookies_btn_save', 'Uložit výběr', SettingRecord::TYPE_STRING, 'webplus');
        $this->settings->setLive(
            'webplus.cookies_necessary_text',
            'Technické cookies a úložiště pro přihlášení, zabezpečení a zapamatování, zda souhlasíte s volitelnými cookies. Nelze vypnout.',
            SettingRecord::TYPE_STRING,
            'webplus',
        );
        $this->settings->setLive('webplus.cookies_preferences_enabled', false, SettingRecord::TYPE_BOOLEAN, 'webplus');
        $this->settings->setLive('webplus.cookies_statistics_enabled', false, SettingRecord::TYPE_BOOLEAN, 'webplus');
        $this->settings->setLive('webplus.cookies_marketing_enabled', false, SettingRecord::TYPE_BOOLEAN, 'webplus');
        $this->settings->setLive('webplus.cookies_policy_path', '/stranka/cookies', SettingRecord::TYPE_STRING, 'webplus');
        $this->settings->setLive('webplus.privacy_path', '/stranka/ochrana-osobnich-udaju', SettingRecord::TYPE_STRING, 'webplus');
        if (trim((string) $this->settings->getLive('webplus.legal_controller_name', '')) === '') {
            $this->settings->setLive('webplus.legal_controller_name', 'KdeAco', SettingRecord::TYPE_STRING, 'webplus');
        }
        $this->settings->setLive('webplus.legal_pack_revision', LegalDocs::REVISION, SettingRecord::TYPE_INTEGER, 'webplus');
    }

    private function normalizeStoredOgImage(): void
    {
        $current = trim((string) $this->settings->getLive('webplus.default_og_image', ''));
        $path = is_string(parse_url($current, PHP_URL_PATH)) ? (string) parse_url($current, PHP_URL_PATH) : '';
        if (str_starts_with($path, '/media/') && str_contains($path, '/file')) {
            if ($current !== $path) {
                $this->settings->setLive('webplus.default_og_image', $path, SettingRecord::TYPE_STRING, 'webplus');
            }

            return;
        }
        if ($current === '' || str_contains($current, ':5155') || str_contains($current, 'unsplash.com')) {
            $this->settings->setLive('webplus.default_og_image', '/og.png', SettingRecord::TYPE_STRING, 'webplus');
        }
    }

    private function ensureMetas(): void
    {
        if (!class_exists(WebMeta::class)) {
            return;
        }
        $fe = $this->frontendUrl();
        $og = $this->ogImageUrl();
        $repo = $this->em->getRepository(WebMeta::class);

        $ensure = function (
            string $path,
            string $title,
            string $desc,
            string $aiSummary,
            bool $sitemapInclude,
            string $prio,
            string $ogType = 'website',
            ?string $cmsPageId = null,
        ) use ($repo, $fe, $og): void {
            $meta = $repo->findOneBy(['path' => $path]);
            if ($meta instanceof WebMeta) {
                $currentOg = trim((string) ($meta->ogImageUrl() ?? ''));
                if ($currentOg === '' || str_contains($currentOg, ':5155') || str_contains($currentOg, 'unsplash.com')) {
                    $meta->setOgImageUrl($og);
                }
                if ($cmsPageId !== null && $cmsPageId !== '' && ($meta->cmsPageId() ?? '') === '') {
                    $meta->setCmsPageId($cmsPageId);
                }

                return;
            }
            $meta = new WebMeta($path);
            $this->em->persist($meta);
            $meta->setActive(true);
            if ($cmsPageId !== null && $cmsPageId !== '') {
                $meta->setCmsPageId($cmsPageId);
            }
            $meta->setSeoTitle($title);
            $meta->setSeoDescription($desc);
            $meta->setOgTitle($title);
            $meta->setOgDescription($desc);
            $meta->setOgImageUrl($og);
            $meta->setOgType($ogType);
            $meta->setOgUrl($fe . ($path === '/' ? '' : $path));
            $meta->setOgLocale('cs_CZ');
            $meta->setRobots('index,follow');
            $meta->setTwitterCard('summary_large_image');
            $meta->setGeoRegion('CZ-PL');
            $meta->setGeoPlacename('Plzeň');
            $meta->setGeoPosition('49.7384;13.3736');
            $meta->setAiSummary($aiSummary);
            $meta->setSitemapInclude($sitemapInclude);
            $meta->setSitemapPriority($prio);
            $meta->setSitemapChangefreq($path === '/' ? 'daily' : 'weekly');
        };

        $cmsPageIdBySlug = $this->cmsPageIdsBySlug();

        $ensure(
            '/',
            'KdeAco · Akce v Plzni a okolí',
            'Katalog koncertů, sportu, výletů a akcí pro děti v Plzni a Plzeňském kraji.',
            'KdeAco je katalog akcí v Plzni a okolí. Najdete tu koncerty, sport, kulturu, výlety i program pro děti — s termíny, místy a možností uložit si oblíbené.',
            true,
            '1.0',
        );
        $ensure(
            '/akce/{slug}',
            '{title} · KdeAco',
            '{description}',
            '{description}',
            false,
            '0.7',
            'article',
        );
        $ensure(
            '/ulozene',
            'Uložené akce · KdeAco',
            'Vaše uložené akce z katalogu KdeAco.',
            'Přihlášení návštěvníci si na KdeAco ukládají oblíbené akce a vrací se k nim v přehledu Uložené.',
            false,
            '0.2',
        );
        $ensure(
            '/profil',
            'Profil · KdeAco',
            'Správa účtu, přidaných akcí a zájmu o program na KdeAco.',
            'Profil na KdeAco slouží k přihlášení, správě účtu a přehledu přidaných či sledovaných akcí.',
            false,
            '0.2',
        );
        $ensure(
            '/profil/nastaveni',
            'Nastavení účtu · KdeAco',
            'Údaje účtu, soukromí a smazání profilu na KdeAco.',
            'V nastavení účtu KdeAco lze upravit kontaktní údaje a spravovat soukromí včetně žádosti o výmaz.',
            false,
            '0.1',
        );
        $ensure(
            '/cookies',
            'Zásady cookies · KdeAco',
            'Cookies a podobné technologie na KdeAco: nezbytné prostředky, souhlas a jak ho změnit.',
            'Zásady cookies KdeAco popisují technicky nezbytné cookies, localStorage a volitelný souhlas.',
            false,
            '0.3',
        );
        $ensure(
            '/stranka/cookies',
            'Zásady cookies · KdeAco',
            'Cookies a podobné technologie na KdeAco: nezbytné prostředky, souhlas a jak ho změnit.',
            'Zásady cookies KdeAco popisují technicky nezbytné cookies, localStorage a volitelný souhlas.',
            true,
            '0.3',
            'website',
            $cmsPageIdBySlug['cookies'] ?? null,
        );
        $ensure(
            '/stranka/o-nas',
            'O nás · KdeAco',
            'KdeAco je komunitní katalog akcí v Plzni a Plzeňském kraji.',
            'KdeAco je katalog akcí, které sem dali lidé. Srdce má v Domažlicích, program sbírá z celého kraje.',
            true,
            '0.4',
            'website',
            $cmsPageIdBySlug['o-nas'] ?? null,
        );
        $ensure(
            '/stranka/ochrana-osobnich-udaju',
            'Ochrana osobních údajů · KdeAco',
            'Informace o zpracování osobních údajů na KdeAco podle čl. 13 a 14 GDPR.',
            'KdeAco jako správce zpracovává údaje účtu, kontaktů, katalogu a novinek podle GDPR. Práva subjektů a cookies jsou popsány na webu.',
            true,
            '0.3',
            'website',
            $cmsPageIdBySlug['ochrana-osobnich-udaju'] ?? null,
        );
        $ensure(
            '/stranka/obchodni-podminky',
            'Obchodní podmínky · KdeAco',
            'Obchodní podmínky KdeAco: užívání katalogu, licence k fotografiím, oprávnění a hlášení obsahu.',
            'Podmínky užívání katalogu KdeAco včetně licence k fotografiím, potvrzení oprávnění a postupu při hlášení závadného obsahu.',
            true,
            '0.3',
            'website',
            $cmsPageIdBySlug['obchodni-podminky'] ?? null,
        );
        $ensure(
            '/kontakt',
            'Kontakt · KdeAco',
            'Napište týmu KdeAco — tip na akci, spolupráce nebo technický dotaz.',
            'Kontaktní formulář KdeAco slouží k tipům na akce, spolupráci a běžným dotazům k katalogu.',
            true,
            '0.5',
        );

        $this->linkPublishedCmsPages($ensure, $cmsPageIdBySlug);
        $this->em->flush();
    }

    /** @return array<string, string> slug => page id */
    private function cmsPageIdsBySlug(): array
    {
        if (!class_exists(Page::class)) {
            return [];
        }

        $out = [];
        /** @var list<Page> $pages */
        $pages = $this->em->getRepository(Page::class)->findAll();
        foreach ($pages as $page) {
            if ($page instanceof Page) {
                $out[$page->slug()] = $page->id();
            }
        }

        return $out;
    }

    /**
     * Ensure every published CMS page has a linked Web+ meta (path from cms_pages_path).
     *
     * @param callable(string,string,string,string,bool,string,string,?string):void $ensure
     * @param array<string, string> $cmsPageIdBySlug
     */
    private function linkPublishedCmsPages(callable $ensure, array $cmsPageIdBySlug): void
    {
        if (!class_exists(Page::class) || $cmsPageIdBySlug === []) {
            return;
        }

        $tpl = trim((string) $this->settings->getLive('webplus.cms_pages_path', '/stranka/{slug}'));
        if ($tpl === '' || !str_contains($tpl, '{slug}')) {
            $tpl = '/stranka/{slug}';
        }

        /** @var list<Page> $pages */
        $pages = $this->em->getRepository(Page::class)->findBy(['status' => 'published']);
        foreach ($pages as $page) {
            if (!$page instanceof Page) {
                continue;
            }
            $slug = $page->slug();
            $path = str_replace('{slug}', $slug, $tpl);
            $title = trim($page->title()) . ' · KdeAco';
            $desc = trim((string) ($page->metaDescription() ?? ''));
            if ($desc === '') {
                $desc = $title;
            }
            $ensure(
                $path,
                $title,
                $desc,
                $desc,
                true,
                '0.4',
                'website',
                $page->id(),
            );
        }
    }

    private function configureAiTools(): void
    {
        if (!class_exists(AgentTool::class)) {
            return;
        }
        if (!(bool) $this->settings->getLive('webplus_ai.enabled', false)) {
            $this->settings->setLive('webplus_ai.enabled', true, SettingRecord::TYPE_BOOLEAN, 'webplus_ai');
        }
        $repo = $this->em->getRepository(AgentTool::class);

        foreach (['find_properties', 'get_property_detail'] as $name) {
            $tool = $repo->findOneBy(['name' => $name]);
            if ($tool instanceof AgentTool) {
                $tool->setEnabled(false);
            }
        }

        $site = $repo->findOneBy(['name' => 'get_site_info']);
        if ($site instanceof AgentTool) {
            $site->setEnabled(true);
            $site->setTitle('Informace o KdeAco');
            $site->setDescription('Základní informace o webu KdeAco — katalog akcí v Plzni a okolí.');
        }

        $this->upsertTool(
            'find_events',
            'Najít akce',
            'Vyhledá publikované akce v katalogu KdeAco podle textu, kategorie nebo regionu.',
            [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Volný text (název, místo, popis)'],
                    'category' => ['type' => 'string', 'description' => 'Slug kategorie (Zábava, Sport, Pro děti, Kultura, Ostatní)'],
                    'region' => ['type' => 'string', 'description' => 'Okres Plzeňského kraje (Domažlice, Klatovy, Plzeň-město, Plzeň-jih, Plzeň-sever, Rokycany, Tachov)'],
                    'limit' => ['type' => 'integer', 'description' => 'Max. počet výsledků (1–20)', 'default' => 8],
                ],
            ],
            10,
        );
        $this->upsertTool(
            'get_event_detail',
            'Detail akce',
            'Vrátí detail jedné akce podle slugu z URL /akce/{slug}.',
            [
                'type' => 'object',
                'required' => ['slug'],
                'properties' => [
                    'slug' => ['type' => 'string', 'description' => 'URL slug akce'],
                ],
            ],
            20,
        );

        $this->em->flush();
    }

    /** @param array<string, mixed> $schema */
    private function upsertTool(string $name, string $title, string $description, array $schema, int $order): void
    {
        $repo = $this->em->getRepository(AgentTool::class);
        $tool = $repo->findOneBy(['name' => $name]);
        if (!$tool instanceof AgentTool) {
            $tool = new AgentTool($name, $title, $description);
            $this->em->persist($tool);
        }
        $tool->setTitle($title);
        $tool->setDescription($description);
        $tool->setInputSchema($schema);
        $tool->setEnabled(true);
        $tool->setMutating(false);
        $tool->setSortOrder($order);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function payloadFor(CatalogEvent $event, string $path, array $params): DynamicMetaPayload
    {
        $excerpt = trim($event->description());
        if (mb_strlen($excerpt) > 220) {
            $excerpt = rtrim(mb_substr($excerpt, 0, 217)) . '…';
        }
        $fe = $this->frontendUrl();
        $image = EventMediaLibrary::publicUrl($event->mediaAssetId()) ?: $this->ogImageUrl();
        $place = trim($event->place() . ($event->region() !== '' ? ', ' . $event->region() : ''));
        $hideDates = $this->categories->hidesDates($event->category())
            || (!$event->showStartsAt() && !($event->showEndsAt() && $event->endsAt() !== null));
        $date = $hideDates
            ? ''
            : \Modules\Events\Application\EventCatalog::publicDateLabel(
                $event->startsAt(),
                $event->endsAt(),
                $event->showStartsAt(),
                $event->showEndsAt() && $event->endsAt() !== null,
            );
        $summary = $event->title() . ($date !== '' ? ' — ' . $date : '') . ($place !== '' ? ' · ' . $place : '');
        $jsonLd = [
                '@context' => 'https://schema.org',
                '@type' => 'Event',
                'name' => $event->title(),
                'description' => $excerpt !== '' ? $excerpt : $event->title(),
                'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
                'eventStatus' => 'https://schema.org/EventScheduled',
                'location' => [
                    '@type' => 'Place',
                    'name' => $event->place(),
                    'address' => [
                        '@type' => 'PostalAddress',
                        'addressLocality' => $event->region() !== '' ? $event->region() : $event->place(),
                        'addressRegion' => 'Plzeňský kraj',
                        'addressCountry' => 'CZ',
                    ],
                ],
                'image' => $image,
                'url' => $fe . '/akce/' . $event->slug(),
                'organizer' => [
                    '@type' => 'Organization',
                    'name' => trim((string) $event->organizer()) !== '' ? trim((string) $event->organizer()) : 'KdeAco',
                ],
        ];
        if ($event->showStartsAt() && !$this->categories->hidesDates($event->category())) {
            $jsonLd['startDate'] = $event->startsAt()->format(\DateTimeInterface::ATOM);
        }
        if ($event->showEndsAt() && $event->endsAt() !== null && !$this->categories->hidesDates($event->category())) {
            $jsonLd['endDate'] = $event->endsAt()->format(\DateTimeInterface::ATOM);
        }

        return new DynamicMetaPayload(
            source: 'events',
            path: $path,
            title: $event->title(),
            description: $excerpt !== '' ? $excerpt : $summary,
            image: $image,
            geoRegion: 'CZ-PL',
            geoPlacename: $event->place() !== '' ? $event->place() : 'Plzeň',
            geoPosition: '49.7384;13.3736',
            aiSummary: $summary . ($excerpt !== '' ? '. ' . $excerpt : ''),
            jsonLd: $jsonLd,
            vars: array_merge($params, [
                'title' => $event->title(),
                'slug' => $event->slug(),
                'place' => $event->place(),
                'region' => $event->region(),
                'description' => $excerpt,
                'date' => $date,
            ]),
            canonical: $fe . '/akce/' . $event->slug(),
            ogType: 'article',
            updatedAt: $hideDates ? $event->updatedAt() : $event->startsAt(),
        );
    }

    /** @param array<string, mixed> $params */
    private function eventSlug(string $path, array $params): ?string
    {
        if (isset($params['slug']) && is_string($params['slug']) && str_starts_with($path, '/akce/')) {
            return $params['slug'];
        }
        if (preg_match('#^/akce/([^/]+)$#', $path, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function frontendUrl(): string
    {
        $stored = rtrim((string) $this->settings->get('webplus.frontend_url', ''), '/');
        if ($stored !== '' && !PublicUrl::isLoopback($stored)) {
            return $stored;
        }

        return PublicUrl::frontend();
    }

    private function promoteHttpsFrontend(string $httpsFe): void
    {
        if (!str_starts_with(strtolower($httpsFe), 'https://')) {
            return;
        }
        foreach (['webplus.frontend_url', 'webplus.base_url'] as $key) {
            $current = rtrim((string) $this->settings->getLive($key, ''), '/');
            if ($current === '' || PublicUrl::isLoopback($current)) {
                $this->settings->setLive($key, $httpsFe, SettingRecord::TYPE_STRING, 'webplus');
            }
        }
    }

    private function ogImageUrl(): string
    {
        $current = trim((string) $this->settings->get('webplus.default_og_image', ''));
        if (
            $current !== ''
            && !str_contains($current, ':5155')
            && !str_contains($current, 'unsplash.com')
        ) {
            if (preg_match('#^https?://#i', $current) === 1) {
                return $current;
            }

            return rtrim(PublicUrl::app() ?: 'http://127.0.0.1:8088', '/') . (str_starts_with($current, '/') ? $current : '/' . $current);
        }

        return rtrim(PublicUrl::app() ?: 'http://127.0.0.1:8088', '/') . '/og.png';
    }

    private function publishedCount(): int
    {
        try {
            return (int) $this->em->createQueryBuilder()
                ->select('COUNT(e.id)')
                ->from(CatalogEvent::class, 'e')
                ->andWhere('e.status = :st')
                ->setParameter('st', 'published')
                ->getQuery()
                ->getSingleScalarResult();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function exampleEventPath(): string
    {
        $result = $this->catalog->search('', [], 1, 1, 'startsAt', 'asc', true);
        $first = $result['items'][0] ?? null;
        if ($first instanceof CatalogEvent) {
            return '/akce/' . $first->slug();
        }

        return '/akce/ukazkova-akce';
    }
}
