<?php

declare(strict_types=1);

namespace Modules\Customers\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Customers\Domain\Customer;

final class CustomersProvider implements ContactsProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function find(string $id): ?ContactReference
    {
        $customer = $this->em->find(Customer::class, $id);
        if (!$customer instanceof Customer) {
            return null;
        }

        return new ContactReference($customer->id(), $customer->name(), $customer->email(), $customer->company());
    }

    public function search(string $query, int $limit = 20): array
    {
        $qb = $this->em->getRepository(Customer::class)
            ->createQueryBuilder('c')
            ->setMaxResults($limit);
        self_repair_search($qb, 'c', ['name', 'email', 'company'], $query);
        $items = $qb->getQuery()->getResult();

        return array_map(
            static fn (Customer $customer): ContactReference => new ContactReference(
                $customer->id(),
                $customer->name(),
                $customer->email(),
                $customer->company(),
            ),
            $items
        );
    }
}
