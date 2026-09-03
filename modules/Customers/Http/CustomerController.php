<?php

declare(strict_types=1);

namespace Modules\Customers\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Customers\Domain\Customer;
use Modules\Customers\Domain\CustomerCreated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
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
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $status = (string) $request->query->get('status', '');
        $sort = (string) $request->query->get('sort', 'name');
        $dir = strtolower((string) $request->query->get('dir', 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;

        $qb = $this->em->getRepository(Customer::class)->createQueryBuilder('c');
        if ($search !== '') {
            $qb->andWhere('c.name LIKE :q OR c.email LIKE :q OR c.company LIKE :q OR c.phone LIKE :q')
                ->setParameter('q', '%' . $search . '%');
        }
        if (in_array($status, ['active', 'inactive', 'lead'], true)) {
            $qb->andWhere('c.status = :status')->setParameter('status', $status);
        }

        $allowedSort = ['name', 'email', 'company', 'status', 'createdAt'];
        if (!in_array($sort, $allowedSort, true)) {
            $sort = 'name';
        }
        $qb->orderBy('c.' . $sort, $dir);

        $total = (int) (clone $qb)->select('COUNT(c.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        return $this->page('@customers/index.html.twig', [
            'pageTitle' => 'Zákazníci',
            'items' => $items,
            'search' => $search,
            'status' => $status,
            'sort' => $sort,
            'dir' => strtolower($dir),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages' => (int) ceil(max(1, $total) / $perPage),
        ]);
    }

    public function create(): Response
    {
        return $this->page('@customers/form.html.twig', [
            'pageTitle' => 'Nový zákazník',
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
        $this->events->dispatch(new CustomerCreated($customer->id()));
        $this->auditor->log(
            'customer.created',
            'customer',
            $customer->id(),
            null,
            ['name' => $customer->name(), 'email' => $customer->email()],
        );
        $this->forgetForm('customers.save');
        $this->flash('success', 'Zákazník ' . $customer->name() . ' byl vytvořen.');

        return $this->redirect('admin.customers.show', ['id' => $customer->id()]);
    }

    public function show(string $id): Response
    {
        $customer = $this->find($id);
        if ($customer === null) {
            $this->abort(404, 'Zákazník neexistuje.');
        }

        return $this->page('@customers/show.html.twig', [
            'pageTitle' => $customer->name(),
            'item' => $customer,
        ]);
    }

    public function edit(string $id): Response
    {
        $customer = $this->find($id);
        if ($customer === null) {
            $this->abort(404, 'Zákazník neexistuje.');
        }

        return $this->page('@customers/form.html.twig', [
            'pageTitle' => 'Upravit zákazníka',
            'item' => $customer,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $customer = $this->find($id);
        if ($customer === null) {
            $this->abort(404, 'Zákazník neexistuje.');
        }

        $data = $this->validated($request, $customer);
        $old = ['email' => $customer->email(), 'name' => $customer->name(), 'status' => $customer->status()];
        $customer->setName($data['name']);
        $customer->setEmail($data['email']);
        $customer->setPhone($data['phone']);
        $customer->setCompany($data['company']);
        $customer->setNote($data['note']);
        $customer->setStatus($data['status']);
        $this->em->flush();
        $this->auditor->log('customer.updated', 'customer', $customer->id(), $old, [
            'email' => $customer->email(),
            'name' => $customer->name(),
            'status' => $customer->status(),
        ]);
        $this->forgetForm('customers.save');
        $this->flash('success', 'Zákazník byl uložen.');

        return $this->redirect('admin.customers.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $customer = $this->find($id);
        if ($customer === null) {
            $this->abort(404, 'Zákazník neexistuje.');
        }

        $name = $customer->name();
        $this->em->remove($customer);
        $this->em->flush();
        $this->auditor->log('customer.deleted', 'customer', $id);
        $this->flash('success', 'Zákazník ' . $name . ' byl smazán.');

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
        $note = trim((string) ($data['note'] ?? ''));
        $status = (string) ($data['status'] ?? 'active');
        if (!in_array($status, ['active', 'inactive', 'lead'], true)) {
            $status = 'active';
        }

        return [
            'name' => trim((string) $data['name']),
            'email' => strtolower(trim((string) $data['email'])),
            'phone' => $phone !== '' ? $phone : null,
            'company' => $company !== '' ? $company : null,
            'note' => $note !== '' ? $note : null,
            'status' => $status,
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
