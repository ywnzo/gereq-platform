<?php

declare(strict_types=1);

namespace Modules\Reviews\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\Reviews\Domain\ProductReview;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Star rating distribution of approved reviews. */
final class ReviewRatingChartWidget extends TwigDashboardWidget
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
        return 'reviews.rating.chart';
    }

    public function title(): string
    {
        return $this->translator->trans('reviews.widget.rating.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_PANEL;
    }

    public function permission(): ?string
    {
        return 'reviews.view';
    }

    public function order(): int
    {
        return 27;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/chart_panel.html.twig';
    }

    protected function data(): array
    {
        $rows = $this->em->getRepository(ProductReview::class)->createQueryBuilder('r')
            ->select('r.rating AS rating, COUNT(r.id) AS cnt')
            ->where('r.status = :approved')
            ->setParameter('approved', 'approved')
            ->groupBy('r.rating')
            ->getQuery()
            ->getArrayResult();

        $buckets = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $total = 0;
        $sum = 0;
        foreach ($rows as $row) {
            $rating = (int) ($row['rating'] ?? 0);
            $count = (int) ($row['cnt'] ?? 0);
            if (!isset($buckets[$rating])) {
                continue;
            }
            $buckets[$rating] = $count;
            $total += $count;
            $sum += $rating * $count;
        }

        $labels = array_map(
            fn (int $stars): string => $stars . ' ' . $this->translator->trans('reviews.widget.rating.stars'),
            array_keys($buckets),
        );

        return [
            'title' => $this->translator->trans('reviews.widget.rating.title'),
            'subtitle' => $this->translator->trans('reviews.widget.rating.subtitle'),
            'href' => $this->router->url('admin.reviews.index'),
            'link_label' => $this->translator->trans('nav.reviews'),
            'wide' => false,
            'empty' => $total === 0,
            'empty_message' => $this->translator->trans('reviews.widget.stats.no_approved'),
            'stats' => [
                ['label' => $this->translator->trans('admin.dashboard.chart.total'), 'value' => (string) $total],
                [
                    'label' => $this->translator->trans('reviews.widget.rating.average'),
                    'value' => $total > 0 ? number_format($sum / $total, 1, ',', '') . ' ★' : '—',
                ],
            ],
            'config' => [
                'type' => 'bar',
                'labels' => $labels,
                'datasets' => [[
                    'label' => $this->translator->trans('nav.reviews'),
                    'data' => array_values($buckets),
                    'kind' => 'bar',
                    'color' => '#fbbf24',
                ]],
            ],
        ];
    }
}
