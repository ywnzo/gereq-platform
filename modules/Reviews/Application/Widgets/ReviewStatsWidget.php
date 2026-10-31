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

/** Reviews pending approval plus average rating of approved ones. */
final class ReviewStatsWidget extends TwigDashboardWidget
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
        return 'reviews.stats';
    }

    public function title(): string
    {
        return $this->translator->trans('reviews.widget.stats.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_METRIC;
    }

    public function permission(): ?string
    {
        return 'reviews.view';
    }

    public function order(): int
    {
        return 54;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/metric.html.twig';
    }

    protected function data(): array
    {
        $repo = $this->em->getRepository(ProductReview::class);

        $pending = (int) $repo->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.status = :pending')
            ->setParameter('pending', 'pending')
            ->getQuery()
            ->getSingleScalarResult();

        $approved = $repo->createQueryBuilder('r')
            ->select('COUNT(r.id) AS cnt, AVG(r.rating) AS avg')
            ->where('r.status = :approved')
            ->setParameter('approved', 'approved')
            ->getQuery()
            ->getSingleResult();

        $approvedCount = (int) ($approved['cnt'] ?? 0);
        $average = $approvedCount > 0 ? round((float) ($approved['avg'] ?? 0), 1) : null;

        return [
            'label' => $this->translator->trans('reviews.widget.stats.title'),
            'value' => (string) $pending,
            'hint' => $average !== null
                ? $this->translator->trans('reviews.widget.stats.hint', [
                    '%avg%' => number_format($average, 1, ',', ''),
                    '%count%' => (string) $approvedCount,
                ])
                : $this->translator->trans('reviews.widget.stats.no_approved'),
            'href' => $this->router->url('admin.reviews.index'),
        ];
    }
}
