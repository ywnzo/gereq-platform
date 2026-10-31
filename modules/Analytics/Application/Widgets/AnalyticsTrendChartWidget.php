<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\Analytics\Application\AnalyticsConfig;
use Modules\Analytics\Application\ChartConfig;
use Modules\Analytics\Application\Repositories\ReportRepository;
use Modules\Analytics\Application\Repositories\SiteRepository;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Dashboard line chart: visitors / pageviews / events of the primary tracked site.
 *
 * The config comes from {@see ChartConfig}, so the shared `chart_panel` widget and the
 * `gereqChart` Alpine factory can render it without module JS.
 */
final class AnalyticsTrendChartWidget extends TwigDashboardWidget
{
    private const DAYS = 14;

    public function __construct(
        Environment $twig,
        private readonly SiteRepository $sites,
        private readonly AnalyticsConfig $config,
        private readonly ReportRepository $reports,
        private readonly ChartConfig $charts,
        private readonly TranslatorInterface $translator,
        private readonly Router $router,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'analytics.trend.chart';
    }

    public function title(): string
    {
        return $this->translator->trans('widget.analytics.trend.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_PANEL;
    }

    public function permission(): ?string
    {
        return 'analytics.view';
    }

    public function order(): int
    {
        return 63;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/chart_panel.html.twig';
    }

    protected function data(): array
    {
        $title = $this->translator->trans('widget.analytics.trend.title');
        $href = $this->router->url('admin.analytics.index');

        try {
            $site = $this->primarySite();
            if ($site === null || !$this->config->trackerEnabled($site)) {
                return $this->emptyState($title, $href);
            }

            $to = new \DateTimeImmutable('today');
            $series = $this->reports->trend(
                (string) $site['id'],
                $to->modify('-' . (self::DAYS - 1) . ' days')->format('Y-m-d'),
                $to->format('Y-m-d'),
            );

            $visitors = array_sum(array_map(static fn (array $row): int => (int) $row['visitors'], $series));
            $pageviews = array_sum(array_map(static fn (array $row): int => (int) $row['pageviews'], $series));
            $events = array_sum(array_map(static fn (array $row): int => (int) $row['events'], $series));

            return [
                'title' => $title,
                'subtitle' => (string) ($site['name'] ?? ''),
                'href' => $href,
                'link_label' => $this->translator->trans('analytics.title.overview'),
                'wide' => true,
                'empty' => $visitors + $pageviews + $events === 0,
                'empty_message' => $this->translator->trans('analytics.title.overview'),
                'stats' => [
                    ['label' => $this->translator->trans('analytics.kpi.visitors'), 'value' => (string) $visitors],
                    ['label' => $this->translator->trans('analytics.kpi.pageviews'), 'value' => (string) $pageviews],
                    ['label' => $this->translator->trans('analytics.kpi.events'), 'value' => (string) $events],
                ],
                'config' => $this->charts->trend($series),
            ];
        } catch (\Throwable) {
            // Dashboard must render even before the module tables exist.
            return $this->emptyState($title, $href);
        }
    }

    /** @return array<string, mixed> */
    private function emptyState(string $title, string $href): array
    {
        return [
            'title' => $title,
            'href' => $href,
            'empty' => true,
            'empty_message' => $this->translator->trans('analytics.title.overview'),
            'config' => [],
        ];
    }

    /** @return array<string, mixed>|null */
    private function primarySite(): ?array
    {
        $sites = $this->sites->all();
        foreach ($sites as $site) {
            if (($site['enabled'] ?? false) === true) {
                return $site;
            }
        }

        return $sites[0] ?? null;
    }
}
