<?php

declare(strict_types=1);

namespace Modules\CarService\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Hook\HookManager;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\User\Entity\User;
use Modules\CarService\Application\CarServiceProvider;
use Modules\CarService\Application\FinishOrderService;
use Modules\CarService\Application\OrderCalendarService;
use Modules\CarService\Application\OrderMediaService;
use Modules\CarService\Application\QrCodeService;
use Modules\CarService\Domain\ServiceBranch;
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
        private readonly HookManager $hooks,
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
            'branches' => $this->branchMap($items),
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
            'branchOptions' => $this->branchOptions(),
            'types' => ServiceOrder::TYPES,
            'teams' => ServiceOrder::TEAMS,
            'statuses' => ServiceOrder::STATUSES,
            'paymentTypes' => ServiceOrder::PAYMENT_TYPES,
            'fuelLevels' => \Modules\Fleet\Domain\Vehicle::FUEL_LEVELS,
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
                'service_info' => $data['service_info'],
                'payment_type' => $data['payment_type'],
                'internal_cost' => $data['internal_cost'],
                'external_cost' => $data['external_cost'],
                'intake_mileage' => $data['intake_mileage'],
                'intake_fuel_level' => $data['intake_fuel_level'],
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
        $branch = $order->branchId() !== null ? $this->em->find(ServiceBranch::class, $order->branchId()) : null;
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
        $sections = $this->hooks->filter('carservice.order.show.sections', [], $order);

        return $this->page('@carservice/orders/show.html.twig', [
            'pageTitle' => $this->translator->trans('carservice.order', ['%id%' => substr($id, 0, 8)]),
            'item' => $order,
            'vehicle' => $vehicle instanceof Vehicle ? $vehicle : null,
            'customer' => $customer instanceof Customer ? $customer : null,
            'branch' => $branch instanceof ServiceBranch ? $branch : null,
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
            'sections' => $sections,
            'qrDataUri' => $this->qr->dataUriForOrder($order->trackerToken()),
            'trackerUrl' => $this->qr->trackerUrl($order->trackerToken()),
            'trackerDeepLink' => $trackerUrl,
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
            'branchOptions' => $this->branchOptions($order->branchId()),
            'types' => ServiceOrder::TYPES,
            'teams' => ServiceOrder::TEAMS,
            'statuses' => ServiceOrder::STATUSES,
            'paymentTypes' => ServiceOrder::PAYMENT_TYPES,
            'fuelLevels' => \Modules\Fleet\Domain\Vehicle::FUEL_LEVELS,
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
        $prevStatus = $order->status();
        $order->setVehicleId($data['vehicle_id']);
        $order->setCustomerId($data['customer_id']);
        $order->setType($data['type']);
        if ($data['status'] === 'cancelled' && $prevStatus !== 'cancelled') {
            $this->em->flush();
            $order = $this->carservice->cancelOrder($id);
        } elseif ($data['status'] === 'done' && $order->isOpen()) {
            return $this->finish($request, $id);
        } else {
            $order->setStatus($data['status']);
        }
        $order->setTeam($data['team']);
        $order->setBranchId($data['branch_id']);
        $order->setNote($data['note']);
        $order->setServiceInfo($data['service_info']);
        $order->setPaymentType($data['payment_type']);
        $order->setInternalCost($data['internal_cost']);
        $order->setExternalCost($data['external_cost']);
        $order->setIntakeMileage($data['intake_mileage']);
        $order->setIntakeFuelLevel($data['intake_fuel_level']);
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
            return $this->finish($request, $id);
        }
        if ($status === 'cancelled') {
            try {
                $order = $this->carservice->cancelOrder($id);
            } catch (\InvalidArgumentException $e) {
                $this->flash('error', $this->translator->trans($e->getMessage()));

                return $this->redirect('admin.carservice.show', ['id' => $id]);
            }
            $this->events->emit('carservice.order_updated', ['type' => 'carservice_order', 'id' => $id], $this->carservice->serialize($order));
            $this->flash('success', $this->translator->trans('carservice.flash.status_changed'));

            return $this->redirect('admin.carservice.show', ['id' => $id]);
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
        $note = trim((string) $request->request->get('note', '')) ?: null;
        try {
            $order = $this->carservice->switchTeam($id, $team, $this->auth->user()?->id(), $note);
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

    public function finish(Request $request, string $id): Response
    {
        $meta = [];
        if ($request->request->has('payment_type') || $request->request->has('signature') || $request->request->has('service_info')) {
            $data = $this->validate($request, 'carservice.finish');
            $mileage = trim((string) ($data['intake_mileage'] ?? ''));
            $meta = [
                'payment_type' => trim((string) ($data['payment_type'] ?? '')) ?: null,
                'internal_cost' => trim((string) ($data['internal_cost'] ?? '')) !== '' ? (string) $data['internal_cost'] : null,
                'external_cost' => trim((string) ($data['external_cost'] ?? '')) !== '' ? (string) $data['external_cost'] : null,
                'service_info' => trim((string) ($data['service_info'] ?? '')) ?: null,
                'intake_mileage' => $mileage !== '' ? (int) $mileage : null,
                'intake_fuel_level' => trim((string) ($data['intake_fuel_level'] ?? '')) ?: null,
                'note' => trim((string) ($data['note'] ?? '')) ?: null,
                'signature' => trim((string) ($data['signature'] ?? '')) ?: null,
            ];
        }
        try {
            $result = $this->finishOrder->finish($id, $meta);
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

    /** @return array<string, mixed> */
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
        $branchId = trim((string) ($data['branch_id'] ?? ''));
        if ($branchId !== '' && !$this->em->find(ServiceBranch::class, $branchId) instanceof ServiceBranch) {
            $this->abort(422, $this->translator->trans('carservice.error.invalid_branch'));
        }
        $intake = trim((string) ($data['intake_at'] ?? ''));
        $handover = trim((string) ($data['handover_at'] ?? ''));
        $mileage = trim((string) ($data['intake_mileage'] ?? ''));
        $internal = trim((string) ($data['internal_cost'] ?? ''));
        $external = trim((string) ($data['external_cost'] ?? ''));
        $payment = trim((string) ($data['payment_type'] ?? ''));

        return [
            'vehicle_id' => $vehicleId,
            'customer_id' => $customerId !== '' ? $customerId : null,
            'type' => in_array(($data['type'] ?? 'external_customer'), ServiceOrder::TYPES, true) ? (string) $data['type'] : 'external_customer',
            'status' => in_array(($data['status'] ?? 'intake'), ServiceOrder::STATUSES, true) ? (string) $data['status'] : 'intake',
            'team' => in_array(($data['team'] ?? 'body_shop'), ServiceOrder::TEAMS, true) ? (string) $data['team'] : 'body_shop',
            'branch_id' => $branchId !== '' ? $branchId : null,
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
            'service_info' => trim((string) ($data['service_info'] ?? '')) ?: null,
            'payment_type' => $payment !== '' && in_array($payment, ServiceOrder::PAYMENT_TYPES, true) ? $payment : null,
            'internal_cost' => $internal !== '' ? $internal : null,
            'external_cost' => $external !== '' ? $external : null,
            'intake_mileage' => $mileage !== '' ? (int) $mileage : null,
            'intake_fuel_level' => trim((string) ($data['intake_fuel_level'] ?? '')) ?: null,
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

    /** @param list<ServiceOrder> $items @return array<string, ServiceBranch> */
    private function branchMap(array $items): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (ServiceOrder $o): ?string => $o->branchId(), $items))));
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach ($this->em->getRepository(ServiceBranch::class)->createQueryBuilder('b')->where('b.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult() as $b) {
            if ($b instanceof ServiceBranch) {
                $map[$b->id()] = $b;
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
    private function branchOptions(?string $selectedId = null): array
    {
        $options = [['value' => '', 'label' => '—']];
        $seen = [];
        $branches = $this->em->getRepository(ServiceBranch::class)->findBy(['active' => true], ['name' => 'ASC']);
        foreach ($branches as $branch) {
            if ($branch instanceof ServiceBranch) {
                $options[] = ['value' => $branch->id(), 'label' => $branch->selectLabel()];
                $seen[$branch->id()] = true;
            }
        }
        if ($selectedId !== null && $selectedId !== '' && !isset($seen[$selectedId])) {
            $current = $this->em->find(ServiceBranch::class, $selectedId);
            if ($current instanceof ServiceBranch) {
                $options[] = ['value' => $current->id(), 'label' => $current->selectLabel()];
            }
        }

        return $options;
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
