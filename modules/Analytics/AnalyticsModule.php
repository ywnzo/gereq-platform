<?php

declare(strict_types=1);

namespace Modules\Analytics;

use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Admin\Search\AdminSearchHit;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Analytics\Application\AnalyticsConfig;
use Modules\Analytics\Application\AnalyticsProvider;
use Modules\Analytics\Application\Jobs\PurgeAnalyticsJob;
use Modules\Analytics\Application\Jobs\RollupAnalyticsJob;
use Modules\Analytics\Application\Repositories\SiteRepository;
use Modules\Analytics\Application\TrackerInjector;
use Modules\Analytics\Application\Widgets\AnalyticsKpiWidget;
use Modules\Analytics\Application\Widgets\AnalyticsTrendChartWidget;
use Modules\Analytics\Http\Api\AnalyticsApiController;
use Modules\Analytics\Http\OverviewController;
use Modules\Analytics\Http\BehaviorController;
use Modules\Analytics\Http\Public\PublicAnalyticsController;
use Modules\Analytics\Http\ReportController;
use Modules\Analytics\Http\SettingsController;
use Modules\Analytics\Http\SiteController;
use Modules\Analytics\Http\VisitorController;
use Symfony\Component\HttpFoundation\Request;

/**
 * First-party web analytics for the platform storefront.
 *
 * Payload shape, routes, settings keys and data model are frozen in `modules/Analytics/README.md`.
 * Phase 1 collects pseudonymous visitors, sessions, pageviews and events after cookie consent;
 * heatmap visualisation is a later phase.
 */
final class AnalyticsModule implements ModuleInterface
{
    private const VERSION = '0.3.1';

