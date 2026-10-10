<?php

declare(strict_types=1);

namespace Gereq\Customers\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\Authenticator;
use Gereq\Customers\Domain\Customer;
use Gereq\Customers\Application\CustomerScope;

/** Request-scoped counters for dashboard widgets (avoids duplicate COUNT queries). */
final class CustomerCounters
{
    private ?int $total = null;

    private ?int $active = null;

    private ?int $erasure = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly CustomerScope $customerScope,
    ) {
    }

    public function total(): int
    {
        if ($this->total !== null) {
            return $this->total;
        }

        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Customer::class, 'c');
        $this->customerScope->applyCustomerScope($qb, 'c', $this->auth->user());

        return $this->total = (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function active(): int
    {
        if ($this->active !== null) {
            return $this->active;
        }

        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Customer::class, 'c')
            ->where('c.status = :status')
            ->setParameter('status', 'active');
        $this->customerScope->applyCustomerScope($qb, 'c', $this->auth->user());

        return $this->active = (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function erasure(): int
    {
        if ($this->erasure !== null) {
            return $this->erasure;
        }

        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Customer::class, 'c')
            ->where('c.erasureRequestedAt IS NOT NULL');
        $this->customerScope->applyCustomerScope($qb, 'c', $this->auth->user());

        return $this->erasure = (int) $qb->getQuery()->getSingleScalarResult();
    }
}
