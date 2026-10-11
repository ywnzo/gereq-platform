<?php

declare(strict_types=1);

namespace Gereq\Publish\Adapter;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Publish\ChangeCard;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\PublishableAdapterInterface;
use Gereq\Publish\StagingStore;
use Gereq\Customers\Domain\Customer;
use Gereq\Customers\Domain\CustomerAccount;
use Gereq\Customers\Domain\CustomerAddress;
use Gereq\Customers\Domain\CustomerContact;
use Gereq\Customers\Domain\CustomerNote;
use Gereq\Customers\Domain\CustomerRelation;
use Gereq\Customers\Domain\CustomerTagLink;

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

    public function discard(ContentStagingEntry $entry): void
    {
        if ($entry->action() === ContentStagingEntry::ACTION_CREATE) {
            $this->hardDelete($entry->resourceKey());

            return;
        }

        if ($entry->action() !== ContentStagingEntry::ACTION_UPDATE) {
            return;
        }

        $snap = $entry->payload()['production_snapshot'] ?? null;
        if (!is_array($snap)) {
            return;
        }

        $customer = $this->em->find(Customer::class, $entry->resourceKey());
        if (!$customer instanceof Customer) {
            return;
        }

        $customer->setName((string) ($snap['name'] ?? $customer->name()));
        if (isset($snap['email']) && is_string($snap['email']) && $snap['email'] !== '') {
            $customer->setEmail($snap['email']);
        }
        if (method_exists($customer, 'setPhone')) {
            $customer->setPhone(isset($snap['phone']) && is_string($snap['phone']) ? $snap['phone'] : null);
        }
        if (method_exists($customer, 'setIsCompany')) {
            $customer->setIsCompany((bool) ($snap['is_company'] ?? false));
        }
        if (method_exists($customer, 'setCompany')) {
            $customer->setCompany(isset($snap['company']) && is_string($snap['company']) ? $snap['company'] : null);
        }
        if (method_exists($customer, 'setIco')) {
            $customer->setIco(isset($snap['ico']) && is_string($snap['ico']) ? $snap['ico'] : null);
        }
        if (method_exists($customer, 'setDic')) {
            $customer->setDic(isset($snap['dic']) && is_string($snap['dic']) ? $snap['dic'] : null);
        }
        if (method_exists($customer, 'setNote')) {
            $customer->setNote(isset($snap['note']) && is_string($snap['note']) ? $snap['note'] : null);
        }
        if (isset($snap['status']) && is_string($snap['status']) && method_exists($customer, 'setStatus')) {
            $customer->setStatus($snap['status']);
        }
        $this->em->flush();
    }

    public function restore(string $resourceKey, ?array $snapshot): void
    {
        if ($snapshot === null) {
            $entry = new ContentStagingEntry(
                'customers',
                'resource',
                $resourceKey,
                ContentStagingEntry::ACTION_CREATE,
                ['pending_create' => true],
                'rollback',
            );
            $this->discard($entry);

            return;
        }

        $entry = new ContentStagingEntry(
            'customers',
            'resource',
            $resourceKey,
            ContentStagingEntry::ACTION_UPDATE,
            ['production_snapshot' => $snapshot],
            'rollback',
        );
        $this->discard($entry);
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
            'is_company' => $customer->isCompany(),
            'company' => $customer->company(),
            'ico' => $customer->ico(),
            'dic' => $customer->dic(),
            'company_address' => $customer->companyAddress(),
            'note' => $customer->note(),
            'status' => $customer->status(),
            'avatar_file_id' => $customer->avatarFileId(),
            'created_at' => $customer->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $customer->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
