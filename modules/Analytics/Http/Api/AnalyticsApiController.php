<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Api;

use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Analytics\Application\AnalyticsProvider;
use Modules\Analytics\Application\Repositories\RollupRepository;
use Modules\Analytics\Application\Repositories\SiteRepository;
use Modules\Analytics\Application\ReportRange;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * Internal read-only API (`/api/v1/analytics`, scope `analytics.read`).
 *
 * Phase 1 exposes aggregates only — no writes, no site management (that stays in the admin UI).
 * Numbers come from the daily rollup tables so long ranges keep working after the raw traffic
 * retention window has purged pageviews, events and sessions.
 *
 * Whitelisted filters: `site` (id or tracker key), `range`, `from`, `to`, `days`, `limit`.
 */
final class AnalyticsApiController extends Controller
{
    /** @var list<string> */
    private const FILTERS = ['site', 'range', 'from', 'to', 'days', 'limit'];

    private const DEFAULT_DAYS = 30;

    private const MAX_LIMIT = 200;

    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly SiteRepository $sites,
        private readonly RollupRepository $rollups,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function stats(Request $request): JsonResponse
    {
        $siteIds = $this->siteIds($request);
        if ($siteIds === null) {
            return ApiResponse::notFound('site_not_found', 'Tracked site was not found.');
        }

        $range = $this->range($request);
        $series = $this->rollups->statsRange($siteIds, $range->from, $range->to);

        $totals = [
            'visitors' => 0,
            'new_visitors' => 0,
            'sessions' => 0,
            'bounces' => 0,
            'pageviews' => 0,
            'events' => 0,
            'total_duration_seconds' => 0,
        ];
        foreach ($series as $row) {
            $totals['visitors'] += $row['visitors'];
            $totals['new_visitors'] += $row['new_visitors'];
            $totals['sessions'] += $row['sessions'];
            $totals['bounces'] += $row['bounces'];
            $totals['pageviews'] += $row['pageviews'];
            $totals['events'] += $row['events'];
            $totals['total_duration_seconds'] += $row['total_duration_seconds'];
        }
        $totals['bounce_rate'] = $totals['sessions'] > 0
            ? round($totals['bounces'] / $totals['sessions'], 4)
            : 0.0;
        $totals['avg_duration_seconds'] = $totals['sessions'] > 0
            ? (int) round($totals['total_duration_seconds'] / $totals['sessions'])
            : 0;

        return ApiResponse::data([
            'range' => $range->toArray(),
            'sites' => $siteIds,
            'totals' => $totals,
            'series' => $series,
        ]);
    }

    public function pages(Request $request): JsonResponse
    {
        return $this->dimension($request, 'path');
    }

    public function sources(Request $request): JsonResponse
    {
        return $this->dimension($request, 'source');
    }

    public function sites(Request $request): JsonResponse
    {
        $range = $this->range($request);
        $items = [];
        foreach ($this->allSiteIds() as $site) {
            $stats = $this->rollups->statsRange([(string) $site['id']], $range->from, $range->to);
            $pageviews = 0;
            $visitors = 0;
            $sessions = 0;
            foreach ($stats as $row) {
                $pageviews += $row['pageviews'];
                $visitors += $row['visitors'];
                $sessions += $row['sessions'];
            }
            $items[] = [
                ...AnalyticsProvider::publicSite($site),
                'totals' => [
                    'visitors' => $visitors,
                    'sessions' => $sessions,
                    'pageviews' => $pageviews,
                ],
            ];
        }

        return ApiResponse::collection($items, [
            'range' => $range->toArray(),
            'filters' => self::FILTERS,
        ]);
    }

    private function dimension(Request $request, string $dimension): JsonResponse
    {
        $siteIds = $this->siteIds($request);
        if ($siteIds === null) {
            return ApiResponse::notFound('site_not_found', 'Tracked site was not found.');
        }

        $range = $this->range($request);
        $rows = $this->rollups->dimensionTop($siteIds, $dimension, $range->from, $range->to, $this->limit($request));

        return ApiResponse::collection($rows, [
            'range' => $range->toArray(),
            'dimension' => $dimension,
            'sites' => $siteIds,
        ]);
    }

    private function range(Request $request): ReportRange
    {
        $days = $request->query->get('days');
        $defaultDays = is_numeric($days)
            ? max(1, min(ReportRange::MAX_DAYS, (int) $days))
            : self::DEFAULT_DAYS;

        return ReportRange::resolve(
            $request->query->get('range'),
            $request->query->get('from'),
            $request->query->get('to'),
            $defaultDays,
        );
    }

    private function limit(Request $request): int
    {
        $limit = $request->query->get('limit');

        return is_numeric($limit) ? max(1, min(self::MAX_LIMIT, (int) $limit)) : 20;
    }

    /**
     * Site ids for the `site` filter (tracker key or id). Null when an explicit filter matched nothing.
     *
     * @return list<string>|null
     */
    private function siteIds(Request $request): ?array
    {
        $filter = trim((string) $request->query->get('site', ''));
        if ($filter === '') {
            return array_map(static fn (array $site): string => (string) $site['id'], $this->allSiteIds());
        }

        $site = $this->sites->find($filter) ?? $this->sites->findEnabledByKey($filter);
        if ($site === null) {
            return null;
        }

        return [(string) $site['id']];
    }

    /** @return list<array<string, mixed>> */
    private function allSiteIds(): array
    {
        try {
            return $this->sites->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
