<?php

declare(strict_types=1);

namespace Modules\Customers\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Hook\HookManager;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Security\HtmlSanitizer;
use Modules\Customers\Domain\Customer;
use Modules\Customers\Domain\CustomerAddress;
use Modules\Customers\Domain\CustomerContact;
use Modules\Customers\Domain\CustomerCreated;
use Modules\Customers\Domain\CustomerNote;
use Modules\Customers\Domain\Events\CustomerArchived;
use Modules\Customers\Domain\Events\CustomerDeleted;
use Modules\Customers\Domain\Events\CustomerRestored;
use Modules\Customers\Domain\Events\CustomerUpdated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CustomerController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Auditor $auditor,
        private readonly EventBus $events,
        private readonly HookManager $hooks,
        private readonly TranslatorInterface $translator,
        private readonly HtmlSanitizer $html,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = \Gereq\Http\ListQuery::fromRequest(
            $request,
            sortable: ['name', 'email', 'company', 'status', 'createdAt'],
            defaultSort: 'name',
            filterKeys: ['status'],
        );
        $status = $list->filter('status');

        $qb = $this->em->getRepository(Customer::class)->createQueryBuilder('c');
        $list->applySearch($qb, 'c', ['name', 'email', 'company', 'phone']);
        if (in_array($status, ['active', 'inactive', 'lead', 'archived'], true)) {
            $qb->andWhere('c.status = :status')->setParameter('status', $status);
        }

        $qb->orderBy('c.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(c.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@customers/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('customers.title'),
            'items' => $items,
            'status' => $status,
        ]));
    }

    public function create(): Response
    {
        return $this->page('@customers/form.html.twig', [
            'pageTitle' => $this->translator->trans('customers.new'),
            'item' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $customer = new Customer($data['name'], $data['email']);
        $customer->setPhone($data['phone']);
        $customer->setCompany($data['company']);
        $customer->setNote($data['note']);
        $customer->setStatus($data['status']);
        $this->em->persist($customer);
        $this->em->flush();
        $this->events->dispatch(new CustomerCreated($customer->id(), $this->publicCustomer($customer)));
        $this->auditor->log(
            'customer.created',
            'customer',
            $customer->id(),
            null,
            ['name' => $customer->name(), 'email' => $customer->email()],
        );
        $this->forgetForm('customers.save');
        $this->flash('success', $this->translator->trans('customers.flash.created', ['%name%' => $customer->name()]));

        return $this->redirect('admin.customers.show', ['id' => $customer->id()]);
    }

    public function show(string $id): Response
    {
        $customer = $this->find($id);
        if ($customer === null) {
            $this->abort(404, $this->translator->trans('customers.error.not_found'));
        }

        $actions = $this->hooks->filter('customers.show.actions', [], $customer);
        $sections = $this->hooks->filter('customers.show.sections', [], $customer);
        if (!is_array($actions)) {
            $actions = [];
        }
        if (!is_array($sections)) {
            $sections = [];
        }
        usort($sections, static fn (array $a, array $b): int => ($a['order'] ?? 100) <=> ($b['order'] ?? 100));

        $addresses = $this->em->getRepository(CustomerAddress::class)->findBy(
            ['customerId' => $customer->id()],
            ['isPrimary' => 'DESC', 'createdAt' => 'DESC'],
        );
        $contacts = $this->em->getRepository(CustomerContact::class)->findBy(
            ['customerId' => $customer->id()],
            ['isPrimary' => 'DESC', 'name' => 'ASC'],
        );
        $timelineNotes = $this->em->getRepository(CustomerNote::class)->findBy(
            ['customerId' => $customer->id()],
            ['createdAt' => 'DESC'],
        );

        return $this->page('@customers/show.html.twig', [
            'pageTitle' => $customer->name(),
            'item' => $customer,
            'noteHtml' => $this->html->sanitize($customer->note()),
            'addresses' => $addresses,
            'contacts' => $contacts,
            'timelineNotes' => $timelineNotes,
            'hookActions' => $actions,
            'hookSections' => $sections,
        ]);
    }

    public function storeAddress(Request $request, string $id): Response
    {
        $customer = $this->requireCustomer($id);
        $type = (string) $request->request->get('type', 'other');
        if (!in_array($type, CustomerAddress::TYPES, true)) {
            $type = 'other';
        }
        $line1 = trim((string) $request->request->get('line1', ''));
        $city = trim((string) $request->request->get('city', ''));
        $zip = trim((string) $request->request->get('zip', ''));
        $country = strtoupper(trim((string) $request->request->get('country', 'CZ'))) ?: 'CZ';
        if ($line1 === '' || $city === '' || $zip === '') {
            $this->flash('error', $this->translator->trans('customers.addresses.validation'));

            return $this->redirectToShow($id);
        }
        $address = new CustomerAddress($customer->id(), $type, $line1, $city, $zip, $country);
        $address->setLine2(trim((string) $request->request->get('line2', '')) ?: null);
        $address->setIsPrimary($request->request->get('is_primary') === '1');
        $this->em->persist($address);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('customers.addresses.flash.created'));

        return $this->redirectToShow($id);
    }

    public function destroyAddress(string $id, string $addressId): Response
    {
        $customer = $this->requireCustomer($id);
        $address = $this->em->find(CustomerAddress::class, $addressId);
        if (!$address instanceof CustomerAddress || $address->customerId() !== $customer->id()) {
            $this->abort(404);
        }
        $this->em->remove($address);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('customers.addresses.flash.deleted'));

        return $this->redirectToShow($id);
    }

    public function storeContact(Request $request, string $id): Response
    {
        $customer = $this->requireCustomer($id);
        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->flash('error', $this->translator->trans('customers.contacts.validation'));

            return $this->redirectToShow($id);
        }
        $contact = new CustomerContact($customer->id(), $name);
        $contact->setRole(trim((string) $request->request->get('role', '')) ?: null);
        $contact->setEmail(trim((string) $request->request->get('email', '')) ?: null);
        $contact->setPhone(trim((string) $request->request->get('phone', '')) ?: null);
        $contact->setIsPrimary($request->request->get('is_primary') === '1');
        $this->em->persist($contact);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('customers.contacts.flash.created'));

        return $this->redirectToShow($id);
    }

    public function destroyContact(string $id, string $contactId): Response
    {
        $customer = $this->requireCustomer($id);
        $contact = $this->em->find(CustomerContact::class, $contactId);
        if (!$contact instanceof CustomerContact || $contact->customerId() !== $customer->id()) {
            $this->abort(404);
        }
        $this->em->remove($contact);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('customers.contacts.flash.deleted'));

        return $this->redirectToShow($id);
    }

    public function storeNote(Request $request, string $id): Response
    {
        $customer = $this->requireCustomer($id);
        $body = trim((string) $request->request->get('body', ''));
        if ($body === '') {
            $this->flash('error', $this->translator->trans('customers.notes.validation'));

            return $this->redirectToShow($id);
        }
        $userId = $this->auth->user()?->id();
        $note = new CustomerNote($customer->id(), $body, $userId);
        $this->em->persist($note);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('customers.notes.flash.created'));

        return $this->redirectToShow($id);
    }

    public function destroyNote(string $id, string $noteId): Response
    {
        $customer = $this->requireCustomer($id);
        $note = $this->em->find(CustomerNote::class, $noteId);
        if (!$note instanceof CustomerNote || $note->customerId() !== $customer->id()) {
            $this->abort(404);
        }
        $this->em->remove($note);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('customers.notes.flash.deleted'));

        return $this->redirectToShow($id);
    }

    private function requireCustomer(string $id): Customer
    {
        $customer = $this->find($id);
        if ($customer === null) {
            $this->abort(404, $this->translator->trans('customers.error.not_found'));
        }

        return $customer;
    }

    private function redirectToShow(string $id): Response
    {
        return $this->redirectTo($this->router->url('admin.customers.show', ['id' => $id], $this->request()) . '#overview');
    }

    public function edit(string $id): Response
    {
        $customer = $this->find($id);
        if ($customer === null) {
            $this->abort(404, $this->translator->trans('customers.error.not_found'));
        }

        return $this->page('@customers/form.html.twig', [
            'pageTitle' => $this->translator->trans('customers.edit'),
            'item' => $customer,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $customer = $this->find($id);
        if ($customer === null) {
            $this->abort(404, $this->translator->trans('customers.error.not_found'));
        }

        $data = $this->validated($request, $customer);
        $old = ['email' => $customer->email(), 'name' => $customer->name(), 'status' => $customer->status()];
        $previousStatus = $customer->status();
        $customer->setName($data['name']);
        $customer->setEmail($data['email']);
        $customer->setPhone($data['phone']);
        $customer->setCompany($data['company']);
        $customer->setNote($data['note']);
        $customer->setStatus($data['status']);
        $this->em->flush();
        $payload = $this->publicCustomer($customer);
        $this->events->dispatch(CustomerUpdated::make($customer->id(), $payload));
        if ($previousStatus !== 'archived' && $customer->status() === 'archived') {
            $this->events->dispatch(CustomerArchived::make($customer->id(), $payload));
        }
        if ($previousStatus === 'archived' && $customer->status() !== 'archived') {
            $this->events->dispatch(CustomerRestored::make($customer->id(), $payload));
        }
        $this->auditor->log('customer.updated', 'customer', $customer->id(), $old, [
            'email' => $customer->email(),
            'name' => $customer->name(),
            'status' => $customer->status(),
        ]);
        $this->forgetForm('customers.save');
        $this->flash('success', $this->translator->trans('customers.flash.saved'));

        return $this->redirect('admin.customers.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $customer = $this->find($id);
        if ($customer === null) {
            $this->abort(404, $this->translator->trans('customers.error.not_found'));
        }

        $name = $customer->name();
        $payload = $this->publicCustomer($customer);
        $this->hooks->doAction('customers.deleting', $customer);
        $this->em->remove($customer);
        $this->em->flush();
        $this->events->dispatch(CustomerDeleted::make($id, $payload));
        $this->hooks->doAction('customers.deleted', $id, $name);
        $this->auditor->log('customer.deleted', 'customer', $id);
        $this->flash('success', $this->translator->trans('customers.flash.deleted', ['%name%' => $name]));

        return $this->redirect('admin.customers.index');
    }

    private function find(string $id): ?Customer
    {
        $customer = $this->em->find(Customer::class, $id);

        return $customer instanceof Customer ? $customer : null;
    }

    /** @return array{name: string, email: string, phone: ?string, company: ?string, note: ?string, status: string} */
    private function validated(Request $request, ?Customer $existing = null): array
    {
        $data = $this->validate($request, 'customers.save', [
            'ignore_id' => $existing?->id(),
        ]);

        $phone = trim((string) ($data['phone'] ?? ''));
        $company = trim((string) ($data['company'] ?? ''));
        $note = $this->html->sanitizeNullable((string) ($data['note'] ?? ''));
        $status = (string) ($data['status'] ?? 'active');
        if (!in_array($status, ['active', 'inactive', 'lead', 'archived'], true)) {
            $status = 'active';
        }

        return [
            'name' => trim((string) $data['name']),
            'email' => strtolower(trim((string) $data['email'])),
            'phone' => $phone !== '' ? $phone : null,
            'company' => $company !== '' ? $company : null,
            'note' => $note,
            'status' => $status,
        ];
    }

    /** @return array<string, mixed> */
    private function publicCustomer(Customer $customer): array
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

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }
}
