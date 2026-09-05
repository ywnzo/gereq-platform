<?php

declare(strict_types=1);

namespace Modules\Customers\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Modules\Customers\Domain\Customer;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CustomerStatsWidget implements DashboardWidget
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function id(): string
    {
        return 'customers.stats';
    }

    public function title(): string
    {
        return $this->translator->trans('customers.widget.title');
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

        return '<div class="metric-card"><span class="metric-card__label">' . htmlspecialchars($this->translator->trans('customers.widget.title'), ENT_QUOTES, 'UTF-8')
            . '</span><strong class="metric-card__value">'
            . $total . '</strong><span class="muted">'
            . htmlspecialchars($this->translator->trans('customers.widget.active', ['%count%' => (string) $active]), ENT_QUOTES, 'UTF-8')
            . '</span></div>';
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
