<?php

declare(strict_types=1);

namespace Gereq\Admin\Dashboard\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\ChartSeries;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Audit\AuditLog;
use Gereq\Http\Router;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ActivityChartWidget extends TwigDashboardWidget
{
    private const DAYS = 14;

    public function __construct(
        Environment $twig,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly Router $router,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'core.activity.chart';
    }

    public function title(): string
    {
        return $this->translator->trans('admin.dashboard.chart.activity');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_PANEL;
    }

    public function permission(): ?string
    {
        return 'audit.view';
    }

    public function order(): int
    {
        return 12;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/chart_panel.html.twig';
    }

    protected function data(): array
    {
        $days = ChartSeries::dayLabels(self::DAYS);
        $since = new \DateTimeImmutable($days[0] . ' 00:00:00');
        $rows = $this->em->getRepository(AuditLog::class)->createQueryBuilder('a')
            ->select('a.createdAt AS createdAt')
            ->where('a.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getArrayResult();

        $timestamps = [];
        foreach ($rows as $row) {
            $value = $row['createdAt'] ?? null;
            if ($value instanceof \DateTimeInterface) {
                $timestamps[] = $value;
            } elseif (is_string($value) && $value !== '') {
                try {
                    $timestamps[] = new \DateTimeImmutable($value);
                } catch (\Throwable) {
                }
            }
        }

        $values = ChartSeries::countsPerDay($days, $timestamps);
        $total = array_sum($values);
        $peak = $values === [] ? 0 : max($values);

        return [
            'title' => $this->translator->trans('admin.dashboard.chart.activity'),
            'subtitle' => $this->translator->trans('admin.dashboard.chart.activity_sub', ['%days%' => (string) self::DAYS]),
            'href' => $this->router->url('admin.audit.index'),
            'link_label' => $this->translator->trans('common.all'),
            'wide' => true,
            'empty' => $total === 0,
            'empty_message' => $this->translator->trans('empty.activity'),
            'stats' => [
                ['label' => $this->translator->trans('admin.dashboard.chart.total'), 'value' => (string) $total],
                ['label' => $this->translator->trans('admin.dashboard.chart.peak'), 'value' => (string) $peak],
            ],
            'config' => [
                'type' => 'bar',
                'labels' => ChartSeries::shortLabels($days),
                'datasets' => [[
                    'label' => $this->translator->trans('admin.dashboard.chart.events'),
                    'data' => $values,
                    'kind' => 'bar',
                ]],
            ],
        ];
    }
}
