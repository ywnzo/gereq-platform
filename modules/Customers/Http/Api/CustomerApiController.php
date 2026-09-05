<?php

declare(strict_types=1);

namespace Modules\Customers\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Hook\HookManager;
use Gereq\Http\Router;
use Modules\Customers\Domain\Customer;
use Modules\Customers\Domain\CustomerAddress;
use Modules\Customers\Domain\CustomerContact;
use Modules\Customers\Domain\CustomerCreated;
use Modules\Customers\Domain\CustomerNote;
use Modules\Customers\Domain\CustomerRelation;
use Modules\Customers\Domain\Events\CustomerAddressCreated;
use Modules\Customers\Domain\Events\CustomerAddressDeleted;
use Modules\Customers\Domain\Events\CustomerAddressUpdated;
use Modules\Customers\Domain\Events\CustomerArchived;
use Modules\Customers\Domain\Events\CustomerContactCreated;
use Modules\Customers\Domain\Events\CustomerContactDeleted;
use Modules\Customers\Domain\Events\CustomerContactUpdated;
use Modules\Customers\Domain\Events\CustomerDeleted;
use Modules\Customers\Domain\Events\CustomerNoteCreated;
use Modules\Customers\Domain\Events\CustomerNoteDeleted;
use Modules\Customers\Domain\Events\CustomerNoteUpdated;
use Modules\Customers\Domain\Events\CustomerRelationCreated;
use Modules\Customers\Domain\Events\CustomerRelationDeleted;
use Modules\Customers\Domain\Events\CustomerRestored;
use Modules\Customers\Domain\Events\CustomerUpdated;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class CustomerApiController extends AbstractCustomerApiController
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly HookManager $hooks,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest(
            $request,
            sortable: ['name', 'email', 'company', 'status', 'createdAt', 'updatedAt'],
            filterable: ['status'],
        );

        $qb = $this->em->getRepository(Customer::class)->createQueryBuilder('c');
        if ($query->search !== '') {
            $qb->andWhere('c.name LIKE :q OR c.email LIKE :q OR c.company LIKE :q OR c.phone LIKE :q')
                ->setParameter('q', '%' . $query->search . '%');
        }
        if (isset($query->filters['status']) && $query->filters['status'] !== '') {
            $qb->andWhere('c.status = :status')->setParameter('status', $query->filters['status']);
        }

        $sort = $query->sort ?? 'name';
        $qb->orderBy('c.' . $sort, strtoupper($query->direction));

        $total = (int) (clone $qb)->select('COUNT(c.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<Customer> $items */
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (Customer $c): array => $this->serializeCustomer($c), $items),
            $query->meta($total),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateBody($request, 'customers.save');
        $customer = new Customer(trim((string) $data['name']), strtolower(trim((string) $data['email'])));
        $this->applyCustomerFields($customer, $data);
        $this->em->persist($customer);
        $this->em->flush();

        $payload = $this->serializeCustomer($customer);
        $this->events->dispatch(new CustomerCreated($customer->id(), $payload));

        return ApiResponse::data($payload, 201);
    }

    public function show(string $id): JsonResponse
    {
        $customer = $this->findCustomer($id);
        if ($customer === null) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->serializeCustomer($customer));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $customer = $this->findCustomer($id);
        if ($customer === null) {
            return ApiResponse::notFound();
        }

        $data = $this->validateBody($request, 'customers.save', ['ignore_id' => $customer->id()]);
        $previousStatus = $customer->status();
        $customer->setName(trim((string) $data['name']));
        $customer->setEmail(strtolower(trim((string) $data['email'])));
        $this->applyCustomerFields($customer, $data);
        $this->em->flush();

        $payload = $this->serializeCustomer($customer);
        $this->events->dispatch(CustomerUpdated::make($customer->id(), $payload));
        if ($previousStatus !== 'archived' && $customer->status() === 'archived') {
            $this->events->dispatch(CustomerArchived::make($customer->id(), $payload));
        }
        if ($previousStatus === 'archived' && $customer->status() !== 'archived') {
            $this->events->dispatch(CustomerRestored::make($customer->id(), $payload));
        }

        return ApiResponse::data($payload);
    }

    public function destroy(string $id): JsonResponse
    {
        $customer = $this->findCustomer($id);
        if ($customer === null) {
            return ApiResponse::notFound();
        }

        $payload = $this->serializeCustomer($customer);
        $name = $customer->name();
        $this->hooks->doAction('customers.deleting', $customer);
        $this->em->remove($customer);
        $this->em->flush();
        $this->events->dispatch(CustomerDeleted::make($id, $payload));
        $this->hooks->doAction('customers.deleted', $id, $name);

        return ApiResponse::data(['id' => $id, 'deleted' => true]);
    }

    public function archive(string $id): JsonResponse
    {
        $customer = $this->findCustomer($id);
        if ($customer === null) {
            return ApiResponse::notFound();
        }

        $customer->archive();
        $this->em->flush();
        $payload = $this->serializeCustomer($customer);
        $this->events->dispatch(CustomerArchived::make($customer->id(), $payload));
        $this->events->dispatch(CustomerUpdated::make($customer->id(), $payload));

        return ApiResponse::data($payload);
    }

    public function restore(string $id): JsonResponse
    {
        $customer = $this->findCustomer($id);
        if ($customer === null) {
            return ApiResponse::notFound();
        }

        $customer->restore('active');
        $this->em->flush();
        $payload = $this->serializeCustomer($customer);
        $this->events->dispatch(CustomerRestored::make($customer->id(), $payload));
        $this->events->dispatch(CustomerUpdated::make($customer->id(), $payload));

        return ApiResponse::data($payload);
    }

    // --- Addresses ---

    public function addressesIndex(string $id): JsonResponse
    {
        if ($this->findCustomer($id) === null) {
            return ApiResponse::notFound();
        }

        /** @var list<CustomerAddress> $items */
        $items = $this->em->getRepository(CustomerAddress::class)->findBy(
            ['customerId' => $id],
            ['isPrimary' => 'DESC', 'createdAt' => 'DESC'],
        );

        return ApiResponse::collection(array_map(fn (CustomerAddress $a): array => $this->serializeAddress($a), $items));
    }

    public function addressesStore(Request $request, string $id): JsonResponse
    {
        if ($this->findCustomer($id) === null) {
            return ApiResponse::notFound();
        }

        $data = $this->validateBody($request, 'customers.address.save');
        $address = new CustomerAddress(
            $id,
            (string) $data['type'],
            trim((string) $data['line1']),
            trim((string) $data['city']),
            trim((string) $data['zip']),
            trim((string) $data['country']),
        );
        $address->setLine2(isset($data['line2']) ? (string) $data['line2'] : null);
        $address->setIsPrimary($this->boolValue($data['is_primary'] ?? false));
        if ($address->isPrimary()) {
            $this->clearPrimaryAddresses($id);
        }
        $this->em->persist($address);
        $this->em->flush();

        $payload = $this->serializeAddress($address);
        $this->events->dispatch(CustomerAddressCreated::make($address->id(), $payload));

        return ApiResponse::data($payload, 201);
    }

    public function addressesShow(string $id, string $addressId): JsonResponse
    {
        $address = $this->findAddress($id, $addressId);
        if ($address === null) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->serializeAddress($address));
    }

    public function addressesUpdate(Request $request, string $id, string $addressId): JsonResponse
    {
        $address = $this->findAddress($id, $addressId);
        if ($address === null) {
            return ApiResponse::notFound();
        }

        $data = $this->validateBody($request, 'customers.address.save');
        $address->setType((string) $data['type']);
        $address->setLine1(trim((string) $data['line1']));
        $address->setLine2(isset($data['line2']) ? (string) $data['line2'] : null);
        $address->setCity(trim((string) $data['city']));
        $address->setZip(trim((string) $data['zip']));
        $address->setCountry(trim((string) $data['country']));
        $isPrimary = $this->boolValue($data['is_primary'] ?? false);
        if ($isPrimary) {
            $this->clearPrimaryAddresses($id, $address->id());
        }
        $address->setIsPrimary($isPrimary);
        $this->em->flush();

        $payload = $this->serializeAddress($address);
        $this->events->dispatch(CustomerAddressUpdated::make($address->id(), $payload));

        return ApiResponse::data($payload);
    }

    public function addressesDestroy(string $id, string $addressId): JsonResponse
    {
        $address = $this->findAddress($id, $addressId);
        if ($address === null) {
            return ApiResponse::notFound();
        }

        $payload = $this->serializeAddress($address);
        $this->em->remove($address);
        $this->em->flush();
        $this->events->dispatch(CustomerAddressDeleted::make($addressId, $payload));

        return ApiResponse::data(['id' => $addressId, 'deleted' => true]);
    }

    // --- Contacts ---

    public function contactsIndex(string $id): JsonResponse
    {
        if ($this->findCustomer($id) === null) {
            return ApiResponse::notFound();
        }

        /** @var list<CustomerContact> $items */
        $items = $this->em->getRepository(CustomerContact::class)->findBy(
            ['customerId' => $id],
            ['isPrimary' => 'DESC', 'name' => 'ASC'],
        );

        return ApiResponse::collection(array_map(fn (CustomerContact $c): array => $this->serializeContact($c), $items));
    }

    public function contactsStore(Request $request, string $id): JsonResponse
    {
        if ($this->findCustomer($id) === null) {
            return ApiResponse::notFound();
        }

        $data = $this->validateBody($request, 'customers.contact.save');
        $contact = new CustomerContact($id, trim((string) $data['name']));
        $contact->setEmail(isset($data['email']) ? (string) $data['email'] : null);
        $contact->setPhone(isset($data['phone']) ? (string) $data['phone'] : null);
        $contact->setRole(isset($data['role']) ? (string) $data['role'] : null);
        $contact->setIsPrimary($this->boolValue($data['is_primary'] ?? false));
        if ($contact->isPrimary()) {
            $this->clearPrimaryContacts($id);
        }
        $this->em->persist($contact);
        $this->em->flush();

        $payload = $this->serializeContact($contact);
        $this->events->dispatch(CustomerContactCreated::make($contact->id(), $payload));

        return ApiResponse::data($payload, 201);
    }

    public function contactsShow(string $id, string $contactId): JsonResponse
    {
        $contact = $this->findContact($id, $contactId);
        if ($contact === null) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->serializeContact($contact));
    }

    public function contactsUpdate(Request $request, string $id, string $contactId): JsonResponse
    {
        $contact = $this->findContact($id, $contactId);
        if ($contact === null) {
            return ApiResponse::notFound();
        }

        $data = $this->validateBody($request, 'customers.contact.save');
        $contact->setName(trim((string) $data['name']));
        $contact->setEmail(isset($data['email']) ? (string) $data['email'] : null);
        $contact->setPhone(isset($data['phone']) ? (string) $data['phone'] : null);
        $contact->setRole(isset($data['role']) ? (string) $data['role'] : null);
        $isPrimary = $this->boolValue($data['is_primary'] ?? false);
        if ($isPrimary) {
            $this->clearPrimaryContacts($id, $contact->id());
        }
        $contact->setIsPrimary($isPrimary);
        $this->em->flush();

        $payload = $this->serializeContact($contact);
        $this->events->dispatch(CustomerContactUpdated::make($contact->id(), $payload));

        return ApiResponse::data($payload);
    }

    public function contactsDestroy(string $id, string $contactId): JsonResponse
    {
        $contact = $this->findContact($id, $contactId);
        if ($contact === null) {
            return ApiResponse::notFound();
        }

        $payload = $this->serializeContact($contact);
        $this->em->remove($contact);
        $this->em->flush();
        $this->events->dispatch(CustomerContactDeleted::make($contactId, $payload));

        return ApiResponse::data(['id' => $contactId, 'deleted' => true]);
    }

    // --- Notes ---

    public function notesIndex(string $id): JsonResponse
    {
        if ($this->findCustomer($id) === null) {
            return ApiResponse::notFound();
        }

        /** @var list<CustomerNote> $items */
        $items = $this->em->getRepository(CustomerNote::class)->findBy(
            ['customerId' => $id],
            ['createdAt' => 'DESC'],
        );

        return ApiResponse::collection(array_map(fn (CustomerNote $n): array => $this->serializeNote($n), $items));
    }

    public function notesStore(Request $request, string $id): JsonResponse
    {
        if ($this->findCustomer($id) === null) {
            return ApiResponse::notFound();
        }

        $data = $this->validateBody($request, 'customers.note.save');
        $actor = $request->attributes->get('_api_actor');
        $userId = is_array($actor) && ($actor['type'] ?? null) === 'user' ? ($actor['id'] ?? null) : null;
        $note = new CustomerNote($id, trim((string) $data['body']), is_string($userId) ? $userId : null);
        $this->em->persist($note);
        $this->em->flush();

        $payload = $this->serializeNote($note);
        $this->events->dispatch(CustomerNoteCreated::make($note->id(), $payload));

        return ApiResponse::data($payload, 201);
    }

    public function notesShow(string $id, string $noteId): JsonResponse
    {
        $note = $this->findNote($id, $noteId);
        if ($note === null) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->serializeNote($note));
    }

    public function notesUpdate(Request $request, string $id, string $noteId): JsonResponse
    {
        $note = $this->findNote($id, $noteId);
        if ($note === null) {
            return ApiResponse::notFound();
        }

        $data = $this->validateBody($request, 'customers.note.save');
        $note->setBody(trim((string) $data['body']));
        $this->em->flush();

        $payload = $this->serializeNote($note);
        $this->events->dispatch(CustomerNoteUpdated::make($note->id(), $payload));

        return ApiResponse::data($payload);
    }

    public function notesDestroy(string $id, string $noteId): JsonResponse
    {
        $note = $this->findNote($id, $noteId);
        if ($note === null) {
            return ApiResponse::notFound();
        }

        $payload = $this->serializeNote($note);
        $this->em->remove($note);
        $this->em->flush();
        $this->events->dispatch(CustomerNoteDeleted::make($noteId, $payload));

        return ApiResponse::data(['id' => $noteId, 'deleted' => true]);
    }

    // --- Relations ---

    public function relationsIndex(string $id): JsonResponse
    {
        if ($this->findCustomer($id) === null) {
            return ApiResponse::notFound();
        }

        $qb = $this->em->getRepository(CustomerRelation::class)->createQueryBuilder('r');
        $qb->where('r.fromCustomerId = :id OR r.toCustomerId = :id')
            ->setParameter('id', $id)
            ->orderBy('r.createdAt', 'DESC');

        /** @var list<CustomerRelation> $items */
        $items = $qb->getQuery()->getResult();

        return ApiResponse::collection(array_map(fn (CustomerRelation $r): array => $this->serializeRelation($r), $items));
    }

    public function relationsStore(Request $request, string $id): JsonResponse
    {
        if ($this->findCustomer($id) === null) {
            return ApiResponse::notFound();
        }

        $data = $this->validateBody($request, 'customers.relation.save');
        $toId = (string) $data['to_customer_id'];
        if ($this->findCustomer($toId) === null) {
            return ApiResponse::error('invalid_relation', 'Cílový zákazník neexistuje.', ['to_customer_id' => 'not_found'], 422);
        }

        try {
            $relation = new CustomerRelation($id, $toId, (string) $data['type']);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('invalid_relation', $e->getMessage(), [], 422);
        }

        $this->em->persist($relation);
        $this->em->flush();

        $payload = $this->serializeRelation($relation);
        $this->events->dispatch(CustomerRelationCreated::make($relation->id(), $payload));

        return ApiResponse::data($payload, 201);
    }

    public function relationsDestroy(string $id, string $relationId): JsonResponse
    {
        $relation = $this->em->find(CustomerRelation::class, $relationId);
        if (!$relation instanceof CustomerRelation) {
            return ApiResponse::notFound();
        }
        if ($relation->fromCustomerId() !== $id && $relation->toCustomerId() !== $id) {
            return ApiResponse::notFound();
        }

        $payload = $this->serializeRelation($relation);
        $this->em->remove($relation);
        $this->em->flush();
        $this->events->dispatch(CustomerRelationDeleted::make($relationId, $payload));

        return ApiResponse::data(['id' => $relationId, 'deleted' => true]);
    }

    private function findCustomer(string $id): ?Customer
    {
        $customer = $this->em->find(Customer::class, $id);

        return $customer instanceof Customer ? $customer : null;
    }

    private function findAddress(string $customerId, string $addressId): ?CustomerAddress
    {
        $address = $this->em->find(CustomerAddress::class, $addressId);
        if (!$address instanceof CustomerAddress || $address->customerId() !== $customerId) {
            return null;
        }

        return $address;
    }

    private function findContact(string $customerId, string $contactId): ?CustomerContact
    {
        $contact = $this->em->find(CustomerContact::class, $contactId);
        if (!$contact instanceof CustomerContact || $contact->customerId() !== $customerId) {
            return null;
        }

        return $contact;
    }

    private function findNote(string $customerId, string $noteId): ?CustomerNote
    {
        $note = $this->em->find(CustomerNote::class, $noteId);
        if (!$note instanceof CustomerNote || $note->customerId() !== $customerId) {
            return null;
        }

        return $note;
    }

    /** @param array<string, mixed> $data */
    private function applyCustomerFields(Customer $customer, array $data): void
    {
        $customer->setPhone(isset($data['phone']) ? (string) $data['phone'] : null);
        $customer->setCompany(isset($data['company']) ? (string) $data['company'] : null);
        $customer->setNote(isset($data['note']) ? (string) $data['note'] : null);
        $status = (string) ($data['status'] ?? 'active');
        if (!in_array($status, ['active', 'inactive', 'lead', 'archived'], true)) {
            $status = 'active';
        }
        $customer->setStatus($status);
    }

    private function clearPrimaryAddresses(string $customerId, ?string $exceptId = null): void
    {
        /** @var list<CustomerAddress> $items */
        $items = $this->em->getRepository(CustomerAddress::class)->findBy(['customerId' => $customerId, 'isPrimary' => true]);
        foreach ($items as $item) {
            if ($exceptId !== null && $item->id() === $exceptId) {
                continue;
            }
            $item->setIsPrimary(false);
        }
    }

    private function clearPrimaryContacts(string $customerId, ?string $exceptId = null): void
    {
        /** @var list<CustomerContact> $items */
        $items = $this->em->getRepository(CustomerContact::class)->findBy(['customerId' => $customerId, 'isPrimary' => true]);
        foreach ($items as $item) {
            if ($exceptId !== null && $item->id() === $exceptId) {
                continue;
            }
            $item->setIsPrimary(false);
        }
    }

    /** @return array<string, mixed> */
    private function serializeCustomer(Customer $customer): array
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

    /** @return array<string, mixed> */
    private function serializeAddress(CustomerAddress $address): array
    {
        return [
            'id' => $address->id(),
            'customer_id' => $address->customerId(),
            'type' => $address->type(),
            'line1' => $address->line1(),
            'line2' => $address->line2(),
            'city' => $address->city(),
            'zip' => $address->zip(),
            'country' => $address->country(),
            'is_primary' => $address->isPrimary(),
            'created_at' => $address->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $address->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeContact(CustomerContact $contact): array
    {
        return [
            'id' => $contact->id(),
            'customer_id' => $contact->customerId(),
            'name' => $contact->name(),
            'email' => $contact->email(),
            'phone' => $contact->phone(),
            'role' => $contact->role(),
            'is_primary' => $contact->isPrimary(),
            'created_at' => $contact->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $contact->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeNote(CustomerNote $note): array
    {
        return [
            'id' => $note->id(),
            'customer_id' => $note->customerId(),
            'body' => $note->body(),
            'created_by_user_id' => $note->createdByUserId(),
            'created_at' => $note->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $note->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeRelation(CustomerRelation $relation): array
    {
        return [
            'id' => $relation->id(),
            'from_customer_id' => $relation->fromCustomerId(),
            'to_customer_id' => $relation->toCustomerId(),
            'type' => $relation->type(),
            'created_at' => $relation->createdAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
