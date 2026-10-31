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
 * Dimension reports: pages, sources (referrers), devices and events.
 *
 * Every action renders the same `rows` / `chart` / `columns` contract so the shared report
 * template renders them with one table + one chart component.
 */
final class ReportController extends AdminPageController
{
    private const ROWS = 25;

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

    public function pages(Request $request): Response
    {
        return $this->report($request, '@analytics/reports/pages.html.twig', 'analytics.title.pages', function (string $siteId, string $from, string $to): array {
            $rows = $this->safe(fn (): array => $this->reports->topPaths($siteId, $from, $to, self::ROWS), []);

            return [
                'rows' => array_map(fn (array $row): array => [
                    'value' => $row['value'],
                    'pageviews' => $row['pageviews'],
                    'visitors' => $row['visitors'],
                    'engaged' => $this->duration((int) $row['engaged_seconds']),
                ], $rows),
                'chart' => $this->rankingChart($this->charts, $rows, 'pageviews', $this->translator->trans('analytics.kpi.pageviews')),
                'columns' => $this->columns([
                    ['key' => 'value', 'label' => 'analytics.title.pages'],
                    ['key' => 'pageviews', 'label' => 'analytics.kpi.pageviews', 'numeric' => true],
                    ['key' => 'visitors', 'label' => 'analytics.kpi.visitors', 'numeric' => true],
                    ['key' => 'engaged', 'label' => 'analytics.kpi.avg_duration', 'numeric' => true],
                ]),
            ];
        });
    }

    public function sources(Request $request): Response
    {
        return $this->report($request, '@analytics/reports/sources.html.twig', 'analytics.title.sources', function (string $siteId, string $from, string $to): array {
            $rows = $this->safe(fn (): array => $this->reports->topReferrers($siteId, $from, $to, self::ROWS), []);

            return [
                'rows' => array_map(fn (array $row): array => [
                    'value' => $row['value'],
                    'pageviews' => $row['pageviews'],
                    'visitors' => $row['visitors'],
                    'engaged' => $this->duration((int) $row['engaged_seconds']),
                ], $rows),
                'chart' => $this->rankingChart($this->charts, $rows, 'pageviews', $this->translator->trans('analytics.title.sources'), '#a78bfa'),
                'columns' => $this->columns([
                    ['key' => 'value', 'label' => 'analytics.title.sources'],
                    ['key' => 'pageviews', 'label' => 'analytics.kpi.pageviews', 'numeric' => true],
                    ['key' => 'visitors', 'label' => 'analytics.kpi.visitors', 'numeric' => true],
                    ['key' => 'engaged', 'label' => 'analytics.kpi.avg_duration', 'numeric' => true],
                ]),
            ];
        });
    }

    public function devices(Request $request): Response
    {
        return $this->report($request, '@analytics/reports/devices.html.twig', 'analytics.title.devices', function (string $siteId, string $from, string $to): array {
            $rows = $this->safe(fn (): array => $this->reports->topDevices($siteId, $from, $to, self::ROWS), []);

            return [
                'rows' => array_map(static fn (array $row): array => [
                    'value' => $row['value'],
                    'sessions' => $row['sessions'],
                    'visitors' => $row['visitors'],
                    'pageviews' => $row['pageviews'],
                    'events' => $row['events'],
                ], $rows),
                'chart' => $this->rankingChart($this->charts, $rows, 'sessions', $this->translator->trans('analytics.kpi.sessions'), '#f59e0b'),
                'columns' => $this->columns([
                    ['key' => 'value', 'label' => 'analytics.title.devices'],
                    ['key' => 'sessions', 'label' => 'analytics.kpi.sessions', 'numeric' => true],
                    ['key' => 'visitors', 'label' => 'analytics.kpi.visitors', 'numeric' => true],
                    ['key' => 'pageviews', 'label' => 'analytics.kpi.pageviews', 'numeric' => true],
                    ['key' => 'events', 'label' => 'analytics.kpi.events', 'numeric' => true],
                ]),
            ];
        });
    }

    public function events(Request $request): Response
    {
        return $this->report($request, '@analytics/reports/events.html.twig', 'analytics.title.events', function (string $siteId, string $from, string $to): array {
            $rows = $this->safe(fn (): array => $this->reports->topEvents($siteId, $from, $to, self::ROWS), []);

            return [
                'rows' => array_map(static fn (array $row): array => [
                    'value' => $row['value'],
                    'events' => $row['events'],
                    'visitors' => $row['visitors'],
                ], $rows),
                'chart' => $this->rankingChart($this->charts, $rows, 'events', $this->translator->trans('analytics.kpi.events'), '#34d399'),
                'columns' => $this->columns([
                    ['key' => 'value', 'label' => 'analytics.title.events'],
                    ['key' => 'events', 'label' => 'analytics.kpi.events', 'numeric' => true],
                    ['key' => 'visitors', 'label' => 'analytics.kpi.visitors', 'numeric' => true],
                ]),
            ];
        });
    }

    /**
     * @param callable(string, string, string): array{rows: list<array<string, mixed>>, chart: array<string, mixed>, columns: list<array{key: string, label: string, numeric: bool}>} $builder
     */
    private function report(Request $request, string $view, string $titleKey, callable $builder): Response
    {
        $range = $this->reportRange($request, 30);
        $site = $this->selectedSite($request, $this->allSites($this->sites));
        $payload = ['rows' => [], 'chart' => [], 'columns' => []];

        if ($site !== null) {
            $payload = $builder((string) $site['id'], $range->from, $range->to);
        }

        return $this->adminPage($view, [
            'pageTitle' => $this->translator->trans($titleKey),
            'site' => $site !== null ? AnalyticsProvider::publicSite($site) : null,
            'range' => $range->toArray(),
            'rows' => $payload['rows'],
            'chart' => $payload['chart'],
            'columns' => $payload['columns'],
        ]);
    }
}
