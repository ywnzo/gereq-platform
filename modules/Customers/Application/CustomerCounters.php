<?php

declare(strict_types=1);

namespace Modules\Customers\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Customers\Domain\Customer;

/** Request-scoped counters for dashboard widgets (avoids duplicate COUNT queries). */
final class CustomerCounters
{
    private ?int $total = null;

    private ?int $active = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function total(): int
    {
        return $this->total ??= (int) $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Customer::class, 'c')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function active(): int
    {
        return $this->active ??= (int) $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Customer::class, 'c')
            ->where('c.status = :status')
            ->setParameter('status', 'active')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
