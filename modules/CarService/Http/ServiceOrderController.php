<?php

declare(strict_types=1);

namespace Modules\CarService\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Debug\FullServiceLogger;
use Gereq\Event\EventBus;
use Gereq\Hook\HookManager;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\User\Entity\User;
use Modules\CarService\Application\BranchContext;
use Modules\CarService\Application\CarServiceProvider;
use Modules\CarService\Application\FinishOrderService;
use Modules\CarService\Application\IntakeProtocolCatalog;
use Modules\CarService\Application\OrderCalendarService;
use Modules\CarService\Application\OrderMediaService;
use Modules\CarService\Application\PdfTemplateCatalog;
use Modules\CarService\Application\QrCodeService;
use Modules\CarService\Application\ServiceDocumentService;
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
        private readonly ServiceDocumentService $serviceDocuments,
        private readonly OrderMediaService $orderMedia,
        private readonly OrderCalendarService $orderCalendar,
        private readonly QrCodeService $qr,
        private readonly CapabilityRegistry $capabilities,
        private readonly BranchContext $branchContext,
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
        $branchId = $this->branchContext->branchId();
        if ($branchId !== null) {
            $qb->andWhere('o.branchId = :branch')->setParameter('branch', $branchId);
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
            'showBranchColumn' => $branchId === null,
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
            'branchOptions' => $this->branchOptions($this->branchContext->branchId()),
            'types' => ServiceOrder::TYPES,
            'teams' => ServiceOrder::TEAMS,
            'statuses' => ServiceOrder::STATUSES,
            'paymentTypes' => ServiceOrder::PAYMENT_TYPES,
            'fuelLevels' => \Modules\Fleet\Domain\Vehicle::FUEL_LEVELS,
            'defaultBranchId' => $this->branchContext->branchId() ?? '',
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
        if (!$this->branchContext->canAccessScoped($order->branchId())) {
            $this->abort(403, $this->translator->trans('carservice.error.branch_forbidden'));
        }
        $vehicle = $this->em->find(Vehicle::class, $order->vehicleId());
        $customer = $order->customerId() !== null ? $this->em->find(Customer::class, $order->customerId()) : null;
        $branch = $order->branchId() !== null ? $this->em->find(ServiceBranch::class, $order->branchId()) : null;
        $jobs = $this->carservice->jobsForOrder($id);
        $switches = $this->em->getRepository(ServiceTeamSwitch::class)->findBy(['orderId' => $id], ['createdAt' => 'DESC']);
        $links = $this->em->getRepository(ServiceOrderLink::class)->findBy(['orderId' => $id]);
        $mediaPhases = $this->carservice->mediaPhases();
        $mediaByPhase = $this->orderMedia->forOrderByPhases($id, $mediaPhases);
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
            'jobSeconds' => $this->carservice->totalJobSeconds($id, $jobs),
            'statuses' => ServiceOrder::STATUSES,
            'teams' => ServiceOrder::TEAMS,
            'userOptions' => $this->userOptions(),
            'softLimitHours' => $this->carservice->jobSoftLimitHours(),
            'mediaPhases' => $mediaPhases,
            'mediaByPhase' => $mediaByPhase,
            'mediaAvailable' => $this->orderMedia->available(),
            'documents' => $documents,
            'pdfTypes' => PdfTemplateCatalog::typeMeta(),
            'documentsAvailable' => $this->capabilities->has('documents'),
            'sections' => $sections,
            'qrDataUri' => $this->qr->dataUriForOrder($order->trackerToken()),
            'trackerUrl' => $this->qr->trackerUrl($order->trackerToken()),
            'trackerDeepLink' => $trackerUrl,
            'intakeCatalog' => [
                'equipment' => IntakeProtocolCatalog::equipment(),
                'services' => IntakeProtocolCatalog::services(),
                'inspection' => IntakeProtocolCatalog::inspectionItems(),
                'body' => IntakeProtocolCatalog::bodyItems(),
                'zones' => IntakeProtocolCatalog::zones(),
                'axles' => IntakeProtocolCatalog::axleItems(),
            ],
            'intakeProtocol' => IntakeProtocolCatalog::normalize($order->intakeProtocol() ?? []),
            'showIntakeProtocolForm' => !$this->serviceDocuments->orderHasDocumentType(
                $id,
                PdfTemplateCatalog::TYPE_INTAKE,
                $documents,
            ),
        ]);
    }

    public function edit(string $id): Response
    {
        $order = $this->orderForBranchMutate($id);

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
        $order = $this->orderForBranchMutate($id);
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
            if ($prevStatus !== $data['status']) {
                FullServiceLogger::log('carservice.order.status_changed', [
                    'order_id' => $id,
                    'from' => $prevStatus,
                    'to' => $data['status'],
                    'source' => 'update',
                ]);
            }
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
        $this->orderForBranchMutate($id);
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
        $order = $this->carservice->getOrder($id);
        if ($order === null) {
            $this->abort(404, $this->translator->trans('carservice.error.not_found'));
        }
        $prevStatus = $order->status();
        $order->setStatus($status);
        $this->em->flush();
        FullServiceLogger::log('carservice.order.status_changed', [
            'order_id' => $id,
            'from' => $prevStatus,
            'to' => $status,
            'source' => 'updateStatus',
        ]);
        $this->events->emit('carservice.order_updated', ['type' => 'carservice_order', 'id' => $id], $this->carservice->serialize($order));
        $this->flash('success', $this->translator->trans('carservice.flash.status_changed'));

        return $this->redirect('admin.carservice.show', ['id' => $id]);
    }

    public function switchTeam(Request $request, string $id): Response
    {
        $this->orderForBranchMutate($id);
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
        $this->orderForBranchMutate($id);
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
        $this->orderForBranchMutate($id);
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
        $this->orderForBranchMutate($id);
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
        FullServiceLogger::log('carservice.finish.http', [
            'order_id' => $id,
            'meta_keys' => array_keys($meta),
            'user_id' => $this->auth->user()?->id(),
        ]);
        try {
            $result = $this->finishOrder->finish($id, $meta);
            $order = $result['order'];
        } catch (\InvalidArgumentException $e) {
            FullServiceLogger::log('carservice.finish.http.error', [
                'order_id' => $id,
                'error' => $e->getMessage(),
            ]);
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.carservice.show', ['id' => $id]);
        }
        $payload = $this->carservice->serialize($order);
        if ($result['document'] !== null) {
            $payload['document_id'] = $result['document']->id();
        }
        FullServiceLogger::log('carservice.finish.emit', [
            'order_id' => $id,
            'status' => $order->status(),
            'document_id' => $result['document']?->id(),
            'job_seconds' => $payload['job_seconds'] ?? null,
        ]);
        $this->events->emit('carservice.order_finished', ['type' => 'carservice_order', 'id' => $id], $payload);
        $this->events->emit('carservice.order_updated', ['type' => 'carservice_order', 'id' => $id], $payload);
        $this->flash('success', $this->translator->trans(
            $result['document'] !== null ? 'carservice.flash.finished_with_doc' : 'carservice.flash.finished',
        ));

        return $this->redirect('admin.carservice.show', ['id' => $id]);
    }

    public function updateIntakeProtocol(Request $request, string $id): Response
    {
        $order = $this->orderForBranchMutate($id);

        $protocol = IntakeProtocolCatalog::normalize([
            'equipment' => $request->request->all('equipment'),
            'services' => $request->request->all('services'),
            'inspection' => $request->request->all('inspection'),
            'body' => $request->request->all('body'),
            'zones' => $request->request->all('zones'),
            'axles_front' => $request->request->all('axles_front'),
            'axles_rear' => $request->request->all('axles_rear'),
        ]);
        $order->setIntakeProtocol($protocol);
        $this->em->flush();

        $payload = $this->carservice->serialize($order);
        $this->events->emit('carservice.order_updated', ['type' => 'carservice_order', 'id' => $id], $payload);
        $this->auditor->log('carservice.intake_protocol_saved', 'carservice_order', $id);

        if ($this->capabilities->has('documents')) {
            try {
                $document = $this->serviceDocuments->generate($order, PdfTemplateCatalog::TYPE_INTAKE);
                $this->auditor->log('carservice.document_generated', 'carservice_order', $id, null, [
                    'type' => PdfTemplateCatalog::TYPE_INTAKE,
                    'document_id' => $document->id(),
                    'source' => 'intake_protocol',
                ]);
                $this->flash('success', $this->translator->trans('carservice.flash.intake_protocol_generated', [
                    '%title%' => $document->title(),
                ]));
            } catch (\Throwable) {
                $this->flash('error', $this->translator->trans('carservice.flash.intake_protocol_pdf_failed'));
            }
        } else {
            $this->flash('success', $this->translator->trans('carservice.flash.intake_protocol_saved'));
        }

        return $this->redirect('admin.carservice.show', ['id' => $id]);
    }

    public function generateDocument(Request $request, string $id): Response
    {
        $order = $this->orderForBranchMutate($id);
        if (!$this->capabilities->has('documents')) {
            $this->flash('error', $this->translator->trans('carservice.error.documents_unavailable'));

            return $this->redirect('admin.carservice.show', ['id' => $id]);
        }

        $data = $this->validate($request, 'carservice.document');
        $type = (string) ($data['type'] ?? '');
        if (!in_array($type, PdfTemplateCatalog::types(), true)) {
            $this->flash('error', $this->translator->trans('carservice.error.invalid_pdf_type'));

            return $this->redirect('admin.carservice.show', ['id' => $id]);
        }

        try {
            $document = $this->serviceDocuments->generate($order, $type, [
                'signature' => trim((string) ($data['signature'] ?? '')) ?: null,
            ]);
        } catch (\Throwable $e) {
            $this->flash('error', $this->translator->trans('carservice.flash.document_failed'));

            return $this->redirect('admin.carservice.show', ['id' => $id]);
        }

        $this->auditor->log('carservice.document_generated', 'carservice_order', $id, null, [
            'type' => $type,
            'document_id' => $document->id(),
        ]);
        $this->flash('success', $this->translator->trans('carservice.flash.document_generated', [
            '%title%' => $document->title(),
        ]));

        return $this->redirect('admin.carservice.show', ['id' => $id]);
    }

    public function uploadMedia(Request $request, string $id): Response
    {
        $this->orderForBranchMutate($id);
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
        $order = $this->orderForBranchMutate($id);
        $this->deleteOrder($order);
        $this->em->flush();
        $this->auditor->log('carservice.deleted', 'carservice_order', $id);
        $this->flash('success', $this->translator->trans('carservice.flash.deleted'));

        return $this->redirect('admin.carservice.index');
    }

    public function bulk(Request $request): Response
    {
        $data = $this->validate($request, 'carservice.bulk');
        $action = (string) ($data['action'] ?? '');
        $ids = $this->normalizeIds($request->request->all('ids'));

        if ($ids === []) {
            $this->flash('error', $this->translator->trans('carservice.bulk.error.empty'));

            return $this->redirect('admin.carservice.index');
        }
        if ($action === 'delete' && !$request->request->getBoolean('confirmed')) {
            $this->flash('error', $this->translator->trans('carservice.bulk.error.confirm'));

            return $this->redirect('admin.carservice.index');
        }
        if ($action !== 'delete') {
            $this->flash('error', $this->translator->trans('carservice.bulk.error.empty'));

            return $this->redirect('admin.carservice.index');
        }

        $affected = 0;
        foreach ($ids as $id) {
            $order = $this->carservice->getOrder($id);
            if ($order === null || !$this->branchContext->canMutateOrder($this->auth->user(), $order)) {
                continue;
            }
            $this->deleteOrder($order);
            $this->auditor->log('carservice.deleted', 'carservice_order', $id, null, ['bulk' => true]);
            $affected++;
        }
        $this->em->flush();
        $this->flash('success', $this->translator->trans('carservice.bulk.flash.delete', [
            '%count%' => (string) $affected,
        ]));

        return $this->redirect('admin.carservice.index');
    }

    private function orderForBranchMutate(string $id): ServiceOrder
    {
        $order = $this->carservice->getOrder($id);
        if ($order === null) {
            $this->abort(404, $this->translator->trans('carservice.error.not_found'));
        }
        if (!$this->branchContext->canMutateOrder($this->auth->user(), $order)) {
            $this->abort(403, $this->translator->trans('carservice.error.branch_forbidden'));
        }

        return $order;
    }

    private function deleteOrder(ServiceOrder $order): void
    {
        $id = $order->id();
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
    }

    /** @return list<string> */
    private function normalizeIds(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $ids,
        ), static fn (string $id): bool => $id !== '')));
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
        if ($branchId === '') {
            $branchId = (string) ($this->branchContext->branchId() ?? '');
        }
        if ($branchId !== '' && !$this->em->find(ServiceBranch::class, $branchId) instanceof ServiceBranch) {
            $this->abort(422, $this->translator->trans('carservice.error.invalid_branch'));
        }
        $user = $this->auth->user();
        if ($user instanceof User && !$user->isAdministrator()) {
            $home = $this->branchContext->defaultBranchId($user);
            if ($home !== null) {
                $branchId = $home;
            }
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