    public function id(): string
    {
        return 'analytics';
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('analytics.view', 'perm.analytics.view', 'perm.group.analytics');
        $context->permissions()->register('analytics.manage', 'perm.analytics.manage', 'perm.group.analytics');
        $context->permissions()->register('analytics.raw', 'perm.analytics.raw', 'perm.group.analytics');
        $context->permissions()->register('analytics.purge', 'perm.analytics.purge', 'perm.group.analytics');

        $context->capabilities()->register('analytics', $this->id(), $context->get(AnalyticsProvider::class));

        $this->registerSettings($context);
        $this->registerAdminRoutes($context);
        $this->registerPublicRoutes($context);
        $this->registerApi($context);
        $this->registerPlatformEvents($context);
        $this->registerValidation($context);
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'analytics',
            label: 'nav.analytics',
            icon: 'analytics',
            route: 'admin.analytics.index',
            permission: 'analytics.view',
            order: 62,
        ));

        $context->menu()->add(new MenuItem(
            id: 'analytics.overview',
            label: 'analytics.title.overview',
            route: 'admin.analytics.index',
            permission: 'analytics.view',
            parent: 'analytics',
            order: 10,
        ));
        $context->menu()->add(new MenuItem(
            id: 'analytics.sites',
            label: 'analytics.title.sites',
            route: 'admin.analytics.sites.index',
            permission: 'analytics.manage',
            parent: 'analytics',
            order: 20,
        ));
        $context->menu()->add(new MenuItem(
            id: 'analytics.settings',
            label: 'analytics.title.settings',
            route: 'admin.analytics.settings',
            permission: 'analytics.manage',
            parent: 'analytics',
            order: 30,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'analytics.index',
            label: 'palette.analytics',
            url: '/admin/analytics',
            icon: 'analytics',
            permission: 'analytics.view',
            keywords: ['analytika', 'analytics', 'návštěvnost', 'statistiky', 'statistics', 'traffic'],
        ));

        $this->registerAdminSearch($context);
        $this->registerWidgets($context);
        $this->registerHooks($context);
        $this->registerSchedule($context);
    }

    private function registerSettings(ModuleContext $context): void
    {
        $settings = $context->settings();
        $module = $this->id();

        foreach (AnalyticsConfig::settingDefinitions() as $key => $definition) {
            $settings->define(
                $key,
                $definition['type'],
                AnalyticsConfig::labelKey($key),
                $definition['default'],
                $module,
                [],
                false,
            );
        }
    }

    private function registerAdminRoutes(ModuleContext $context): void
    {
        $context->router()->group('/admin/analytics', function (Router $routes): void {
            $routes->get('', [OverviewController::class, 'index'])
                ->name('admin.analytics.index')->permission('analytics.view');
            $routes->get('/pages', [ReportController::class, 'pages'])
                ->name('admin.analytics.pages')->permission('analytics.view');
            $routes->get('/sources', [ReportController::class, 'sources'])
                ->name('admin.analytics.sources')->permission('analytics.view');
            $routes->get('/devices', [ReportController::class, 'devices'])
                ->name('admin.analytics.devices')->permission('analytics.view');
            $routes->get('/events', [ReportController::class, 'events'])
                ->name('admin.analytics.events')->permission('analytics.view');
            $routes->get('/forms', [BehaviorController::class, 'forms'])
                ->name('admin.analytics.forms')->permission('analytics.view');
            $routes->get('/recordings', [BehaviorController::class, 'recordings'])
                ->name('admin.analytics.recordings')->permission('analytics.raw');
            $routes->get('/recordings/{id}', [BehaviorController::class, 'show'])
                ->name('admin.analytics.recordings.show')->permission('analytics.raw');
            $routes->get('/recordings/{id}/data', [BehaviorController::class, 'data'])
                ->name('admin.analytics.recordings.data')->permission('analytics.raw');
            $routes->get('/visitors', [VisitorController::class, 'index'])
                ->name('admin.analytics.visitors')->permission('analytics.raw');
            $routes->get('/sites', [SiteController::class, 'index'])
                ->name('admin.analytics.sites.index')->permission('analytics.manage');
            $routes->get('/sites/create', [SiteController::class, 'create'])
                ->name('admin.analytics.sites.create')->permission('analytics.manage');
            $routes->post('/sites', [SiteController::class, 'store'])
                ->name('admin.analytics.sites.store')->permission('analytics.manage');
            $routes->get('/sites/{id}/edit', [SiteController::class, 'edit'])
                ->name('admin.analytics.sites.edit')->permission('analytics.manage');
            $routes->post('/sites/{id}', [SiteController::class, 'update'])
                ->name('admin.analytics.sites.update')->permission('analytics.manage');
            $routes->post('/sites/{id}/delete', [SiteController::class, 'destroy'])
                ->name('admin.analytics.sites.delete')->permission('analytics.manage');
            $routes->post('/sites/{id}/rotate-key', [SiteController::class, 'rotateSecret'])
                ->name('admin.analytics.sites.rotate')->permission('analytics.manage');
            $routes->get('/settings', [SettingsController::class, 'index'])
                ->name('admin.analytics.settings')->permission('analytics.manage');
            $routes->post('/settings', [SettingsController::class, 'save'])
                ->name('admin.analytics.settings.save')->permission('analytics.manage');
            $routes->post('/settings/purge-preview', [SettingsController::class, 'purgePreview'])
                ->name('admin.analytics.settings.purge-preview')->permission('analytics.purge');
        }, ['middleware' => ['auth'], 'module' => 'analytics']);
    }

    private function registerPublicRoutes(ModuleContext $context): void
    {
        $options = [PublicAnalyticsController::class, 'options'];

        $context->router()->add('/api/public/analytics/collect', ['OPTIONS'], $options)
            ->name('api.public.analytics.collect.options');
        $context->router()->post('/api/public/analytics/collect', [PublicAnalyticsController::class, 'collect'])
            ->name('api.public.analytics.collect');

        $context->router()->add('/api/public/analytics/consent', ['OPTIONS'], $options)
            ->name('api.public.analytics.consent.options');
        $context->router()->post('/api/public/analytics/consent', [PublicAnalyticsController::class, 'consent'])
            ->name('api.public.analytics.consent');

        $context->router()->get('/api/public/analytics/config', [PublicAnalyticsController::class, 'config'])
            ->name('api.public.analytics.config');
        $context->router()->get('/api/public/analytics/tracker.js', [PublicAnalyticsController::class, 'tracker'])
            ->name('api.public.analytics.tracker');
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->registerKnownScope('analytics.read');

        $context->api()->module('analytics', function (ApiModule $api): void {
            $api->get('/stats', [AnalyticsApiController::class, 'stats'])->scope('analytics.read');
            $api->get('/pages', [AnalyticsApiController::class, 'pages'])->scope('analytics.read');
            $api->get('/sources', [AnalyticsApiController::class, 'sources'])->scope('analytics.read');
            $api->get('/sites', [AnalyticsApiController::class, 'sites'])->scope('analytics.read');
        });
    }

    private function registerPlatformEvents(ModuleContext $context): void
    {
        $context->platformEvents()->register(
            (new EventDefinition('analytics.site.created', $this->id()))
                ->description('Analytics site created')
                ->webhookEnabled(true),
        );
        $context->platformEvents()->register(
            (new EventDefinition('analytics.site.updated', $this->id()))
                ->description('Analytics site updated')
                ->webhookEnabled(true),
        );
        $context->platformEvents()->register(
            (new EventDefinition('analytics.consent.revoked', $this->id()))
                ->description('Visitor revoked analytics consent')
                ->webhookEnabled(false),
        );
        $context->platformEvents()->register(
            (new EventDefinition('analytics.retention.purged', $this->id()))
                ->description('Analytics retention purge finished')
                ->webhookEnabled(false),
        );
    }

    private function registerValidation(ModuleContext $context): void
    {
        // Only scalar fields are validated by the ruleset; the structured payload parts
        // (consent / page / viewport / events) are validated defensively in IngestService,
        // because the Symfony-style validators stringify array values.
        $context->validation()->ruleset('analytics.collect', [
            'site' => 'nullable|max:64',
            'visitor' => 'nullable|max:190',
            'website_url' => 'nullable|max:190',
            'website' => 'nullable|max:190',
        ]);

        // Admin site form. `domains`, `excluded_paths` and `excluded_ips` are posted as multi-line
        // text (or repeated fields) and normalised in SiteController.
        $context->validation()->ruleset('analytics.site.save', [
            'name' => 'required|min:1|max:190',
            'timezone' => 'nullable|max:64',
            'retention_override_days' => 'nullable|integer|min:1|max:3650',
            'enabled' => 'nullable|boolean',
            'keep_bot_hits' => 'nullable|boolean',
            'respect_dnt' => 'nullable|boolean',
            'domains' => 'nullable|max:4000',
            'excluded_paths' => 'nullable|max:4000',
            'excluded_ips' => 'nullable|max:4000',
        ]);
        $context->forms()->entity('analytics.site.save', \Modules\Analytics\Domain\Site::class);
    }

    private function registerAdminSearch(ModuleContext $context): void
    {
        $sites = $context->get(SiteRepository::class);

        $context->adminSearch()->add(
            'analytics.sites',
            ['name', 'key', 'domains'],
            static function (string $query, int $limit) use ($sites): array {
                $needle = mb_strtolower($query);
                $hits = [];
                foreach ($sites->all() as $site) {
                    $name = (string) ($site['name'] ?? '');
                    $key = (string) ($site['key'] ?? '');
                    $domains = $site['domains'] ?? [];
                    $haystack = mb_strtolower($name . ' ' . $key . ' ' . implode(' ', is_array($domains) ? $domains : []));
                    if ($needle !== '' && !str_contains($haystack, $needle)) {
                        continue;
                    }
                    $hits[] = new AdminSearchHit(
                        id: 'analytics.site.' . (string) $site['id'],
                        label: $name !== '' ? $name : $key,
                        url: '/admin/analytics/sites/' . (string) $site['id'] . '/edit',
                        group: 'admin.search.analytics',
                        subtitle: $key,
                        icon: 'analytics',
                        keywords: array_values(array_filter([
                            $name,
                            $key,
                            ...array_map(static fn (mixed $d): string => (string) $d, is_array($domains) ? $domains : []),
                        ])),
                    );
                    if (count($hits) >= $limit) {
                        break;
                    }
                }

                return $hits;
            },
            permission: 'analytics.view',
            group: 'admin.search.analytics',
            icon: 'analytics',
        );
    }

    private function registerWidgets(ModuleContext $context): void
    {
        $context->dashboard()->addWidget($context->get(AnalyticsKpiWidget::class));
        $context->dashboard()->addWidget($context->get(AnalyticsTrendChartWidget::class));
    }

    private function registerHooks(ModuleContext $context): void
    {
        $injector = $context->get(TrackerInjector::class);

        // First-party tracker snippet on the storefront SPA shell (same pattern as WebPlus).
        $context->hooks()->addFilter(
            'storefront.spa_html',
            static function (string $html, mixed $request = null) use ($injector): string {
                if (!$request instanceof Request) {
                    return $html;
                }

                return $injector->inject($html, $request);
            },
        );
    }

    private function registerSchedule(ModuleContext $context): void
    {
        // Lock expiry is two hours: a crashed run must not suppress an hourly tick for a whole day
        // (the default lock is 1440 minutes), while a healthy run finishes far inside the window.
        $context->schedule()->job(new RollupAnalyticsJob())
            ->hourly()
            ->name('analytics.rollup')
            ->description('Roll up analytics day aggregates.')
            ->withoutOverlapping(120);

        $context->schedule()->job(new PurgeAnalyticsJob())
            ->dailyAt('03:20')
            ->timezone('Europe/Prague')
            ->name('analytics.purge')
            ->description('Purge analytics data past its retention window.')
            ->withoutOverlapping(120);
    }
}
