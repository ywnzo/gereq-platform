<?php

declare(strict_types=1);

namespace Modules\CRM\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\CRM\Domain\Deal;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CrmPipelineChartWidget extends TwigDashboardWidget
{
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
        return 'crm.pipeline.chart';
    }

    public function title(): string
    {
        return $this->translator->trans('admin.dashboard.chart.crm');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_PANEL;
    }

    public function permission(): ?string
    {
        return 'crm.view';
    }

    public function order(): int
    {
        return 19;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/chart_panel.html.twig';
    }

    protected function data(): array
    {
        $rows = $this->em->getRepository(Deal::class)->createQueryBuilder('d')
            ->select('d.stage AS stage, COUNT(d.id) AS cnt')
            ->groupBy('d.stage')
            ->getQuery()
            ->getArrayResult();

        $labels = [];
        $values = [];
        $total = 0;
        foreach ($rows as $row) {
            $stage = (string) ($row['stage'] ?? '');
            $count = (int) ($row['cnt'] ?? 0);
            if ($stage === '' || $count < 1) {
                continue;
            }
            $labels[] = $this->translator->trans('crm.stage.' . $stage);
            $values[] = $count;
            $total += $count;
        }

        return [
            'title' => $this->translator->trans('admin.dashboard.chart.crm'),
            'subtitle' => $this->translator->trans('admin.dashboard.chart.crm_sub'),
            'href' => $this->router->url('admin.crm.deals.index'),
            'link_label' => $this->translator->trans('nav.crm'),
            'wide' => false,
            'empty' => $total === 0,
            'empty_message' => $this->translator->trans('empty.generic'),
            'stats' => [
                ['label' => $this->translator->trans('admin.dashboard.chart.total'), 'value' => (string) $total],
            ],
            'config' => [
                'type' => 'doughnut',
                'labels' => $labels,
                'datasets' => [[
                    'label' => $this->translator->trans('nav.crm'),
                    'data' => $values,
                    'kind' => 'doughnut',
                    'colors' => ['#47c2ff', '#818cf8', '#34d399', '#fbbf24', '#f87171'],
                ]],
            ],
        ];
    }
}
