<?php

declare(strict_types=1);

namespace Modules\Orders\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\Orders\Domain\Order;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class OrderStatsWidget extends TwigDashboardWidget
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
        return 'orders.stats';
    }

    public function title(): string
    {
        return $this->translator->trans('nav.orders');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_METRIC;
    }

    public function permission(): ?string
    {
        return 'orders.view';
    }

    public function order(): int
    {
        return 30;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/metric.html.twig';
    }

    protected function data(): array
    {
        $repo = $this->em->getRepository(Order::class);
        $total = (int) $repo->createQueryBuilder('o')->select('COUNT(o.id)')->getQuery()->getSingleScalarResult();
        $open = (int) $repo->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.status IN (:statuses)')
            ->setParameter('statuses', ['draft', 'confirmed'])
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'label' => $this->translator->trans('nav.orders'),
            'value' => (string) $total,
            'hint' => $this->translator->trans('admin.dashboard.open_orders') . ': ' . $open,
            'href' => $this->router->url('admin.orders.index'),
        ];
    }
}
