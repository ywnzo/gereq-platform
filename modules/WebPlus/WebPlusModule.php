<?php

declare(strict_types=1);

namespace Modules\WebPlus;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventBus;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Modules\Cms\Domain\Events\PageUpdated;
use Modules\WebPlus\Application\Drivers\GoogleProductsFeedDriver;
use Modules\WebPlus\Application\SpaHtmlInjector;
use Modules\WebPlus\Application\WebPlusProvider;
use Symfony\Component\HttpFoundation\Request;
use Modules\WebPlus\Application\Widgets\WebPlusHealthWidget;
use Modules\WebPlus\Application\Widgets\WebPlusShortcutWidget;
use Modules\WebPlus\Application\Widgets\WebPlusStatsWidget;
use Modules\WebPlus\Http\Api\WebPlusApiController;
use Modules\WebPlus\Http\HealthController;
use Modules\WebPlus\Http\MetaController;
use Modules\WebPlus\Http\Public\PublicWebPlusController;
use Modules\WebPlus\Http\SettingsController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class WebPlusModule implements ModuleInterface
{
    public const SUBMODULES = ['Submodules/Ai'];

    public function id(): string
    {
        return 'webplus';
    }

    public function version(): string
    {
        return '3.0.1';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('webplus.view', 'perm.webplus.view', 'perm.group.webplus');
        $context->permissions()->register('webplus.manage', 'perm.webplus.manage', 'perm.group.webplus');

        $provider = $context->get(WebPlusProvider::class);
        $context->capabilities()->register('webplus', $this->id(), $provider);

        $this->registerSettings($context);
        $this->registerAdminRoutes($context);
        $this->registerPublicRoutes($context);
        $this->registerApi($context);
        $this->registerPlatformEvents($context);
        $this->registerValidation($context);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new WebPlusShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->dashboard()->addWidget(new WebPlusStatsWidget(
            $context->get(Environment::class),
            $context->get(EntityManagerInterface::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->dashboard()->addWidget(new WebPlusHealthWidget(
            $context->get(Environment::class),
            $context->get(\Modules\WebPlus\Application\Health\WebHealthChecker::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));

        $context->menu()->add(new MenuItem(
            id: 'webplus',
            label: 'nav.webplus',
            icon: 'globe',
            route: 'admin.webplus.metas.index',
            permission: 'webplus.view',
            order: 51,
            group: 'Obsah',
        ));
        $context->menu()->add(new MenuItem(
            id: 'webplus.metas',
            label: 'nav.webplus.metas',
            route: 'admin.webplus.metas.index',
            permission: 'webplus.view',
            parent: 'webplus',
            order: 52,
        ));
        $context->menu()->add(new MenuItem(
            id: 'webplus.mapping',
            label: 'webplus.mapping.title',
            route: 'admin.webplus.metas.mapping',
            permission: 'webplus.view',
            parent: 'webplus',
            order: 53,
        ));
        $context->menu()->add(new MenuItem(
            id: 'webplus.health',
            label: 'nav.webplus.health',
            route: 'admin.webplus.health',
            permission: 'webplus.view',
            parent: 'webplus',
            order: 54,
        ));
        $context->menu()->add(new MenuItem(
            id: 'webplus.settings',
            label: 'nav.webplus.settings',
            route: 'admin.webplus.settings',
            permission: 'webplus.manage',
            parent: 'webplus',
            order: 55,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'webplus.metas',
            label: 'palette.webplus.metas',
            url: '/admin/webplus/metas',
            icon: 'globe',
            permission: 'webplus.view',
            keywords: ['seo', 'geo', 'og', 'opengraph', 'meta', 'web+', 'webplus'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'webplus.mapping',
            label: 'palette.webplus.mapping',
            url: '/admin/webplus/metas/mapping',
            icon: 'globe',
            permission: 'webplus.view',
            keywords: ['mapovani', 'mapping', 'routy', 'routes', 'sablony', 'slug', 'web+', 'webplus'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'webplus.health',
            label: 'palette.webplus.health',
            url: '/admin/webplus/health',
            icon: 'activity',
            permission: 'webplus.view',
            keywords: ['health', 'zdravi', 'seo', 'geo', 'og', 'score', 'web+'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'webplus.settings',
            label: 'palette.webplus.settings',
            url: '/admin/webplus/settings',
            icon: 'globe',
            permission: 'webplus.manage',
            keywords: ['google', 'feed', 'merchant', 'llms', 'sitemap', 'robots', 'frontend'],
        ));

        $this->registerCmsHooks($context);
        $this->registerTopbarHealthBadge($context);
    }

    private function registerTopbarHealthBadge(ModuleContext $context): void
    {
        $hooks = $context->hooks();
        $twig = $context->get(Environment::class);
        $router = $context->get(Router::class);
        $auth = $context->get(\Gereq\Auth\Authenticator::class);
        $health = $context->get(\Modules\WebPlus\Application\Health\WebHealthChecker::class);
        $colorFn = [\Modules\WebPlus\Application\Health\HealthReport::class, 'scoreColor'];

        $hooks->addFilter('admin.topbar.context', static function (string $html) use ($twig, $router, $auth, $health, $colorFn): string {
            try {
                $user = $auth->user();
                if ($user === null || !$user->can('webplus.view')) {
                    return $html;
                }
                $report = $health->runForBadge();
                $partial = $twig->render('@webplus/partials/topbar_health_badge.html.twig', [
                    'score' => $report->score,
                    'color' => $colorFn($report->score),
                    'grade' => $report->grade,
                    'health_url' => $router->url('admin.webplus.health'),
                ]);

                return $html . $partial;
            } catch (\Throwable) {
                return $html;
            }
        });
    }

    private function registerSettings(ModuleContext $context): void
    {
        $s = $context->settings();
        $id = $this->id();

        $s->define('webplus.site_name', SettingRecord::TYPE_STRING, 'webplus.settings.site_name', '', $id, [], false);
        $s->define('webplus.base_url', SettingRecord::TYPE_STRING, 'webplus.settings.base_url', '', $id, [], false);
        $s->define('webplus.frontend_url', SettingRecord::TYPE_STRING, 'webplus.settings.frontend_url', '', $id, [], false);
        $s->define('webplus.default_og_image', SettingRecord::TYPE_STRING, 'webplus.settings.default_og_image', '', $id, [], false);
        $s->define('webplus.twitter_site', SettingRecord::TYPE_STRING, 'webplus.settings.twitter_site', '', $id, [], false);
        $s->define('webplus.default_robots', SettingRecord::TYPE_STRING, 'webplus.settings.default_robots', 'index,follow', $id, [], false);
        $s->define('webplus.default_og_locale', SettingRecord::TYPE_STRING, 'webplus.settings.default_og_locale', 'cs_CZ', $id, [], false);
        $s->define('webplus.organization_json_ld', SettingRecord::TYPE_STRING, 'webplus.settings.organization_json_ld', '', $id, [], false);
        $s->define('webplus.llms_txt', SettingRecord::TYPE_STRING, 'webplus.settings.llms_txt', '', $id, [], false);
        $s->define('webplus.robots_txt', SettingRecord::TYPE_STRING, 'webplus.settings.robots_txt', '', $id, [], false);

        $s->define('webplus.google_feed_enabled', SettingRecord::TYPE_BOOLEAN, 'webplus.settings.google_feed_enabled', true, $id, [], false);
        $s->define('webplus.google_feed_sources', SettingRecord::TYPE_STRING, 'webplus.settings.google_feed_sources', '*', $id, [], false);
        $s->define('webplus.google_feed_title', SettingRecord::TYPE_STRING, 'webplus.settings.google_feed_title', 'Google Products', $id, [], false);
        $s->define('webplus.google_feed_description', SettingRecord::TYPE_STRING, 'webplus.settings.google_feed_description', '', $id, [], false);
        $s->define('webplus.google_feed_currency', SettingRecord::TYPE_STRING, 'webplus.settings.google_feed_currency', 'CZK', $id, [], false);
        $s->define('webplus.google_feed_brand', SettingRecord::TYPE_STRING, 'webplus.settings.google_feed_brand', '', $id, [], false);
        $s->define('webplus.google_feed_product_path', SettingRecord::TYPE_STRING, 'webplus.settings.google_feed_product_path', '/p/{sku}', $id, [], false);
        $s->define('webplus.google_feed_listings_path', SettingRecord::TYPE_STRING, 'webplus.settings.google_feed_listings_path', '/nabidky/{slug}', $id, [], false);
        $s->define('webplus.cms_pages_path', SettingRecord::TYPE_STRING, 'webplus.settings.cms_pages_path', '/stranka/{slug}', $id, [], false);
        $s->define('webplus.google_feed_extra_paths', SettingRecord::TYPE_STRING, 'webplus.settings.google_feed_extra_paths', '', $id, [], false);
        $s->define('webplus.google_feed_limit', SettingRecord::TYPE_INTEGER, 'webplus.settings.google_feed_limit', 5000, $id, [], false);

        // Controller identity and privacy page.
        $s->define('webplus.legal_controller_name', SettingRecord::TYPE_STRING, 'webplus.settings.legal_controller_name', 'GEREQ', $id, [], false);
        $s->define('webplus.legal_controller_address', SettingRecord::TYPE_STRING, 'webplus.settings.legal_controller_address', '', $id, [], false);
        $s->define('webplus.legal_controller_ico', SettingRecord::TYPE_STRING, 'webplus.settings.legal_controller_ico', '', $id, [], false);
        $s->define('webplus.legal_controller_dic', SettingRecord::TYPE_STRING, 'webplus.settings.legal_controller_dic', '', $id, [], false);
        $s->define('webplus.legal_controller_email', SettingRecord::TYPE_STRING, 'webplus.settings.legal_controller_email', '', $id, [], false);
        $s->define('webplus.legal_controller_phone', SettingRecord::TYPE_STRING, 'webplus.settings.legal_controller_phone', '', $id, [], false);
        $s->define('webplus.privacy_path', SettingRecord::TYPE_STRING, 'webplus.settings.privacy_path', '/stranka/ochrana-osobnich-udaju', $id, [], false);
    }

    private function registerAdminRoutes(ModuleContext $context): void
    {
        $context->router()->group('/admin/webplus/metas', function (Router $routes): void {
            $routes->get('', [MetaController::class, 'index'])->name('admin.webplus.metas.index')->permission('webplus.view');
            $routes->get('/create', [MetaController::class, 'create'])->name('admin.webplus.metas.create')->permission('webplus.manage');
            $routes->get('/mapping', [MetaController::class, 'mapping'])->name('admin.webplus.metas.mapping')->permission('webplus.view');
            $routes->post('/ensure-templates', [MetaController::class, 'ensureTemplates'])->name('admin.webplus.metas.ensure_templates')->permission('webplus.manage');
            $routes->post('/auto-map', [MetaController::class, 'autoMap'])->name('admin.webplus.metas.auto_map')->permission('webplus.manage');
            $routes->get('/for-page/{pageId}', [MetaController::class, 'forPage'])->name('admin.webplus.metas.for_page')->permission('webplus.manage');
            $routes->post('', [MetaController::class, 'store'])->name('admin.webplus.metas.store')->permission('webplus.manage');
            $routes->get('/{id}/edit', [MetaController::class, 'edit'])->name('admin.webplus.metas.edit')->permission('webplus.manage');
            $routes->post('/{id}', [MetaController::class, 'update'])->name('admin.webplus.metas.update')->permission('webplus.manage');
            $routes->post('/{id}/delete', [MetaController::class, 'destroy'])->name('admin.webplus.metas.destroy')->permission('webplus.manage');
        }, ['middleware' => ['auth']]);

        $context->router()->group('/admin/webplus/settings', function (Router $routes): void {
            $routes->get('', [SettingsController::class, 'edit'])->name('admin.webplus.settings')->permission('webplus.manage');
            $routes->post('', [SettingsController::class, 'update'])->name('admin.webplus.settings.update')->permission('webplus.manage');
            $routes->get('/google-feed', [SettingsController::class, 'googleFeedPreview'])->name('admin.webplus.google_feed')->permission('webplus.view');
        }, ['middleware' => ['auth']]);

        $context->router()->group('/admin/webplus', function (Router $routes): void {
            $routes->get('/health', [HealthController::class, 'index'])->name('admin.webplus.health')->permission('webplus.view');
        }, ['middleware' => ['auth']]);
    }

    private function registerPublicRoutes(ModuleContext $context): void
    {
        $opts = [PublicWebPlusController::class, 'options'];
        foreach ([
            '/api/public/webplus/meta' => 'api.public.webplus.meta.options',
            '/api/public/webplus/html' => 'api.public.webplus.html.options',
            '/api/public/webplus/meta/by-page/{pageId}' => 'api.public.webplus.meta.page.options',
            '/api/public/webplus/site' => 'api.public.webplus.site.options',
            '/api/public/webplus/sitemap.xml' => 'api.public.webplus.sitemap.options',
            '/api/public/webplus/robots.txt' => 'api.public.webplus.robots.options',
            '/api/public/webplus/llms.txt' => 'api.public.webplus.llms.options',
            '/api/public/webplus/google-products.xml' => 'api.public.webplus.google_products.options',
        ] as $path => $name) {
            $context->router()->add($path, ['OPTIONS'], $opts)->name($name);
        }

        $context->router()->get('/api/public/webplus/meta', [PublicWebPlusController::class, 'meta'])
            ->name('api.public.webplus.meta');
        $context->router()->get('/api/public/webplus/html', [PublicWebPlusController::class, 'html'])
            ->name('api.public.webplus.html');
        $context->router()->get('/api/public/webplus/meta/by-page/{pageId}', [PublicWebPlusController::class, 'metaByPage'])
            ->name('api.public.webplus.meta.page');
        $context->router()->get('/api/public/webplus/site', [PublicWebPlusController::class, 'site'])
            ->name('api.public.webplus.site');
        $context->router()->get('/api/public/webplus/sitemap.xml', [PublicWebPlusController::class, 'sitemap'])
            ->name('api.public.webplus.sitemap');
        $context->router()->get('/api/public/webplus/robots.txt', [PublicWebPlusController::class, 'robots'])
            ->name('api.public.webplus.robots');
        $context->router()->get('/api/public/webplus/llms.txt', [PublicWebPlusController::class, 'llms'])
            ->name('api.public.webplus.llms');
        $context->router()->get('/api/public/webplus/google-products.xml', [PublicWebPlusController::class, 'googleProducts'])
            ->name('api.public.webplus.google_products');
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->registerKnownScope('webplus.read');
        $context->api()->registerKnownScope('webplus.write');

        $context->api()->module('webplus', function (ApiModule $api): void {
            $api->get('/metas', [WebPlusApiController::class, 'index'])->scope('webplus.read');
            $api->post('/metas', [WebPlusApiController::class, 'store'])->scope('webplus.write');
            $api->get('/metas/{id}', [WebPlusApiController::class, 'show'])->scope('webplus.read');
            $api->patch('/metas/{id}', [WebPlusApiController::class, 'update'])->scope('webplus.write');
            $api->delete('/metas/{id}', [WebPlusApiController::class, 'destroy'])->scope('webplus.write');
            $api->get('/resolve', [WebPlusApiController::class, 'resolve'])->scope('webplus.read');
            $api->get('/site', [WebPlusApiController::class, 'site'])->scope('webplus.read');
            $api->get('/google-products', [WebPlusApiController::class, 'googleProducts'])->scope('webplus.read');
        });
    }

    private function registerPlatformEvents(ModuleContext $context): void
    {
        foreach ([
            'webplus.meta.created' => 'Web+ meta vytvořeno',
            'webplus.meta.updated' => 'Web+ meta upraveno',
            'webplus.meta.deleted' => 'Web+ meta smazáno',
            'webplus.settings.updated' => 'Web+ nastavení uloženo',
        ] as $type => $description) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))
                    ->description($description)
                    ->webhookEnabled(true)
            );
        }
    }

    private function registerValidation(ModuleContext $context): void
    {
        $context->validation()->ruleset('webplus.meta.save', [
            'path' => 'required|min:1|max:255',
            'cms_page_id' => 'nullable|max:36',
            'active' => 'nullable',
            'seo_title' => 'nullable|max:190',
            'seo_description' => 'nullable|max:2000',
            'canonical_url' => 'nullable|max:2048',
            'robots' => 'required|in:index,follow;index,nofollow;noindex,follow;noindex,nofollow',
            'keywords' => 'nullable|max:2000',
            'geo_region' => 'nullable|max:64',
            'geo_placename' => 'nullable|max:190',
            'geo_position' => 'nullable|max:64',
            'ai_summary' => 'nullable|max:10000',
            'json_ld_json' => 'nullable|max:100000',
            'og_title' => 'nullable|max:190',
            'og_description' => 'nullable|max:2000',
            'og_image_url' => 'nullable|max:2048',
            'og_media_asset_id' => 'nullable|min:36|max:36',
            'og_type' => 'required|in:website,article,product,profile',
            'og_url' => 'nullable|max:2048',
            'og_locale' => 'nullable|max:16',
            'twitter_card' => 'required|in:summary,summary_large_image',
            'twitter_title' => 'nullable|max:190',
            'twitter_description' => 'nullable|max:2000',
            'twitter_image_url' => 'nullable|max:2048',
            'twitter_media_asset_id' => 'nullable|min:36|max:36',
            'sitemap_include' => 'nullable',
            'sitemap_priority' => 'nullable|max:8',
            'sitemap_changefreq' => 'nullable|in:always,hourly,daily,weekly,monthly,yearly,never',
            'og_sample_path' => 'nullable|max:255',
        ]);
        $context->forms()->entity('webplus.meta.save', \Modules\WebPlus\Domain\WebMeta::class);
    }

    private function registerCmsHooks(ModuleContext $context): void
    {
        $provider = $context->get(WebPlusProvider::class);
        $spaInjector = $context->get(SpaHtmlInjector::class);

        // First HTML byte for the public SPA: SEO/OG + LCP image preload (no JS wait).
        $context->hooks()->addFilter(
            'storefront.spa_html',
            static function (string $html, mixed $request = null) use ($spaInjector): string {
                if (!$request instanceof Request) {
                    return $html;
                }

                return $spaInjector->inject($html, $request);
            },
        );

        // Enrich CMS public/API page JSON with webplus.meta when present.
        $context->hooks()->addFilter(
            'cms.serialize_page',
            static function (array $data, mixed $page = null) use ($provider): array {
                $pageId = is_string($data['id'] ?? null) ? $data['id'] : null;
                $slug = is_string($data['slug'] ?? null) ? $data['slug'] : null;
                if ($pageId === null || $slug === null) {
                    return $data;
                }
                $title = is_string($data['title'] ?? null) ? $data['title'] : null;
                $description = is_string($data['meta_description'] ?? null) ? $data['meta_description'] : null;
                $resolved = $provider->resolveForCmsPage($pageId, $slug, $title, $description, $page);
                if ($resolved !== null) {
                    $data['webplus'] = $resolved;
                }

                return $data;
            },
        );

        // Keep linked meta path in sync when CMS page slug changes.
        if (class_exists(PageUpdated::class)) {
            $context->get(EventBus::class)->listen(
                PageUpdated::class,
                static function (PageUpdated $event) use ($provider, $context): void {
                    $id = $event->envelope()->resource['id'] ?? null;
                    if (!is_string($id) || $id === '') {
                        return;
                    }
                    $data = $event->envelope()->data;
                    $slug = is_string($data['slug'] ?? null) ? $data['slug'] : null;
                    if ($slug === null) {
                        try {
                            $em = $context->get(EntityManagerInterface::class);
                            $page = $em->find(\Modules\Cms\Domain\Page::class, $id);
                            if ($page instanceof \Modules\Cms\Domain\Page) {
                                $slug = $page->slug();
                            }
                        } catch (\Throwable) {
                            return;
                        }
                    }
                    if ($slug !== null) {
                        $provider->syncPathFromCmsPage($id, $slug);
                    }
                },
            );
        }

        // Silence unused DI for GoogleProductsFeedDriver (autowired into provider).
        $context->get(GoogleProductsFeedDriver::class);
    }
}
