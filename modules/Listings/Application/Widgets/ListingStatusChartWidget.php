<?php

declare(strict_types=1);

namespace Modules\Listings\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\Listings\Domain\Listing;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ListingStatusChartWidget extends TwigDashboardWidget
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
        return 'listings.status.chart';
    }

    public function title(): string
    {
        return $this->translator->trans('admin.dashboard.chart.listings');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_PANEL;
    }

    public function permission(): ?string
    {
        return 'listings.view';
    }

    public function order(): int
    {
        return 17;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/chart_panel.html.twig';
    }

    protected function data(): array
    {
        $rows = $this->em->getRepository(Listing::class)->createQueryBuilder('l')
            ->select('l.status AS status, COUNT(l.id) AS cnt')
            ->groupBy('l.status')
            ->getQuery()
            ->getArrayResult();

        $labels = [];
        $values = [];
        $total = 0;
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            $count = (int) ($row['cnt'] ?? 0);
            if ($status === '' || $count < 1) {
                continue;
            }
            $labels[] = $this->translator->trans('listings.status.' . $status);
            $values[] = $count;
            $total += $count;
        }

        return [
            'title' => $this->translator->trans('admin.dashboard.chart.listings'),
            'subtitle' => $this->translator->trans('admin.dashboard.chart.listings_sub'),
            'href' => $this->router->url('admin.listings.index'),
            'link_label' => $this->translator->trans('nav.listings'),
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
                    'label' => $this->translator->trans('nav.listings'),
                    'data' => $values,
                    'kind' => 'doughnut',
                    'colors' => ['#47c2ff', '#34d399', '#94a3b8', '#fbbf24'],
                ]],
            ],
        ];
    }
}
