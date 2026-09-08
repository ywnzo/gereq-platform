<?php

declare(strict_types=1);

namespace Gereq\Publish\Adapter;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Publish\ChangeCard;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\PublishableAdapterInterface;
use Gereq\Publish\StagingStore;
use Modules\Customers\Domain\Customer;
use Modules\Customers\Domain\CustomerAccount;
use Modules\Customers\Domain\CustomerAddress;
use Modules\Customers\Domain\CustomerContact;
use Modules\Customers\Domain\CustomerNote;
use Modules\Customers\Domain\CustomerRelation;
use Modules\Customers\Domain\CustomerTagLink;

final class CustomerPublishAdapter implements PublishableAdapterInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StagingStore $store,
    ) {
    }

    public function settingsKeys(): array
    {
        return [];
    }

    public function snapshot(array $resourceKeys): array
    {
        $out = [];
        foreach ($resourceKeys as $key) {
            $entry = $this->store->find('customers', $key);
            if ($entry !== null) {
                $out[$key] = $entry->payload()['production_snapshot'] ?? null;
                continue;
            }
            $customer = $this->em->find(Customer::class, $key);
            if ($customer instanceof Customer) {
                $out[$key] = self::capture($customer);
            }
        }

        return $out;
    }

    public function apply(ContentStagingEntry $entry): void
    {
        if ($entry->action() === ContentStagingEntry::ACTION_DELETE) {
            $this->hardDelete($entry->resourceKey());
        }
        // CREATE / UPDATE: working copy already holds desired state.
    }

    public function describe(ContentStagingEntry $entry): ChangeCard
    {
        $snap = $entry->payload()['production_snapshot'] ?? [];
        $name = is_array($snap) ? (string) ($snap['name'] ?? $entry->label()) : $entry->label();
        if ($name === '') {
            $name = $entry->label();
        }
        $email = is_array($snap) ? (string) ($snap['email'] ?? '') : '';

        if ($entry->action() === ContentStagingEntry::ACTION_DELETE) {
            return new ChangeCard('Smazat: ' . $name, $email);
        }
        if ($entry->action() === ContentStagingEntry::ACTION_CREATE) {
            return new ChangeCard('Nové: ' . $name, $email);
        }

        return new ChangeCard($name, $email);
    }

    private function hardDelete(string $customerId): void
    {
        foreach ([
            CustomerAddress::class,
            CustomerContact::class,
            CustomerNote::class,
            CustomerTagLink::class,
        ] as $class) {
            if (!class_exists($class)) {
                continue;
            }
            foreach ($this->em->getRepository($class)->findBy(['customerId' => $customerId]) as $row) {
                $this->em->remove($row);
            }
        }

        if (class_exists(CustomerRelation::class)) {
            $relations = $this->em->createQueryBuilder()
                ->select('r')
                ->from(CustomerRelation::class, 'r')
                ->where('r.fromCustomerId = :id OR r.toCustomerId = :id')
                ->setParameter('id', $customerId)
                ->getQuery()
                ->getResult();
            foreach ($relations as $row) {
                $this->em->remove($row);
            }
        }

        if (class_exists(CustomerAccount::class)) {
            foreach ($this->em->getRepository(CustomerAccount::class)->findBy(['customerId' => $customerId]) as $row) {
                $this->em->remove($row);
            }
        }

        $customer = $this->em->find(Customer::class, $customerId);
        if ($customer instanceof Customer) {
            $this->em->remove($customer);
        }
        $this->em->flush();
    }

    /** @return array<string, mixed> */
    public static function capture(Customer $customer): array
    {
        return [
            'id' => $customer->id(),
            'name' => $customer->name(),
            'email' => $customer->email(),
            'phone' => $customer->phone(),
            'company' => $customer->company(),
            'note' => $customer->note(),
            'status' => $customer->status(),
            'created_at' => $customer->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $customer->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
