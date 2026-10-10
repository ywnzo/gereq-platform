<?php

declare(strict_types=1);

namespace Gereq\Customers\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\Authenticator;
use Gereq\Customers\Domain\Customer;
use Gereq\Customers\Application\CustomerScope;

final class CustomersProvider implements ContactsProvider
{
    /** @var array<string, string> id => name (request memo) */
    private array $nameById = [];

    /** @var array<string, true> ids already queried */
    private array $nameLoaded = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly CustomerScope $customerScope,
    ) {
    }

    public function find(string $id): ?ContactReference
    {
        $customer = $this->em->find(Customer::class, $id);
        if (!$customer instanceof Customer) {
            return null;
        }

        $ids = $this->customerScope->scopedCustomerIds($this->auth->user());
        if ($ids !== null && !in_array($customer->id(), $ids, true)) {
            return null;
        }

        $this->nameById[$customer->id()] = $customer->name();
        $this->nameLoaded[$customer->id()] = true;

        return new ContactReference($customer->id(), $customer->name(), $customer->email(), $customer->company());
    }

    /**
     * Batch-load customer display names (one IN query for missing ids).
     *
     * @param list<string> $ids
     * @return array<string, string> id => name
     */
    public function namesByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            $ids,
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        )));
        if ($ids === []) {
            return [];
        }

        $scoped = $this->customerScope->scopedCustomerIds($this->auth->user());
        if ($scoped !== null) {
            $allowed = array_flip($scoped);
            $ids = array_values(array_filter($ids, static fn (string $id): bool => isset($allowed[$id])));
            if ($ids === []) {
                return [];
            }
        }

        $missing = [];
        foreach ($ids as $id) {
            if (!isset($this->nameLoaded[$id])) {
                $missing[] = $id;
            }
        }

        if ($missing !== []) {
            try {
                foreach ($this->em->getRepository(Customer::class)->findBy(['id' => $missing]) as $customer) {
                    if ($customer instanceof Customer) {
                        $this->nameById[$customer->id()] = $customer->name();
                        $this->nameLoaded[$customer->id()] = true;
                    }
                }
            } catch (\Throwable) {
                // mark missing so we do not retry in this request
            }
            foreach ($missing as $id) {
                $this->nameLoaded[$id] = true;
            }
        }

        $out = [];
        foreach ($ids as $id) {
            if (isset($this->nameById[$id])) {
                $out[$id] = $this->nameById[$id];
            }
        }

        return $out;
    }

    public function search(string $query, int $limit = 20): array
    {
        $qb = $this->em->getRepository(Customer::class)
            ->createQueryBuilder('c')
            ->setMaxResults($limit);
        self_repair_search($qb, 'c', ['name', 'email', 'company'], $query);
        $this->customerScope->applyCustomerScope($qb, 'c', $this->auth->user());
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
