<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\Analytics\Application\AnalyticsConfig;
use Modules\Analytics\Application\Repositories\ReportRepository;
use Modules\Analytics\Application\Repositories\SiteRepository;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Dashboard KPI card: pseudonymous visitors and pageviews of the last 14 days
 * for the primary tracked site.
 */
final class AnalyticsKpiWidget extends TwigDashboardWidget
{
    /** Matches the `widget.analytics.kpi.title` label ("Návštěvnost (7 dní)"). */
    private const DAYS = 7;

    public function __construct(
        Environment $twig,
        private readonly SiteRepository $sites,
        private readonly AnalyticsConfig $config,
        private readonly ReportRepository $reports,
        private readonly TranslatorInterface $translator,
        private readonly Router $router,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'analytics.kpi';
    }

    public function title(): string
    {
        return $this->translator->trans('widget.analytics.kpi.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_METRIC;
    }

    public function permission(): ?string
    {
        return 'analytics.view';
    }

    public function order(): int
    {
        return 62;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/metric.html.twig';
    }

    protected function data(): array
    {
        $label = $this->translator->trans('widget.analytics.kpi.title');
        $href = $this->router->url('admin.analytics.index');

        try {
            $site = $this->primarySite();
            if ($site === null || !$this->config->trackerEnabled($site)) {
                return ['label' => $label, 'value' => '—', 'href' => $href];
            }

            $to = new \DateTimeImmutable('today');
            $kpi = $this->reports->kpi(
                (string) $site['id'],
                $to->modify('-' . (self::DAYS - 1) . ' days')->format('Y-m-d'),
                $to->format('Y-m-d'),
            );

            return [
                'label' => $label,
                'value' => number_format($kpi['visitors'], 0, ',', ' '),
                'hint' => $this->translator->trans('analytics.kpi.pageviews')
                    . ': ' . number_format($kpi['pageviews'], 0, ',', ' '),
                'href' => $href,
            ];
        } catch (\Throwable) {
            // Dashboard must render even before the module tables exist.
            return ['label' => $label, 'value' => '—', 'href' => $href];
        }
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
