<?php

declare(strict_types=1);

namespace Modules\Customers\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\Customers\Domain\Customer;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CustomerStatsWidget extends TwigDashboardWidget
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
        return 'customers.stats';
    }

    public function title(): string
    {
        return $this->translator->trans('customers.widget.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_METRIC;
    }

    public function permission(): ?string
    {
        return 'customers.view';
    }

    public function order(): int
    {
        return 40;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/metric.html.twig';
    }

    protected function data(): array
    {
        $total = (int) $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Customer::class, 'c')
            ->getQuery()
            ->getSingleScalarResult();

        $active = (int) $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Customer::class, 'c')
            ->where('c.status = :status')
            ->setParameter('status', 'active')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'label' => $this->translator->trans('customers.widget.title'),
            'value' => (string) $total,
            'hint' => $this->translator->trans('customers.widget.active', ['%count%' => (string) $active]),
            'href' => $this->router->url('admin.customers.index'),
        ];
    }
}
