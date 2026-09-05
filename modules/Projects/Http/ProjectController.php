<?php

declare(strict_types=1);

namespace Modules\Projects\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\User\Entity\User;
use Modules\Customers\Domain\Customer;
use Modules\Projects\Domain\Project;
use Modules\Projects\Domain\ProjectMember;
use Modules\Projects\Domain\ProjectTask;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ProjectController extends Controller
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
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = \Gereq\Http\ListQuery::fromRequest(
            $request,
            sortable: ['name', 'status', 'updatedAt', 'createdAt', 'dueAt'],
            defaultSort: 'updatedAt',
            defaultDir: 'desc',
            filterKeys: ['status', 'customer'],
        );
        $status = $list->filter('status');
        $customerId = $list->filter('customer');

        $qb = $this->em->getRepository(Project::class)->createQueryBuilder('p');
        $list->applySearch($qb, 'p', ['name']);
        if (in_array($status, Project::STATUSES, true)) {
            $qb->andWhere('p.status = :s')->setParameter('s', $status);
        }
        if ($customerId !== '') {
            $qb->andWhere('p.customerId = :c')->setParameter('c', $customerId);
        }
        $qb->orderBy('p.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(p.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@projects/projects/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('projects.title'),
            'items' => $items,
            'customers' => $this->customerMap($items),
            'status' => $status,
            'statuses' => Project::STATUSES,
        ]));
    }

    public function create(Request $request): Response
    {
        return $this->page('@projects/projects/form.html.twig', [
            'pageTitle' => $this->translator->trans('projects.new'),
            'item' => null,
            'customerId' => (string) $request->query->get('customer', ''),
            'customerOptions' => $this->customerOptions(),
            'userOptions' => $this->userOptions(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $project = new Project($data['customer_id'], $data['name']);
        $project->setStatus($data['status']);
        $project->setStartAt($data['start_at']);
        $project->setDueAt($data['due_at']);
        $project->setOwnerUserId($data['owner_user_id']);
        $project->setDealId($data['deal_id']);
        $this->em->persist($project);
        if ($data['owner_user_id']) {
            $this->em->persist(new ProjectMember($project->id(), $data['owner_user_id'], 'owner'));
        }
        $this->em->flush();
        $this->events->emit('project.created', ['type' => 'project', 'id' => $project->id()], $this->public($project));
        $this->auditor->log('projects.created', 'project', $project->id());
        $this->forgetForm('projects.save');
        $this->flash('success', $this->translator->trans('projects.flash.created', ['%name%' => $project->name()]));

        return $this->redirect('admin.projects.show', ['id' => $project->id()]);
    }

    public function show(string $id): Response
    {
        $project = $this->find($id);
        if ($project === null) {
            $this->abort(404, $this->translator->trans('projects.error.not_found'));
        }
        $tasks = $this->em->getRepository(ProjectTask::class)->findBy(['projectId' => $id], ['updatedAt' => 'DESC']);
        $customer = $this->em->find(Customer::class, $project->customerId());

        return $this->page('@projects/projects/show.html.twig', [
            'pageTitle' => $project->name(),
            'item' => $project,
            'tasks' => $tasks,
            'customer' => $customer instanceof Customer ? $customer : null,
            'statuses' => Project::STATUSES,
            'taskStatuses' => ProjectTask::STATUSES,
            'userOptions' => $this->userOptions(),
        ]);
    }

    public function edit(string $id): Response
    {
        $project = $this->find($id);
        if ($project === null) {
            $this->abort(404, $this->translator->trans('projects.error.not_found'));
        }

        return $this->page('@projects/projects/form.html.twig', [
            'pageTitle' => $this->translator->trans('projects.edit'),
            'item' => $project,
            'customerId' => $project->customerId(),
            'customerOptions' => $this->customerOptions(),
            'userOptions' => $this->userOptions(),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $project = $this->find($id);
        if ($project === null) {
            $this->abort(404, $this->translator->trans('projects.error.not_found'));
        }
        $data = $this->validated($request);
        $project->setCustomerId($data['customer_id']);
        $project->setName($data['name']);
        $project->setStatus($data['status']);
        $project->setStartAt($data['start_at']);
        $project->setDueAt($data['due_at']);
        $project->setOwnerUserId($data['owner_user_id']);
        $project->setDealId($data['deal_id']);
        $this->em->flush();
        $this->events->emit('project.updated', ['type' => 'project', 'id' => $project->id()], $this->public($project));
        $this->auditor->log('projects.updated', 'project', $id);
        $this->forgetForm('projects.save');
        $this->flash('success', $this->translator->trans('projects.flash.saved'));

        return $this->redirect('admin.projects.show', ['id' => $id]);
    }

    public function updateStatus(Request $request, string $id): Response
    {
        $project = $this->find($id);
        if ($project === null) {
            $this->abort(404, $this->translator->trans('projects.error.not_found'));
        }
        $status = (string) $request->request->get('status', '');
        if (!in_array($status, Project::STATUSES, true)) {
            $this->flash('error', $this->translator->trans('projects.error.invalid_status'));

            return $this->redirect('admin.projects.show', ['id' => $id]);
        }
        $project->setStatus($status);
        $this->em->flush();
        $payload = $this->public($project);
        $this->events->emit('project.status_changed', ['type' => 'project', 'id' => $id], $payload);
        $this->events->emit('project.updated', ['type' => 'project', 'id' => $id], $payload);
        $this->flash('success', $this->translator->trans('projects.flash.status_changed'));

        return $this->redirect('admin.projects.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $project = $this->find($id);
        if ($project === null) {
            $this->abort(404, $this->translator->trans('projects.error.not_found'));
        }
        $name = $project->name();
        foreach ($this->em->getRepository(ProjectTask::class)->findBy(['projectId' => $id]) as $t) {
            $this->em->remove($t);
        }
        foreach ($this->em->getRepository(ProjectMember::class)->findBy(['projectId' => $id]) as $m) {
            $this->em->remove($m);
        }
        $this->em->remove($project);
        $this->em->flush();
        $this->events->emit('project.deleted', ['type' => 'project', 'id' => $id], ['id' => $id, 'name' => $name]);
        $this->flash('success', $this->translator->trans('projects.flash.deleted', ['%name%' => $name]));

        return $this->redirect('admin.projects.index');
    }

    private function find(string $id): ?Project
    {
        $p = $this->em->find(Project::class, $id);

        return $p instanceof Project ? $p : null;
    }

    /** @return array<string, mixed> */
    private function public(Project $p): array
    {
        return [
            'id' => $p->id(),
            'customer_id' => $p->customerId(),
            'name' => $p->name(),
            'status' => $p->status(),
            'start_at' => $p->startAt()?->format('Y-m-d'),
            'due_at' => $p->dueAt()?->format('Y-m-d'),
            'owner_user_id' => $p->ownerUserId(),
            'deal_id' => $p->dealId(),
            'created_at' => $p->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $p->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array{customer_id: string, name: string, status: string, start_at: ?\DateTimeImmutable, due_at: ?\DateTimeImmutable, owner_user_id: ?string, deal_id: ?string} */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'projects.save');
        $customerId = trim((string) $data['customer_id']);
        if (!$this->em->find(Customer::class, $customerId) instanceof Customer) {
            $this->abort(422, $this->translator->trans('projects.error.invalid_customer'));
        }
        $start = trim((string) ($data['start_at'] ?? ''));
        $due = trim((string) ($data['due_at'] ?? ''));

        return [
            'customer_id' => $customerId,
            'name' => trim((string) $data['name']),
            'status' => in_array(($data['status'] ?? 'planning'), Project::STATUSES, true) ? (string) $data['status'] : 'planning',
            'start_at' => $start !== '' ? new \DateTimeImmutable($start) : null,
            'due_at' => $due !== '' ? new \DateTimeImmutable($due) : null,
            'owner_user_id' => trim((string) ($data['owner_user_id'] ?? '')) ?: null,
            'deal_id' => trim((string) ($data['deal_id'] ?? '')) ?: null,
        ];
    }

    /** @param list<Project> $items @return array<string, Customer> */
    private function customerMap(array $items): array
    {
        $ids = array_values(array_unique(array_map(static fn (Project $p): string => $p->customerId(), $items)));
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach ($this->em->getRepository(Customer::class)->createQueryBuilder('c')->where('c.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult() as $c) {
            if ($c instanceof Customer) {
                $map[$c->id()] = $c;
            }
        }

        return $map;
    }

    /** @return list<array{value: string, label: string}> */
    private function customerOptions(): array
    {
        return array_map(
            static fn (Customer $c): array => ['value' => $c->id(), 'label' => $c->name()],
            $this->em->getRepository(Customer::class)->createQueryBuilder('c')->orderBy('c.name', 'ASC')->getQuery()->getResult(),
        );
    }

    /** @return list<array{value: string, label: string}> */
    private function userOptions(): array
    {
        return array_map(
            static fn (User $u): array => ['value' => $u->id(), 'label' => $u->name() ?: $u->email()],
            $this->em->getRepository(User::class)->findBy(['status' => 'active'], ['name' => 'ASC']),
        );
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
