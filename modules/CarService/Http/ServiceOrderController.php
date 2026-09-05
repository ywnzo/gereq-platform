<?php

declare(strict_types=1);

namespace Modules\CarService\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\User\Entity\User;
use Modules\CarService\Application\CarServiceProvider;
use Modules\CarService\Application\FinishOrderService;
use Modules\CarService\Application\OrderCalendarService;
use Modules\CarService\Application\OrderMediaService;
use Modules\CarService\Application\QrCodeService;
use Modules\CarService\Domain\ServiceJob;
use Modules\CarService\Domain\ServiceOrder;
use Modules\CarService\Domain\ServiceOrderLink;
use Modules\CarService\Domain\ServiceTeamSwitch;
use Modules\Customers\Domain\Customer;
use Modules\Documents\Application\DocumentsProvider;
use Modules\Fleet\Domain\Vehicle;
use Gereq\Module\CapabilityRegistry;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ServiceOrderController extends Controller
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
        private readonly CarServiceProvider $carservice,
        private readonly FinishOrderService $finishOrder,
        private readonly OrderMediaService $orderMedia,
        private readonly OrderCalendarService $orderCalendar,
        private readonly QrCodeService $qr,
        private readonly CapabilityRegistry $capabilities,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = \Gereq\Http\ListQuery::fromRequest(
            $request,
            sortable: ['status', 'team', 'updatedAt', 'createdAt'],
            defaultSort: 'updatedAt',
            defaultDir: 'desc',
            filterKeys: ['status', 'team', 'vehicle'],
        );
        $status = $list->filter('status');
        $team = $list->filter('team');
        $vehicleId = $list->filter('vehicle');

        $qb = $this->em->getRepository(ServiceOrder::class)->createQueryBuilder('o');
        if (in_array($status, ServiceOrder::STATUSES, true)) {
            $qb->andWhere('o.status = :s')->setParameter('s', $status);
        }
        if (in_array($team, ServiceOrder::TEAMS, true)) {
            $qb->andWhere('o.team = :t')->setParameter('t', $team);
        }
        if ($vehicleId !== '') {
            $qb->andWhere('o.vehicleId = :v')->setParameter('v', $vehicleId);
        }
        $qb->orderBy('o.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(o.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@carservice/orders/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('carservice.title'),
            'items' => $items,
            'vehicles' => $this->vehicleMap($items),
            'customers' => $this->customerMap($items),
            'status' => $status,
            'team' => $team,
            'statuses' => ServiceOrder::STATUSES,
            'teams' => ServiceOrder::TEAMS,
        ]));
    }

    public function create(Request $request): Response
    {
        return $this->page('@carservice/orders/form.html.twig', [
            'pageTitle' => $this->translator->trans('carservice.new'),
            'item' => null,
            'vehicleId' => (string) $request->query->get('vehicle', ''),
            'customerId' => (string) $request->query->get('customer', ''),
            'vehicleOptions' => $this->vehicleOptions(),
            'customerOptions' => $this->customerOptions(),
            'types' => ServiceOrder::TYPES,
            'teams' => ServiceOrder::TEAMS,
            'statuses' => ServiceOrder::STATUSES,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        try {
            $order = $this->carservice->createOrder($data['vehicle_id'], $data['customer_id'], [
                'type' => $data['type'],
                'team' => $data['team'],
                'branch_id' => $data['branch_id'],
                'note' => $data['note'],
                'intake_at' => $data['intake_at'],
                'handover_at' => $data['handover_at'],
            ]);
            if ($data['status'] !== 'intake') {
                $order->setStatus($data['status']);
                $this->em->flush();
            }
            $this->orderCalendar->syncIntakeHandover($order, $data['intake_at'], $data['handover_at']);
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.carservice.create');
        }
        $payload = $this->carservice->serialize($order);
        $this->events->emit('carservice.order_created', ['type' => 'carservice_order', 'id' => $order->id()], $payload);
        $this->auditor->log('carservice.created', 'carservice_order', $order->id());
        $this->forgetForm('carservice.save');
        $this->flash('success', $this->translator->trans('carservice.flash.created'));

        return $this->redirect('admin.carservice.show', ['id' => $order->id()]);
    }

    public function show(string $id): Response
    {
        $order = $this->carservice->getOrder($id);
        if ($order === null) {
            $this->abort(404, $this->translator->trans('carservice.error.not_found'));
        }
        $vehicle = $this->em->find(Vehicle::class, $order->vehicleId());
        $customer = $order->customerId() !== null ? $this->em->find(Customer::class, $order->customerId()) : null;
        $jobs = $this->carservice->jobsForOrder($id);
        $switches = $this->em->getRepository(ServiceTeamSwitch::class)->findBy(['orderId' => $id], ['createdAt' => 'DESC']);
        $links = $this->em->getRepository(ServiceOrderLink::class)->findBy(['orderId' => $id]);
        $mediaByPhase = [];
        foreach ($this->carservice->mediaPhases() as $phase) {
            $mediaByPhase[$phase] = $this->orderMedia->forOrder($id, $phase);
        }
        $documents = [];
        if ($this->capabilities->has('documents') && class_exists(DocumentsProvider::class)) {
            $documents = $this->capabilities->get('documents')->forEntity('carservice_order', $id);
        }
        $trackerUrl = $this->router->url('admin.carservice.tracker.show', ['id' => $id]);

        return $this->page('@carservice/orders/show.html.twig', [
            'pageTitle' => $this->translator->trans('carservice.order', ['%id%' => substr($id, 0, 8)]),
            'item' => $order,
            'vehicle' => $vehicle instanceof Vehicle ? $vehicle : null,
            'customer' => $customer instanceof Customer ? $customer : null,
            'jobs' => $jobs,
            'switches' => $switches,
            'links' => $links,
            'jobSeconds' => $this->carservice->totalJobSeconds($id),
            'statuses' => ServiceOrder::STATUSES,
            'teams' => ServiceOrder::TEAMS,
            'userOptions' => $this->userOptions(),
            'softLimitHours' => $this->carservice->jobSoftLimitHours(),
            'mediaPhases' => $this->carservice->mediaPhases(),
            'mediaByPhase' => $mediaByPhase,
            'mediaAvailable' => $this->orderMedia->available(),
            'documents' => $documents,
            'qrDataUri' => $this->qr->dataUri($order->trackerToken()),
            'trackerUrl' => $trackerUrl,
        ]);
    }

    public function edit(string $id): Response
    {
        $order = $this->carservice->getOrder($id);
        if ($order === null) {
            $this->abort(404, $this->translator->trans('carservice.error.not_found'));
        }

        return $this->page('@carservice/orders/form.html.twig', [
            'pageTitle' => $this->translator->trans('carservice.edit'),
            'item' => $order,
            'vehicleId' => $order->vehicleId(),
            'customerId' => $order->customerId() ?? '',
            'vehicleOptions' => $this->vehicleOptions(),
            'customerOptions' => $this->customerOptions(),
            'types' => ServiceOrder::TYPES,
            'teams' => ServiceOrder::TEAMS,
            'statuses' => ServiceOrder::STATUSES,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $order = $this->carservice->getOrder($id);
        if ($order === null) {
            $this->abort(404, $this->translator->trans('carservice.error.not_found'));
        }
        $data = $this->validated($request);
        if (!$this->em->find(Vehicle::class, $data['vehicle_id']) instanceof Vehicle) {
            $this->abort(422, $this->translator->trans('carservice.error.invalid_vehicle'));
        }
        $order->setVehicleId($data['vehicle_id']);
        $order->setCustomerId($data['customer_id']);
        $order->setType($data['type']);
        $order->setStatus($data['status']);
        $order->setTeam($data['team']);
        $order->setBranchId($data['branch_id']);
        $order->setNote($data['note']);
        $order->setIntakeAt($data['intake_at']);
        $order->setHandoverAt($data['handover_at']);
        $this->em->flush();
        $this->orderCalendar->syncIntakeHandover($order, $data['intake_at'], $data['handover_at']);
        $payload = $this->carservice->serialize($order);
        $this->events->emit('carservice.order_updated', ['type' => 'carservice_order', 'id' => $id], $payload);
        $this->auditor->log('carservice.updated', 'carservice_order', $id);
        $this->forgetForm('carservice.save');
        $this->flash('success', $this->translator->trans('carservice.flash.saved'));

        return $this->redirect('admin.carservice.show', ['id' => $id]);
    }

    public function updateStatus(Request $request, string $id): Response
    {
        $order = $this->carservice->getOrder($id);
        if ($order === null) {
            $this->abort(404, $this->translator->trans('carservice.error.not_found'));
        }
        $status = (string) $request->request->get('status', '');
        if (!in_array($status, ServiceOrder::STATUSES, true)) {
            $this->flash('error', $this->translator->trans('carservice.error.invalid_status'));

            return $this->redirect('admin.carservice.show', ['id' => $id]);
        }
        if ($status === 'done') {
            return $this->finish($id);
        }
        $order->setStatus($status);
        $this->em->flush();
        $this->events->emit('carservice.order_updated', ['type' => 'carservice_order', 'id' => $id], $this->carservice->serialize($order));
        $this->flash('success', $this->translator->trans('carservice.flash.status_changed'));

        return $this->redirect('admin.carservice.show', ['id' => $id]);
    }

    public function switchTeam(Request $request, string $id): Response
    {
        $team = (string) $request->request->get('team', '');
        try {
            $order = $this->carservice->switchTeam($id, $team, $this->auth->user()?->id());
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.carservice.show', ['id' => $id]);
        }
        $payload = $this->carservice->serialize($order);
        $this->events->emit('carservice.team_switched', ['type' => 'carservice_order', 'id' => $id], $payload);
        $this->events->emit('carservice.order_updated', ['type' => 'carservice_order', 'id' => $id], $payload);
        $this->flash('success', $this->translator->trans('carservice.flash.team_switched'));

        return $this->redirect('admin.carservice.show', ['id' => $id]);
    }

    public function storeJob(Request $request, string $id): Response
    {
        $data = $this->validate($request, 'carservice.job');
        try {
            $job = $this->carservice->addJob(
                $id,
                trim((string) ($data['type'] ?? 'work')) ?: 'work',
                trim((string) ($data['employee_user_id'] ?? '')) ?: $this->auth->user()?->id(),
                trim((string) ($data['note'] ?? '')) ?: null,
            );
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.carservice.show', ['id' => $id]);
        }
        $this->events->emit('carservice.job_started', ['type' => 'carservice_job', 'id' => $job->id()], $this->carservice->serializeJob($job));
        $this->forgetForm('carservice.job');
        $this->flash('success', $this->translator->trans('carservice.flash.job_started'));

        return $this->redirect('admin.carservice.show', ['id' => $id]);
    }

    public function completeJob(string $id, string $jobId): Response
    {
        try {
            $job = $this->carservice->completeJob($jobId);
            if ($job->orderId() !== $id) {
                $this->abort(404, $this->translator->trans('carservice.error.job_not_found'));
            }
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.carservice.show', ['id' => $id]);
        }
        $this->events->emit('carservice.job_finished', ['type' => 'carservice_job', 'id' => $jobId], $this->carservice->serializeJob($job));
        if ($this->carservice->jobExceededSoftLimit($job)) {
            $this->flash('warning', $this->translator->trans('carservice.flash.job_over_limit', [
                '%hours%' => (string) $this->carservice->jobSoftLimitHours(),
            ]));
        } else {
            $this->flash('success', $this->translator->trans('carservice.flash.job_finished'));
        }

        return $this->redirect('admin.carservice.show', ['id' => $id]);
    }

    public function finish(string $id): Response
    {
        try {
            $result = $this->finishOrder->finish($id);
            $order = $result['order'];
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.carservice.show', ['id' => $id]);
        }
        $payload = $this->carservice->serialize($order);
        if ($result['document'] !== null) {
            $payload['document_id'] = $result['document']->id();
        }
        $this->events->emit('carservice.order_finished', ['type' => 'carservice_order', 'id' => $id], $payload);
        $this->events->emit('carservice.order_updated', ['type' => 'carservice_order', 'id' => $id], $payload);
        $this->flash('success', $this->translator->trans(
            $result['document'] !== null ? 'carservice.flash.finished_with_doc' : 'carservice.flash.finished',
        ));

        return $this->redirect('admin.carservice.show', ['id' => $id]);
    }

    public function uploadMedia(Request $request, string $id): Response
    {
        $order = $this->carservice->getOrder($id);
        if ($order === null) {
            $this->abort(404, $this->translator->trans('carservice.error.not_found'));
        }
        $phase = trim((string) $request->request->get('phase', 'progress'));
        if (!in_array($phase, $this->carservice->mediaPhases(), true)) {
            $phase = $this->carservice->mediaPhases()[0] ?? 'progress';
        }
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->flash('error', $this->translator->trans('carservice.error.file_required'));

            return $this->redirect('admin.carservice.show', ['id' => $id]);
        }
        try {
            $this->orderMedia->upload($id, $file, $phase);
        } catch (\Throwable $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.carservice.show', ['id' => $id]);
        }
        $this->flash('success', $this->translator->trans('carservice.flash.media_uploaded'));

        return $this->redirect('admin.carservice.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $order = $this->carservice->getOrder($id);
        if ($order === null) {
            $this->abort(404, $this->translator->trans('carservice.error.not_found'));
        }
        foreach ($this->em->getRepository(ServiceJob::class)->findBy(['orderId' => $id]) as $j) {
            $this->em->remove($j);
        }
        foreach ($this->em->getRepository(ServiceTeamSwitch::class)->findBy(['orderId' => $id]) as $s) {
            $this->em->remove($s);
        }
        foreach ($this->em->getRepository(ServiceOrderLink::class)->findBy(['orderId' => $id]) as $l) {
            $this->em->remove($l);
        }
        $this->em->remove($order);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('carservice.flash.deleted'));

        return $this->redirect('admin.carservice.index');
    }

    /** @return array{vehicle_id: string, customer_id: ?string, type: string, status: string, team: string, branch_id: ?string, note: ?string, intake_at: ?\DateTimeImmutable, handover_at: ?\DateTimeImmutable} */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'carservice.save');
        $vehicleId = trim((string) $data['vehicle_id']);
        if (!$this->em->find(Vehicle::class, $vehicleId) instanceof Vehicle) {
            $this->abort(422, $this->translator->trans('carservice.error.invalid_vehicle'));
        }
        $customerId = trim((string) ($data['customer_id'] ?? ''));
        if ($customerId !== '' && !$this->em->find(Customer::class, $customerId) instanceof Customer) {
            $this->abort(422, $this->translator->trans('carservice.error.invalid_customer'));
        }
        $intake = trim((string) ($data['intake_at'] ?? ''));
        $handover = trim((string) ($data['handover_at'] ?? ''));

        return [
            'vehicle_id' => $vehicleId,
            'customer_id' => $customerId !== '' ? $customerId : null,
            'type' => in_array(($data['type'] ?? 'external_customer'), ServiceOrder::TYPES, true) ? (string) $data['type'] : 'external_customer',
            'status' => in_array(($data['status'] ?? 'intake'), ServiceOrder::STATUSES, true) ? (string) $data['status'] : 'intake',
            'team' => in_array(($data['team'] ?? 'body_shop'), ServiceOrder::TEAMS, true) ? (string) $data['team'] : 'body_shop',
            'branch_id' => trim((string) ($data['branch_id'] ?? '')) ?: null,
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
            'intake_at' => $intake !== '' ? new \DateTimeImmutable($intake) : null,
            'handover_at' => $handover !== '' ? new \DateTimeImmutable($handover) : null,
        ];
    }

    /** @param list<ServiceOrder> $items @return array<string, Vehicle> */
    private function vehicleMap(array $items): array
    {
        $ids = array_values(array_unique(array_map(static fn (ServiceOrder $o): string => $o->vehicleId(), $items)));
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach ($this->em->getRepository(Vehicle::class)->createQueryBuilder('v')->where('v.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult() as $v) {
            if ($v instanceof Vehicle) {
                $map[$v->id()] = $v;
            }
        }

        return $map;
    }

    /** @param list<ServiceOrder> $items @return array<string, Customer> */
    private function customerMap(array $items): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (ServiceOrder $o): ?string => $o->customerId(), $items))));
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
    private function vehicleOptions(): array
    {
        return array_map(
            static fn (Vehicle $v): array => ['value' => $v->id(), 'label' => $v->label()],
            $this->em->getRepository(Vehicle::class)->createQueryBuilder('v')->orderBy('v.plate', 'ASC')->setMaxResults(500)->getQuery()->getResult(),
        );
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
