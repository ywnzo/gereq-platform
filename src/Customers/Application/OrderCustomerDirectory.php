<?php

declare(strict_types=1);

namespace Gereq\Customers\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Customers\Domain\Customer;
use Gereq\Customers\Domain\CustomerAddress;
use Gereq\Customers\Domain\CustomerCreated;
use Gereq\Event\EventBus;
use Gereq\Publish\ProductionFreeze;

final class OrderCustomerDirectory
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CustomersProvider $customers,
        private readonly Authenticator $auth,
        private readonly ProductionFreeze $freeze,
        private readonly EventBus $events,
        private readonly Auditor $auditor,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function search(string $query): array
    {
        $references = $this->customers->search(mb_substr($query, 0, 100), 20);
        $ids = array_map(static fn (ContactReference $reference): string => $reference->id, $references);
        if ($ids === []) {
            return [];
        }
        $addresses = [];
        foreach ($this->em->getRepository(CustomerAddress::class)->findBy(['customerId' => $ids], ['isPrimary' => 'DESC']) as $address) {
            $addresses[$address->customerId()][] = $address;
        }
        $profiles = [];
        foreach ($this->em->getRepository(Customer::class)->findBy(['id' => $ids]) as $customer) {
            if (!$customer->isArchived()) {
                $profiles[$customer->id()] = $this->serialize($customer, $addresses[$customer->id()] ?? []);
            }
        }
        return array_values(array_intersect_key($profiles, array_flip($ids)));
    }

    /** @return array<string, mixed>|null */
    public function profile(string $id): ?array
    {
        if ($id === '' || $this->customers->find($id) === null) {
            return null;
        }
        $customer = $this->em->find(Customer::class, $id);
        if (!$customer instanceof Customer || $customer->isArchived()) {
            return null;
        }
        $addresses = $this->em->getRepository(CustomerAddress::class)->findBy(['customerId' => $id], ['isPrimary' => 'DESC']);
        return $this->serialize($customer, $addresses);
    }

    /** @param list<CustomerAddress> $savedAddresses @return array<string, mixed> */
    private function serialize(Customer $customer, array $savedAddresses): array
    {
        $addresses = [];
        foreach ($savedAddresses as $address) {
            $addresses[] = ['id' => $address->id(), 'type' => $address->type(), 'name' => $customer->name(),
                'email' => $customer->email(), 'phone' => $customer->phone(), 'street' => $address->line1(),
                'city' => $address->city(), 'zip' => $address->zip(), 'country' => $address->country()];
        }
        return ['id' => $customer->id(), 'name' => $customer->name(), 'email' => $customer->email(), 'phone' => $customer->phone(),
            'company' => $customer->company(), 'addresses' => $addresses];
    }

    /** The caller owns the order transaction.
     * @param array<string, string> $data
     * @param array<string, string> $billing
     * @param array<string, string> $shipping
     */
    public function create(array $data, array $billing = [], array $shipping = []): string
    {
        if (!$this->auth->user()?->can('customers.manage')) {
            throw new \InvalidArgumentException('orders.compose.customer_permission');
        }
        $name = trim($data['name'] ?? '');
        $email = strtolower(trim($data['email'] ?? ''));
        if ($name === '' || mb_strlen($name) > 180 || strlen($email) > 180 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('orders.compose.customer_required');
        }
        if ($this->em->getRepository(Customer::class)->findOneBy(['email' => $email]) !== null) {
            throw new \InvalidArgumentException('orders.compose.customer_exists');
        }
        $customer = new Customer($name, $email);
        $customer->setPhone($data['phone'] ?? null);
        $this->em->persist($customer);
        foreach (['billing' => $billing, 'shipping' => $shipping] as $type => $address) {
            if (empty($address['street']) || empty($address['city']) || empty($address['zip']) || empty($address['country']) || isset($address['pickup_point'])) {
                continue;
            }
            if ($type === 'shipping' && $billing === $shipping) {
                continue;
            }
            $this->em->persist(new CustomerAddress($customer->id(), $type, $address['street'], $address['city'], $address['zip'], $address['country']));
        }
        $this->em->flush();
        $this->freeze->stageCreate('customers', 'customer', $customer->id(), $name, $this->auth->user()?->id());
        $this->events->dispatch(new CustomerCreated($customer->id(), [
            'id' => $customer->id(), 'name' => $name, 'email' => $email, 'phone' => $customer->phone(),
            'is_company' => $customer->isCompany(), 'company' => $customer->company(), 'ico' => $customer->ico(),
            'dic' => $customer->dic(), 'company_address' => $customer->companyAddress(), 'note' => $customer->note(),
            'status' => $customer->status(), 'avatar_file_id' => $customer->avatarFileId(),
            'created_at' => $customer->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $customer->updatedAt()->format(\DateTimeInterface::ATOM),
        ]));
        $this->auditor->log('customer.created', 'customer', $customer->id(), null, ['source' => 'orders.composer']);
        return $customer->id();
    }
}
