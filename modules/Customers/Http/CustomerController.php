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
use Gereq\Publish\Adapter\CustomerPublishAdapter;
use Gereq\Publish\ProductionFreeze;
use Gereq\Security\HtmlSanitizer;
use Gereq\User\Entity\User;
use Modules\Customers\Domain\Customer;
use Modules\Customers\Domain\CustomerAddress;
use Modules\Customers\Domain\CustomerContact;
use Modules\Customers\Domain\CustomerCreated;
use Modules\Customers\Domain\CustomerNote;
use Modules\Customers\Domain\Events\CustomerArchived;
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
        private readonly ProductionFreeze $freeze,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    private function freezeCustomer(Customer $customer): void
    {
        $this->freeze->freezeIfNeeded(
            'customers',
            'customer',
            $customer->id(),
            fn (): array => CustomerPublishAdapter::capture($customer),
            $customer->name(),
            $this->auth->user()?->id(),
        );
    }

    private function stageDeleteCustomer(Customer $customer): void
    {
        $this->freeze->stageDelete(
            'customers',
            'customer',
            $customer->id(),
            fn (): array => CustomerPublishAdapter::capture($customer),
            $customer->name(),
            $this->auth->user()?->id(),
        );
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

        $deleteKeys = $this->freeze->pendingDeleteKeys('customers');
        if ($deleteKeys !== []) {
            $qb->andWhere('c.id NOT IN (:pendingDelete)')->setParameter('pendingDelete', $deleteKeys);
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
        $customer->setIsCompany($data['is_company']);
        $customer->setCompany($data['company']);
        $customer->setIco($data['ico']);
        $customer->setDic($data['dic']);
        $customer->setCompanyAddress($data['company_address']);
        $customer->setNote($data['note']);
        $customer->setStatus($data['status']);
        $this->em->persist($customer);
        $this->em->flush();
        $this->freeze->stageCreate(
            'customers',
            'customer',
            $customer->id(),
            $customer->name(),
            $this->auth->user()?->id(),
        );
        $this->events->dispatch(new CustomerCreated($customer->id(), $this->publicCustomer($customer)));
        $this->auditor->log(
            'customer.created',
            'customer',
            $customer->id(),
            null,
            ['name' => $customer->name(), 'email' => $customer->email()],
        );
        $this->forgetForm('customers.save');
        $this->flash('success', $this->translator->trans('publish.flash.staged'));

        return $this->redirect('admin.customers.show', ['id' => $customer->id()]);
    }

    public function show(string $id): Response
    {
        $customer = $this->find($id);
        if ($customer === null || $this->freeze->isPendingDelete('customers', $id)) {
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
        $notes = $this->em->getRepository(CustomerNote::class)->findBy(
            ['customerId' => $customer->id()],
            ['createdAt' => 'DESC'],
        );
        $authorIds = [];
        foreach ($notes as $note) {
            $authorId = $note->createdByUserId();
            if ($authorId !== null && $authorId !== '') {
                $authorIds[$authorId] = true;
            }
        }
        $authors = [];
        if ($authorIds !== []) {
            /** @var list<User> $users */
            $users = $this->em->getRepository(User::class)->findBy(['id' => array_keys($authorIds)]);
            foreach ($users as $author) {
                $authors[$author->id()] = $author->name();
            }
        }
        $timelineNotes = [];
        foreach ($notes as $note) {
            $authorId = $note->createdByUserId();
            $timelineNotes[] = [
                'note' => $note,
                'html' => $this->html->sanitize($note->body()),
                'author' => $authorId !== null ? ($authors[$authorId] ?? null) : null,
            ];
        }

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
        $this->freezeCustomer($customer);
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

            return $this->redirectToShow($id, 'addresses');
        }
        $address = new CustomerAddress($customer->id(), $type, $line1, $city, $zip, $country);
        $address->setLine2(trim((string) $request->request->get('line2', '')) ?: null);
        $address->setIsPrimary($request->request->get('is_primary') === '1');
        $this->em->persist($address);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('customers.addresses.flash.created'));

        return $this->redirectToShow($id, 'addresses');
    }

    public function destroyAddress(string $id, string $addressId): Response
    {
        $customer = $this->requireCustomer($id);
        $this->freezeCustomer($customer);
        $address = $this->em->find(CustomerAddress::class, $addressId);
        if (!$address instanceof CustomerAddress || $address->customerId() !== $customer->id()) {
            $this->abort(404);
        }
        $this->em->remove($address);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('customers.addresses.flash.deleted'));

        return $this->redirectToShow($id, 'addresses');
    }

    public function storeContact(Request $request, string $id): Response
    {
        $customer = $this->requireCustomer($id);
        $this->freezeCustomer($customer);
        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->flash('error', $this->translator->trans('customers.contacts.validation'));

            return $this->redirectToShow($id, 'contacts');
        }
        $contact = new CustomerContact($customer->id(), $name);
        $contact->setRole(trim((string) $request->request->get('role', '')) ?: null);
        $contact->setEmail(trim((string) $request->request->get('email', '')) ?: null);
        $contact->setPhone(trim((string) $request->request->get('phone', '')) ?: null);
        $contact->setIsPrimary($request->request->get('is_primary') === '1');
        $this->em->persist($contact);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('customers.contacts.flash.created'));

        return $this->redirectToShow($id, 'contacts');
    }

    public function destroyContact(string $id, string $contactId): Response
    {
        $customer = $this->requireCustomer($id);
        $this->freezeCustomer($customer);
        $contact = $this->em->find(CustomerContact::class, $contactId);
        if (!$contact instanceof CustomerContact || $contact->customerId() !== $customer->id()) {
            $this->abort(404);
        }
        $this->em->remove($contact);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('customers.contacts.flash.deleted'));

        return $this->redirectToShow($id, 'contacts');
    }

    public function storeNote(Request $request, string $id): Response
    {
        $customer = $this->requireCustomer($id);
        $this->freezeCustomer($customer);
        $body = $this->html->sanitize(trim((string) $request->request->get('body', '')));
        $plain = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($plain === '') {
            $this->flash('error', $this->translator->trans('customers.notes.validation'));

            return $this->redirectToShow($id, 'overview');
        }
        $userId = $this->auth->user()?->id();
        $note = new CustomerNote($customer->id(), $body, $userId);
        $this->em->persist($note);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('customers.notes.flash.created'));

        return $this->redirectToShow($id, 'overview');
    }

    public function destroyNote(string $id, string $noteId): Response
    {
        $customer = $this->requireCustomer($id);
        $this->freezeCustomer($customer);
        $note = $this->em->find(CustomerNote::class, $noteId);
        if (!$note instanceof CustomerNote || $note->customerId() !== $customer->id()) {
            $this->abort(404);
        }
        $this->em->remove($note);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('customers.notes.flash.deleted'));

        return $this->redirectToShow($id, 'overview');
    }

    private function requireCustomer(string $id): Customer
    {
        $customer = $this->find($id);
        if ($customer === null || $this->freeze->isPendingDelete('customers', $id)) {
            $this->abort(404, $this->translator->trans('customers.error.not_found'));
        }

        return $customer;
    }

    private function redirectToShow(string $id, string $tab = 'overview'): Response
    {
        $hash = in_array($tab, ['overview', 'contacts', 'addresses'], true) ? $tab : 'overview';

        return $this->redirectTo($this->router->url('admin.customers.show', ['id' => $id], $this->request()) . '#' . $hash);
    }

    public function edit(string $id): Response
    {
        $customer = $this->find($id);
        if ($customer === null || $this->freeze->isPendingDelete('customers', $id)) {
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
        if ($customer === null || $this->freeze->isPendingDelete('customers', $id)) {
            $this->abort(404, $this->translator->trans('customers.error.not_found'));
        }

        $data = $this->validated($request, $customer);
        $old = ['email' => $customer->email(), 'name' => $customer->name(), 'status' => $customer->status()];
        $previousStatus = $customer->status();
        $this->freezeCustomer($customer);
        $customer->setName($data['name']);
        $customer->setEmail($data['email']);
        $customer->setPhone($data['phone']);
        $customer->setIsCompany($data['is_company']);
        $customer->setCompany($data['company']);
        $customer->setIco($data['ico']);
        $customer->setDic($data['dic']);
        $customer->setCompanyAddress($data['company_address']);
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
        $this->flash('success', $this->translator->trans('publish.flash.staged'));

        return $this->redirect('admin.customers.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $customer = $this->find($id);
        if ($customer === null || $this->freeze->isPendingDelete('customers', $id)) {
            $this->abort(404, $this->translator->trans('customers.error.not_found'));
        }

        $name = $customer->name();
        $this->stageDeleteCustomer($customer);
        $this->auditor->log('customer.delete_staged', 'customer', $id);
        $this->flash('success', $this->translator->trans('publish.flash.staged'));

        return $this->redirect('admin.customers.index');
    }

    public function bulk(Request $request): Response
    {
        $data = $this->validate($request, 'customers.bulk');
        $action = (string) $data['action'];
        $user = $this->auth->user();
        $canEdit = $user !== null && $user->can('customers.edit');
        $canDelete = $user !== null && $user->can('customers.delete');

        if ($action === 'delete') {
            if (!$canDelete) {
                $this->abort(403);
            }
        } elseif (!$canEdit) {
            $this->abort(403);
        }

        $ids = $request->request->all('ids');
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $ids,
        ), static fn (string $id): bool => $id !== '')));

        if ($ids === []) {
            $this->flash('error', $this->translator->trans('customers.bulk.error.empty'));

            return $this->redirect('admin.customers.index');
        }
        if ($action === 'delete' && !$request->request->getBoolean('confirmed')) {
            $this->flash('error', $this->translator->trans('customers.bulk.error.confirm'));

            return $this->redirect('admin.customers.index');
        }

        $statusMap = [
            'activate' => 'active',
            'lead' => 'lead',
            'deactivate' => 'inactive',
            'archive' => 'archived',
        ];

        $affected = 0;
        foreach ($ids as $id) {
            $customer = $this->find($id);
            if ($customer === null) {
                continue;
            }

            if ($action === 'delete') {
                if ($this->freeze->isPendingDelete('customers', $customer->id())) {
                    continue;
                }
                $this->stageDeleteCustomer($customer);
                $this->auditor->log('customer.delete_staged', 'customer', $id);
                $affected++;
                continue;
            }

            $newStatus = $statusMap[$action] ?? null;
            if ($newStatus === null || $customer->status() === $newStatus) {
                continue;
            }
            if ($this->freeze->isPendingDelete('customers', $customer->id())) {
                continue;
            }

            $previousStatus = $customer->status();
            $this->freezeCustomer($customer);
            $customer->setStatus($newStatus);
            $payload = $this->publicCustomer($customer);
            $this->events->dispatch(CustomerUpdated::make($customer->id(), $payload));
            if ($previousStatus !== 'archived' && $newStatus === 'archived') {
                $this->events->dispatch(CustomerArchived::make($customer->id(), $payload));
            }
            if ($previousStatus === 'archived' && $newStatus !== 'archived') {
                $this->events->dispatch(CustomerRestored::make($customer->id(), $payload));
            }
            $this->auditor->log('customer.updated', 'customer', $customer->id(), [
                'status' => $previousStatus,
            ], [
                'status' => $newStatus,
            ]);
            $affected++;
        }

        $this->em->flush();
        $this->flash(
            'success',
            $action === 'delete'
                ? $this->translator->trans('publish.flash.staged')
                : $this->translator->trans('customers.bulk.flash.' . $action, [
                    '%count%' => (string) $affected,
                ]),
        );

        return $this->redirect('admin.customers.index');
    }

    private function find(string $id): ?Customer
    {
        $customer = $this->em->find(Customer::class, $id);

        return $customer instanceof Customer ? $customer : null;
    }

    /** @return array{name: string, email: string, phone: ?string, is_company: bool, company: ?string, ico: ?string, dic: ?string, company_address: ?string, note: ?string, status: string} */
    private function validated(Request $request, ?Customer $existing = null): array
    {
        $data = $this->validate($request, 'customers.save', [
            'ignore_id' => $existing?->id(),
        ]);

        $phone = trim((string) ($data['phone'] ?? ''));
        $isCompany = in_array($data['is_company'] ?? null, [true, 1, '1', 'on', 'yes', 'true'], true);
        $company = trim((string) ($data['company'] ?? ''));
        $ico = preg_replace('/\s+/', '', (string) ($data['ico'] ?? '')) ?? '';
        $dic = preg_replace('/\s+/', '', (string) ($data['dic'] ?? '')) ?? '';
        $companyAddress = trim((string) ($data['company_address'] ?? ''));
        $note = $this->html->sanitizeNullable((string) ($data['note'] ?? ''));
        $status = (string) ($data['status'] ?? 'active');
        if (!in_array($status, ['active', 'inactive', 'lead', 'archived'], true)) {
            $status = 'active';
        }

        if (!$isCompany) {
            $ico = '';
            $dic = '';
            $companyAddress = '';
        }

        return [
            'name' => trim((string) $data['name']),
            'email' => strtolower(trim((string) $data['email'])),
            'phone' => $phone !== '' ? $phone : null,
            'is_company' => $isCompany,
            'company' => $company !== '' ? $company : null,
            'ico' => $ico !== '' ? $ico : null,
            'dic' => $dic !== '' ? $dic : null,
            'company_address' => $companyAddress !== '' ? $companyAddress : null,
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
            'is_company' => $customer->isCompany(),
            'company' => $customer->company(),
            'ico' => $customer->ico(),
            'dic' => $customer->dic(),
            'company_address' => $customer->companyAddress(),
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
