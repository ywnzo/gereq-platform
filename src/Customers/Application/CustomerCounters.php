<?php

declare(strict_types=1);

namespace Gereq\Customers\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\Authenticator;
use Gereq\Customers\Domain\Customer;
use Modules\Listings\Application\BrokerIdentity;

/** Request-scoped counters for dashboard widgets (avoids duplicate COUNT queries). */
final class CustomerCounters
{
    private ?int $total = null;

    private ?int $active = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly BrokerIdentity $brokerIdentity,
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
        $this->brokerIdentity->applyCustomerScope($qb, 'c', $this->auth->user());

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
        $this->brokerIdentity->applyCustomerScope($qb, 'c', $this->auth->user());

        return $this->active = (int) $qb->getQuery()->getSingleScalarResult();
    }
}
