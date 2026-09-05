<?php

declare(strict_types=1);

namespace Modules\Orders\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Setting\Settings;
use Modules\Orders\Domain\Invoice;
use Modules\Orders\Domain\Order;

final class NumberSequencer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
    ) {
    }

    public function nextOrderNumber(): string
    {
        $prefix = (string) $this->settings->get('orders.number_prefix', 'ORD-');
        $year = (new \DateTimeImmutable())->format('Y');
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(Order::class, 'o')
            ->where('o.number LIKE :pattern')
            ->setParameter('pattern', $prefix . $year . '-%')
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('%s%s-%04d', $prefix, $year, $count + 1);
    }

    public function nextInvoiceNumber(): string
    {
        $prefix = (string) $this->settings->get('invoices.number_prefix', 'INV-');
        $year = (new \DateTimeImmutable())->format('Y');
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(Invoice::class, 'i')
            ->where('i.number LIKE :pattern')
            ->setParameter('pattern', $prefix . $year . '-%')
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('%s%s-%04d', $prefix, $year, $count + 1);
    }
}
