<?php

declare(strict_types=1);

namespace Modules\Customers\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Modules\Customers\Domain\Customer;

final class CustomerStatsWidget implements DashboardWidget
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function id(): string
    {
        return 'customers.stats';
    }

    public function title(): string
    {
        return 'Zákazníci';
    }

    public function render(): string
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

        return '<div><span>Zákazníci</span><strong>'
            . $total . '</strong><span class="muted">' . $active . ' aktivních</span></div>';
    }

    public function permission(): string
    {
        return 'customers.view';
    }

    public function order(): int
    {
        return 20;
    }
}
