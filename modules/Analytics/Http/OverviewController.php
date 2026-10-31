<?php

declare(strict_types=1);

namespace Modules\Analytics\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Router;
use Modules\Analytics\Application\AnalyticsProvider;
use Modules\Analytics\Application\ChartConfig;
use Modules\Analytics\Application\Repositories\ReportRepository;
use Modules\Analytics\Application\Repositories\SiteRepository;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * `admin.analytics.index` — overview: KPI cards, trend chart and the top dimensions.
 */
final class OverviewController extends AdminPageController
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        Authenticator $auth,
        Menu $menu,
        CommandPalette $palette,
        TranslatorInterface $translator,
        private readonly SiteRepository $sites,
        private readonly ReportRepository $reports,
        private readonly ChartConfig $charts,
    ) {
        parent::__construct($twig, $router, $requestStack, $auth, $menu, $palette, $translator);
    }

    public function index(Request $request): Response
    {
        $range = $this->reportRange($request, 30);
        $sites = $this->allSites($this->sites);
        $site = $this->selectedSite($request, $sites);
        $siteId = $site !== null ? (string) $site['id'] : null;

        $series = $siteId !== null
            ? $this->safe(fn (): array => $this->reports->trend($siteId, $range->from, $range->to), [])
            : [];
        $kpi = $siteId !== null
            ? $this->safe(fn (): array => $this->reports->kpi($siteId, $range->from, $range->to), self::emptyKpi())
            : self::emptyKpi();

        return $this->adminPage('@analytics/overview.html.twig', [
            'pageTitle' => $this->translator->trans('analytics.title.overview'),
            'sites' => array_map(static fn (array $row): array => self::siteOption($row), $sites),
            'site' => $site !== null ? AnalyticsProvider::publicSite($site) : null,
            'range' => $range->toArray(),
            'kpi' => $this->kpiCards($kpi),
            'trend' => $series !== [] ? $this->charts->trend($series) : [],
            'topPages' => $siteId !== null
                ? $this->safe(fn (): array => $this->reports->topPaths($siteId, $range->from, $range->to, 10), [])
                : [],
            'topSources' => $siteId !== null
                ? $this->safe(fn (): array => $this->reports->topSources($siteId, $range->from, $range->to, 10), [])
                : [],
            'topDevices' => $siteId !== null
                ? $this->safe(fn (): array => $this->reports->topDevices($siteId, $range->from, $range->to, 10), [])
                : [],
            'topEvents' => $siteId !== null
                ? $this->safe(fn (): array => $this->reports->topEvents($siteId, $range->from, $range->to, 10), [])
                : [],
        ]);
    }

    /**
     * Metric cards. Keys match the `analytics.kpi.*` labels the overview renders
     * (`@analytics/partials/_macros.html.twig::kpi_row`), values are preformatted strings/ints.
     *
     * @param array{visitors: int, new_visitors: int, sessions: int, bounces: int, bounce_rate: float, pageviews: int, events: int, avg_duration_seconds: int, total_duration_seconds: int} $kpi
     * @return array<string, int|string>
     */
    private function kpiCards(array $kpi): array
    {
        return [
            'visitors' => $kpi['visitors'],
            'new_visitors' => $kpi['new_visitors'],
            'sessions' => $kpi['sessions'],
            'pageviews' => $kpi['pageviews'],
            'events' => $kpi['events'],
            'bounces' => $kpi['bounces'],
            'bounce_rate' => (int) round($kpi['bounce_rate'] * 100) . ' %',
            'avg_duration' => $this->duration($kpi['avg_duration_seconds']),
        ];
    }

    /**
     * Site selector entry — never carries the HMAC secret.
     *
     * @param array<string, mixed> $site
     * @return array{id: string, name: string, key: string, enabled: bool, domains: list<string>}
     */
    private static function siteOption(array $site): array
    {
        $domains = $site['domains'] ?? [];

        return [
            'id' => (string) ($site['id'] ?? ''),
            'name' => (string) ($site['name'] ?? ''),
            'key' => (string) ($site['key'] ?? ''),
            'enabled' => ($site['enabled'] ?? false) === true,
            'domains' => is_array($domains) ? array_values(array_map('strval', $domains)) : [],
        ];
    }

    /** @return array{visitors: int, new_visitors: int, sessions: int, bounces: int, bounce_rate: float, pageviews: int, events: int, avg_duration_seconds: int, total_duration_seconds: int} */
    private static function emptyKpi(): array
    {
        return [
            'visitors' => 0,
            'new_visitors' => 0,
            'sessions' => 0,
            'bounces' => 0,
            'bounce_rate' => 0.0,
            'pageviews' => 0,
            'events' => 0,
            'avg_duration_seconds' => 0,
            'total_duration_seconds' => 0,
        ];
    }
}
