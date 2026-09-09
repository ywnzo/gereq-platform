<?php

declare(strict_types=1);

namespace Modules\CRM\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\CRM\Domain\Activity;
use Modules\CRM\Domain\Deal;
use Modules\Customers\Domain\Customer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ActivityController extends Controller
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
        $search = trim((string) $request->query->get('q', ''));
        $type = (string) $request->query->get('type', '');
        $status = (string) $request->query->get('status', '');
        $customerId = (string) $request->query->get('customer', '');
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 20)));

        $qb = $this->em->getRepository(Activity::class)->createQueryBuilder('a')
            ->orderBy('a.dueAt', 'ASC')
            ->addOrderBy('a.createdAt', 'DESC');

        self_repair_search($qb, 'a', ['subject', 'body'], $search);
        if (in_array($type, Activity::TYPES, true)) {
            $qb->andWhere('a.type = :type')->setParameter('type', $type);
        }
        if ($status === 'open') {
            $qb->andWhere('a.completedAt IS NULL');
        } elseif ($status === 'done') {
            $qb->andWhere('a.completedAt IS NOT NULL');
        }
        if ($customerId !== '') {
            $qb->andWhere('a.customerId = :customer')->setParameter('customer', $customerId);
        }

        $total = (int) (clone $qb)->select('COUNT(a.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        return $this->page('@crm/activities/index.html.twig', [
            'pageTitle' => $this->translator->trans('crm.activities.title'),
            'items' => $items,
            'customers' => $this->customerMap($items),
            'search' => $search,
            'type' => $type,
            'status' => $status,
            'customerId' => $customerId,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages' => (int) ceil(max(1, $total) / $perPage),
            'types' => Activity::TYPES,
        ]);
    }

    public function create(Request $request): Response
    {
        $customerId = (string) $request->query->get('customer', '');
        $dealId = (string) $request->query->get('deal', '');

        return $this->page('@crm/activities/form.html.twig', [
            'pageTitle' => $this->translator->trans('crm.activities.new'),
            'item' => null,
            'customerId' => $customerId,
            'dealId' => $dealId,
            'customerOptions' => $this->customerOptions(),
            'dealOptions' => $this->dealOptions($customerId !== '' ? $customerId : null),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $activity = new Activity($data['customer_id'], $data['subject'], $data['type']);
        $activity->setDealId($data['deal_id']);
        $activity->setBody($data['body']);
        $activity->setDueAt($data['due_at']);
        $this->em->persist($activity);
        $this->em->flush();
        $this->events->emit('activity.created', ['type' => 'activity', 'id' => $activity->id()], $this->publicActivity($activity));
        $this->auditor->log('crm.activity.created', 'crm_activity', $activity->id(), null, [
            'subject' => $activity->subject(),
            'customer_id' => $activity->customerId(),
        ]);
        $this->forgetForm('crm.activity.save');
        $this->flash('success', $this->translator->trans('crm.flash.activity_created'));

        return $this->redirectAfterActivity($request, $activity->dealId());
    }

    public function edit(string $id): Response
    {
        $activity = $this->find($id);
        if ($activity === null) {
            $this->abort(404, $this->translator->trans('crm.error.activity_not_found'));
        }

        return $this->page('@crm/activities/form.html.twig', [
            'pageTitle' => $this->translator->trans('crm.activities.edit'),
            'item' => $activity,
            'customerId' => $activity->customerId(),
            'dealId' => $activity->dealId() ?? '',
            'customerOptions' => $this->customerOptions(),
            'dealOptions' => $this->dealOptions($activity->customerId()),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $activity = $this->find($id);
        if ($activity === null) {
            $this->abort(404, $this->translator->trans('crm.error.activity_not_found'));
        }

        $data = $this->validated($request);
        $activity->setCustomerId($data['customer_id']);
        $activity->setDealId($data['deal_id']);
        $activity->setType($data['type']);
        $activity->setSubject($data['subject']);
        $activity->setBody($data['body']);
        $activity->setDueAt($data['due_at']);
        $this->em->flush();
        $this->events->emit('activity.updated', ['type' => 'activity', 'id' => $activity->id()], $this->publicActivity($activity));
        $this->auditor->log('crm.activity.updated', 'crm_activity', $activity->id());
        $this->forgetForm('crm.activity.save');
        $this->flash('success', $this->translator->trans('crm.flash.activity_saved'));

        return $this->redirectAfterActivity($request, $activity->dealId());
    }

    public function complete(Request $request, string $id): Response
    {
        $activity = $this->find($id);
        if ($activity === null) {
            $this->abort(404, $this->translator->trans('crm.error.activity_not_found'));
        }

        if ($activity->isCompleted()) {
            $activity->reopen();
            $event = 'activity.reopened';
            $this->flash('success', $this->translator->trans('crm.flash.activity_reopened'));
        } else {
            $activity->complete();
            $event = 'activity.completed';
            $this->flash('success', $this->translator->trans('crm.flash.activity_completed'));
        }
        $this->em->flush();
        $payload = $this->publicActivity($activity);
        $this->events->emit($event, ['type' => 'activity', 'id' => $activity->id()], $payload);
        $this->events->emit('activity.updated', ['type' => 'activity', 'id' => $activity->id()], $payload);
        $this->auditor->log('crm.activity.toggled', 'crm_activity', $id);

        $dealId = $activity->dealId();
        if ($dealId === null || $dealId === '') {
            $dealId = trim((string) $request->request->get('deal_id', '')) ?: null;
        }

        return $this->redirectAfterActivity($request, $dealId);
    }

    public function destroy(string $id): Response
    {
        $activity = $this->find($id);
        if ($activity === null) {
            $this->abort(404, $this->translator->trans('crm.error.activity_not_found'));
        }

        $payload = $this->publicActivity($activity);
        $this->em->remove($activity);
        $this->em->flush();
        $this->events->emit('activity.deleted', ['type' => 'activity', 'id' => $id], $payload);
        $this->auditor->log('crm.activity.deleted', 'crm_activity', $id);
        $this->flash('success', $this->translator->trans('crm.flash.activity_deleted'));

        return $this->redirect('admin.crm.activities.index');
    }

    private function find(string $id): ?Activity
    {
        $activity = $this->em->find(Activity::class, $id);

        return $activity instanceof Activity ? $activity : null;
    }

    private function redirectAfterActivity(Request $request, ?string $dealId): Response
    {
        if ((string) $request->request->get('_return') === 'deal' && $dealId !== null && $dealId !== '') {
            return $this->redirect('admin.crm.deals.show', ['id' => $dealId]);
        }

        return $this->redirect('admin.crm.activities.index');
    }

    /** @return array<string, mixed> */
    private function publicActivity(Activity $activity): array
    {
        return [
            'id' => $activity->id(),
            'customer_id' => $activity->customerId(),
            'deal_id' => $activity->dealId(),
            'type' => $activity->type(),
            'subject' => $activity->subject(),
            'body' => $activity->body(),
            'due_at' => $activity->dueAt()?->format(\DateTimeInterface::ATOM),
            'completed_at' => $activity->completedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $activity->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $activity->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array{customer_id: string, deal_id: ?string, type: string, subject: string, body: ?string, due_at: ?\DateTimeImmutable}
     */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'crm.activity.save');

        $customerId = trim((string) ($data['customer_id'] ?? ''));
        $customer = $this->em->find(Customer::class, $customerId);
        if (!$customer instanceof Customer) {
            $this->abort(422, $this->translator->trans('crm.error.invalid_customer'));
        }

        $dealId = trim((string) ($data['deal_id'] ?? ''));
        if ($dealId !== '') {
            $deal = $this->em->find(Deal::class, $dealId);
            if (!$deal instanceof Deal || $deal->customerId() !== $customerId) {
                $this->abort(422, $this->translator->trans('crm.error.deal_customer_mismatch'));
            }
        } else {
            $dealId = '';
        }

        $type = (string) ($data['type'] ?? 'note');
        if (!in_array($type, Activity::TYPES, true)) {
            $type = 'note';
        }

        $dueRaw = trim((string) ($data['due_at'] ?? ''));
        $dueAt = null;
        if ($dueRaw !== '') {
            $dueAt = new \DateTimeImmutable($dueRaw);
        }

        $body = trim((string) ($data['body'] ?? ''));

        return [
            'customer_id' => $customerId,
            'deal_id' => $dealId !== '' ? $dealId : null,
            'type' => $type,
            'subject' => trim((string) $data['subject']),
            'body' => $body !== '' ? $body : null,
            'due_at' => $dueAt,
        ];
    }

    /**
     * @param list<Activity> $items
     * @return array<string, Customer>
     */
    private function customerMap(array $items): array
    {
        $ids = array_values(array_unique(array_map(static fn (Activity $a): string => $a->customerId(), $items)));
        if ($ids === []) {
            return [];
        }

        $rows = $this->em->getRepository(Customer::class)
            ->createQueryBuilder('c')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $customer) {
            /** @var Customer $customer */
            $map[$customer->id()] = $customer;
        }

        return $map;
    }

    /** @return list<array{value: string, label: string}> */
    private function customerOptions(): array
    {
        $rows = $this->em->getRepository(Customer::class)
            ->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (Customer $c): array => [
                'value' => $c->id(),
                'label' => $c->company() ? $c->name() . ' · ' . $c->company() : $c->name(),
            ],
            $rows
        );
    }

    /** @return list<array{value: string, label: string}> */
    private function dealOptions(?string $customerId): array
    {
        $qb = $this->em->getRepository(Deal::class)->createQueryBuilder('d')->orderBy('d.title', 'ASC');
        if ($customerId !== null && $customerId !== '') {
            $qb->andWhere('d.customerId = :c')->setParameter('c', $customerId);
        }

        return array_map(
            static fn (Deal $d): array => ['value' => $d->id(), 'label' => $d->title()],
            $qb->getQuery()->getResult()
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
