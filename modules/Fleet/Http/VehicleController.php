<?php

declare(strict_types=1);

namespace Modules\Fleet\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Hook\HookManager;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Modules\Customers\Domain\Customer;
use Modules\Fleet\Application\FleetProvider;
use Modules\Fleet\Application\OrvClient;
use Modules\Fleet\Application\VehicleCalendarService;
use Modules\Fleet\Application\VehicleMediaService;
use Modules\Fleet\Domain\Vehicle;
use Modules\Fleet\Domain\VehicleBrand;
use Modules\Fleet\Domain\VehicleStatusLog;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class VehicleController extends Controller
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
        private readonly FleetProvider $fleet,
        private readonly HookManager $hooks,
        private readonly VehicleMediaService $vehicleMedia,
        private readonly VehicleCalendarService $vehicleCalendar,
        private readonly OrvClient $orv,
        private readonly CapabilityRegistry $capabilities,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = \Gereq\Http\ListQuery::fromRequest(
            $request,
            sortable: ['plate', 'status', 'updatedAt', 'createdAt', 'brand'],
            defaultSort: 'updatedAt',
            defaultDir: 'desc',
            filterKeys: ['status', 'ownership', 'customer'],
        );
        $status = $list->filter('status');
        $ownership = $list->filter('ownership');
        $customerId = $list->filter('customer');

        $qb = $this->em->getRepository(Vehicle::class)->createQueryBuilder('v');
        $list->applySearch($qb, 'v', ['plate', 'vin', 'brand', 'model']);
        if (in_array($status, Vehicle::STATUSES, true)) {
            $qb->andWhere('v.status = :s')->setParameter('s', $status);
        }
        if (in_array($ownership, Vehicle::OWNERSHIPS, true)) {
            $qb->andWhere('v.ownership = :o')->setParameter('o', $ownership);
        }
        if ($customerId !== '') {
            $qb->andWhere('v.customerId = :c')->setParameter('c', $customerId);
        }
        $branchId = $this->selectedBranchId();
        if ($branchId !== null) {
            $qb->andWhere('v.branchId = :branch')->setParameter('branch', $branchId);
        }
        $qb->orderBy('v.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(v.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();
        $covers = $this->vehicleMedia->coversForVehicles($items);
        $thumbs = [];
        foreach ($covers as $vehicleId => $asset) {
            $thumbs[$vehicleId] = $this->router->url('admin.media.file', ['id' => $asset->id()]);
        }
        $showBranchColumn = $branchId === null && $this->capabilities->has('carservice.branch_context');

        return $this->page('@fleet/vehicles/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('fleet.title'),
            'items' => $items,
            'thumbs' => $thumbs,
            'customers' => $this->customerMap($items),
            'branches' => $showBranchColumn ? $this->branchMap($items) : [],
            'showBranchColumn' => $showBranchColumn,
            'status' => $status,
            'ownership' => $ownership,
            'statuses' => Vehicle::STATUSES,
            'ownerships' => Vehicle::OWNERSHIPS,
        ]));
    }

    public function create(Request $request): Response
    {
        return $this->page('@fleet/vehicles/form.html.twig', [
            'pageTitle' => $this->translator->trans('fleet.new'),
            'item' => null,
            'customerId' => (string) $request->query->get('customer', ''),
            'customerOptions' => $this->customerOptions(),
            'statuses' => Vehicle::STATUSES,
            'ownerships' => Vehicle::OWNERSHIPS,
            'fuels' => Vehicle::FUELS,
            'gearboxes' => Vehicle::GEARBOXES,
            'fuelLevels' => Vehicle::FUEL_LEVELS,
            'fuelOptions' => $this->fuelOptions(),
            'orvConfigured' => $this->orv->configured(),
            'brandOptions' => $this->brandNames(),
            'branchOptions' => $this->branchOptions(),
            'defaultBranchId' => $this->selectedBranchId() ?? '',
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $existing = $this->fleet->findByPlate($data['plate']);
        if ($existing !== null) {
            $this->flash('error', $this->translator->trans('fleet.error.plate_exists'));

            return $this->redirect('admin.fleet.create');
        }
        $vehicle = new Vehicle($data['plate'], $data['ownership']);
        $this->apply($vehicle, $data);
        $this->fleet->ensureBrandModel($vehicle);
        $this->em->persist($vehicle);
        $this->em->persist(new VehicleStatusLog($vehicle->id(), null, $vehicle->status(), 'created', $this->auth->user()?->id()));
        $this->em->flush();
        $this->vehicleCalendar->syncReminders($vehicle);
        $payload = $this->fleet->serialize($vehicle);
        $this->events->emit('fleet.vehicle_created', ['type' => 'fleet_vehicle', 'id' => $vehicle->id()], $payload);
        $this->auditor->log('fleet.created', 'fleet_vehicle', $vehicle->id());
        $this->forgetForm('fleet.save');
        $this->flash('success', $this->translator->trans('fleet.flash.created', ['%plate%' => $vehicle->plate()]));

        return $this->redirect('admin.fleet.show', ['id' => $vehicle->id()]);
    }

    public function show(string $id): Response
    {
        $vehicle = $this->findInBranchScope($id);
        $logs = $this->em->getRepository(VehicleStatusLog::class)->findBy(
            ['vehicleId' => $id],
            ['createdAt' => 'DESC'],
            20,
        );
        $customer = $vehicle->customerId() !== null && class_exists(Customer::class)
            ? $this->em->find(Customer::class, $vehicle->customerId())
            : null;
        $sections = $this->hooks->filter('fleet.vehicle.show.sections', [], $vehicle);
        if (!is_array($sections)) {
            $sections = [];
        }
        usort($sections, static fn (array $a, array $b): int => ($a['order'] ?? 100) <=> ($b['order'] ?? 100));

        $mediaItems = $this->vehicleMedia->forVehicle($id);
        $tech = $vehicle->techJson() ?? [];
        $orvNotesRaw = (string) ($tech['orv_notes'] ?? '');
        $orvNoteLines = $orvNotesRaw !== ''
            ? \Modules\Fleet\Application\OrvClient::formatOrvNotesLines($orvNotesRaw)
            : [];

        return $this->page('@fleet/vehicles/show.html.twig', [
            'pageTitle' => $vehicle->label(),
            'item' => $vehicle,
            'customer' => $customer instanceof Customer ? $customer : null,
            'logs' => $logs,
            'statuses' => Vehicle::STATUSES,
            'hookSections' => $sections,
            'mediaAvailable' => $this->vehicleMedia->available(),
            'mediaItems' => $mediaItems,
            'mediaCoverLinkId' => $this->vehicleMedia->coverLinkId($id),
            'orvNoteLines' => $orvNoteLines,
        ]);
    }

    public function edit(string $id): Response
    {
        $vehicle = $this->findInBranchScope($id);

        return $this->page('@fleet/vehicles/form.html.twig', [
            'pageTitle' => $this->translator->trans('fleet.edit'),
            'item' => $vehicle,
            'customerId' => $vehicle->customerId() ?? '',
            'customerOptions' => $this->customerOptions(),
            'statuses' => Vehicle::STATUSES,
            'ownerships' => Vehicle::OWNERSHIPS,
            'fuels' => Vehicle::FUELS,
            'gearboxes' => Vehicle::GEARBOXES,
            'fuelLevels' => Vehicle::FUEL_LEVELS,
            'fuelOptions' => $this->fuelOptions(),
            'orvConfigured' => $this->orv->configured(),
            'brandOptions' => $this->brandNames(),
            'branchOptions' => $this->branchOptions($vehicle->branchId()),
            'defaultBranchId' => $vehicle->branchId() ?? '',
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $vehicle = $this->findInBranchScope($id);
        $data = $this->validated($request);
        $other = $this->fleet->findByPlate($data['plate']);
        if ($other !== null && $other->id() !== $id) {
            $this->flash('error', $this->translator->trans('fleet.error.plate_exists'));

            return $this->redirect('admin.fleet.edit', ['id' => $id]);
        }
        $prevStatus = $vehicle->status();
        $this->apply($vehicle, $data);
        $this->fleet->ensureBrandModel($vehicle);
        if ($prevStatus !== $vehicle->status()) {
            $this->em->persist(new VehicleStatusLog($id, $prevStatus, $vehicle->status(), 'edit', $this->auth->user()?->id()));
        }
        $this->em->flush();
        $this->vehicleCalendar->syncReminders($vehicle);
        $payload = $this->fleet->serialize($vehicle);
        $this->events->emit('fleet.vehicle_updated', ['type' => 'fleet_vehicle', 'id' => $id], $payload);
        if ($prevStatus !== $vehicle->status()) {
            $this->events->emit('fleet.vehicle_status_changed', ['type' => 'fleet_vehicle', 'id' => $id], $payload);
        }
        $this->auditor->log('fleet.updated', 'fleet_vehicle', $id);
        $this->forgetForm('fleet.save');
        $this->flash('success', $this->translator->trans('fleet.flash.saved'));

        return $this->redirect('admin.fleet.show', ['id' => $id]);
    }

    public function updateStatus(Request $request, string $id): Response
    {
        $vehicle = $this->findInBranchScope($id);
        $status = (string) $request->request->get('status', '');
        $reason = trim((string) $request->request->get('reason', '')) ?: null;
        if (!in_array($status, Vehicle::STATUSES, true)) {
            $this->flash('error', $this->translator->trans('fleet.error.invalid_status'));

            return $this->redirect('admin.fleet.show', ['id' => $id]);
        }
        $this->fleet->setStatus($id, $status, $reason, $this->auth->user()?->id());
        $payload = $this->fleet->serialize($this->find($id) ?? $vehicle);
        $this->events->emit('fleet.vehicle_status_changed', ['type' => 'fleet_vehicle', 'id' => $id], $payload);
        $this->events->emit('fleet.vehicle_updated', ['type' => 'fleet_vehicle', 'id' => $id], $payload);
        $this->flash('success', $this->translator->trans('fleet.flash.status_changed'));

        return $this->redirect('admin.fleet.show', ['id' => $id]);
    }

    public function uploadMedia(Request $request, string $id): Response
    {
        $this->findInBranchScope($id);
        $label = trim((string) $request->request->get('label', ''));
        $rawFiles = $request->files->get('files');
        if (!is_array($rawFiles)) {
            $single = $request->files->get('file');
            $rawFiles = $single instanceof UploadedFile ? [$single] : [];
        }
        $files = array_values(array_filter(
            $rawFiles,
            static fn ($f) => $f instanceof UploadedFile && $f->isValid(),
        ));
        if ($files === []) {
            $this->flash('error', $this->translator->trans('fleet.error.file_required'));

            return $this->redirect('admin.fleet.show', ['id' => $id]);
        }
        try {
            $count = $label !== ''
                ? $this->vehicleMedia->uploadMany($id, $files, $label)
                : $this->vehicleMedia->uploadManyGuessed($id, $files);
        } catch (\Throwable $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.fleet.show', ['id' => $id]);
        }
        if ($count === 0) {
            $this->flash('error', $this->translator->trans('fleet.error.file_required'));
        } else {
            $this->flash('success', $this->translator->trans(
                $count === 1 ? 'fleet.flash.media_uploaded' : 'fleet.flash.media_uploaded_many',
                ['%count%' => (string) $count],
            ));
        }

        return $this->redirect('admin.fleet.show', ['id' => $id]);
    }

    public function setMediaCover(string $id, string $linkId): Response
    {
        $this->findInBranchScope($id);
        try {
            $this->vehicleMedia->setCover($id, $linkId);
        } catch (\Throwable $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.fleet.show', ['id' => $id]);
        }
        $this->flash('success', $this->translator->trans('fleet.flash.media_cover'));

        return $this->redirect('admin.fleet.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $vehicle = $this->findInBranchScope($id);
        $plate = $vehicle->plate();
        $this->deleteVehicle($vehicle);
        $this->em->flush();
        $this->events->emit('fleet.vehicle_deleted', ['type' => 'fleet_vehicle', 'id' => $id], ['id' => $id, 'plate' => $plate]);
        $this->flash('success', $this->translator->trans('fleet.flash.deleted', ['%plate%' => $plate]));

        return $this->redirect('admin.fleet.index');
    }

    public function bulk(Request $request): Response
    {
        $data = $this->validate($request, 'fleet.bulk');
        $action = (string) ($data['action'] ?? '');
        $ids = $this->normalizeIds($request->request->all('ids'));

        if ($ids === []) {
            $this->flash('error', $this->translator->trans('fleet.bulk.error.empty'));

            return $this->redirect('admin.fleet.index');
        }
        if ($action === 'delete' && !$request->request->getBoolean('confirmed')) {
            $this->flash('error', $this->translator->trans('fleet.bulk.error.confirm'));

            return $this->redirect('admin.fleet.index');
        }
        if ($action !== 'delete') {
            $this->flash('error', $this->translator->trans('fleet.bulk.error.empty'));

            return $this->redirect('admin.fleet.index');
        }

        $affected = 0;
        foreach ($ids as $id) {
            $vehicle = $this->find($id);
            if ($vehicle === null || !$this->isVehicleInBranchScope($vehicle)) {
                continue;
            }
            $plate = $vehicle->plate();
            $this->deleteVehicle($vehicle);
            $this->events->emit('fleet.vehicle_deleted', ['type' => 'fleet_vehicle', 'id' => $id], [
                'id' => $id,
                'plate' => $plate,
                'bulk' => true,
            ]);
            $affected++;
        }
        $this->em->flush();
        $this->flash('success', $this->translator->trans('fleet.bulk.flash.delete', [
            '%count%' => (string) $affected,
        ]));

        return $this->redirect('admin.fleet.index');
    }

    private function deleteVehicle(Vehicle $vehicle): void
    {
        $id = $vehicle->id();
        foreach ($this->em->getRepository(VehicleStatusLog::class)->findBy(['vehicleId' => $id]) as $log) {
            $this->em->remove($log);
        }
        $this->em->remove($vehicle);
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

    public function orvLookup(Request $request): Response
    {
        $data = $this->validate($request, 'fleet.orv');
        $result = $this->orv->lookup((string) $data['orv_id']);
        if (!$result['ok']) {
            $msg = $result['error'] ?? 'fleet.error.orv_request_failed';

            return new JsonResponse([
                'ok' => false,
                'error' => str_starts_with((string) $msg, 'fleet.') ? $this->translator->trans((string) $msg) : (string) $msg,
            ], 422);
        }

        return new JsonResponse([
            'ok' => true,
            'fields' => $this->orv->mapToVehicleFields($result['data'] ?? []),
            'raw' => $result['data'],
        ]);
    }

    private function find(string $id): ?Vehicle
    {
        return $this->fleet->find($id);
    }

    private function findInBranchScope(string $id): Vehicle
    {
        $vehicle = $this->find($id);
        if ($vehicle === null) {
            $this->abort(404, $this->translator->trans('fleet.error.not_found'));
        }
        $this->assertBranchScope($vehicle);

        return $vehicle;
    }

    private function assertBranchScope(Vehicle $vehicle): void
    {
        if (!$this->isVehicleInBranchScope($vehicle)) {
            $this->abort(403, $this->translator->trans('fleet.error.branch_forbidden'));
        }
    }

    private function isVehicleInBranchScope(Vehicle $vehicle): bool
    {
        if (!$this->capabilities->has('carservice.branch_context')) {
            return true;
        }
        $ctx = $this->capabilities->get('carservice.branch_context');
        if (!is_object($ctx) || !method_exists($ctx, 'canAccessScoped')) {
            return true;
        }

        return (bool) $ctx->canAccessScoped($vehicle->branchId());
    }

    /** @param array<string, mixed> $data */
    private function apply(Vehicle $vehicle, array $data): void
    {
        $vehicle->setPlate($data['plate']);
        $vehicle->setVin($data['vin']);
        $vehicle->setOrvId($data['orv_id']);
        $vehicle->setBrand($data['brand']);
        $vehicle->setModel($data['model']);
        $vehicle->setModelYear($data['model_year']);
        $vehicle->setColor($data['color']);
        $vehicle->setFuel($data['fuel']);
        $vehicle->setGearbox($data['gearbox']);
        $vehicle->setMotorization($data['motorization']);
        $vehicle->setMileage($data['mileage']);
        $vehicle->setFuelLevel($data['fuel_level']);
        $vehicle->setConditionJson($data['condition']);
        $vehicle->setStatus($data['status']);
        $vehicle->setOwnership($data['ownership']);
        $vehicle->setCustomerId($data['customer_id']);
        $vehicle->setBranchId($data['branch_id']);
        $vehicle->setStkAt($data['stk_at']);
        $vehicle->setInsuranceUntil($data['insurance_until']);
        $vehicle->setVignetteUntil($data['vignette_until']);
        $vehicle->setTiresAt($data['tires_at']);
        $vehicle->setWarrantyUntil($data['warranty_until']);
        $vehicle->setNote($data['note']);
        $vehicle->setTechJson($data['tech']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'fleet.save');
        $customerId = trim((string) ($data['customer_id'] ?? ''));
        if ($customerId !== '' && class_exists(Customer::class) && !$this->em->find(Customer::class, $customerId) instanceof Customer) {
            $this->abort(422, $this->translator->trans('fleet.error.invalid_customer'));
        }
        $branchId = trim((string) ($data['branch_id'] ?? ''));
        if ($branchId === '') {
            $branchId = (string) ($this->selectedBranchId() ?? '');
        }
        $scope = $this->selectedBranchId();
        if ($scope !== null) {
            $branchId = $scope;
        }
        if ($branchId !== '' && !$this->isValidBranchId($branchId)) {
            $this->abort(422, $this->translator->trans('fleet.error.invalid_branch'));
        }
        $year = trim((string) ($data['model_year'] ?? ''));
        $mileage = trim((string) ($data['mileage'] ?? ''));
        $stk = trim((string) ($data['stk_at'] ?? ''));
        $ins = trim((string) ($data['insurance_until'] ?? ''));
        $vig = trim((string) ($data['vignette_until'] ?? ''));
        $tires = trim((string) ($data['tires_at'] ?? ''));
        $warranty = trim((string) ($data['warranty_until'] ?? ''));
        $gearbox = trim((string) ($data['gearbox'] ?? ''));
        $fuelLevel = trim((string) ($data['fuel_level'] ?? ''));
        $condition = array_filter([
            'interior' => trim((string) ($data['condition_interior'] ?? '')) ?: null,
            'exterior' => trim((string) ($data['condition_exterior'] ?? '')) ?: null,
            'damage' => trim((string) ($data['condition_damage'] ?? '')) ?: null,
        ], static fn ($v) => $v !== null);

        $techKeys = [
            'vehicle_kind', 'body_type', 'category', 'emission', 'engine_code',
            'power_kw', 'displacement_cc', 'weight_kg', 'seats', 'max_speed',
            'consumption', 'tire_size', 'tp_number', 'first_registered_at',
            'homologation', 'version_code', 'generation', 'orv_notes',
        ];
        $tech = [];
        foreach ($techKeys as $key) {
            $raw = $data[$key] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            if (in_array($key, ['power_kw', 'displacement_cc', 'weight_kg', 'seats', 'max_speed'], true)) {
                $tech[$key] = (int) $raw;
            } elseif ($key === 'orv_notes') {
                $tech[$key] = \Modules\Fleet\Application\OrvClient::formatOrvNotes((string) $raw);
            } else {
                $tech[$key] = trim((string) $raw);
            }
        }
        if (!isset($tech['generation'])) {
            $guess = \Modules\Fleet\Application\VehicleGeneration::resolve(
                isset($data['brand']) ? (string) $data['brand'] : null,
                isset($data['model']) ? (string) $data['model'] : null,
                isset($data['model_year']) && $data['model_year'] !== '' && $data['model_year'] !== null ? (int) $data['model_year'] : null,
            );
            if ($guess !== null) {
                $tech['generation'] = $guess;
            }
        }

        return [
            'plate' => Vehicle::normalizePlate((string) $data['plate']),
            'vin' => trim((string) ($data['vin'] ?? '')) ?: null,
            'orv_id' => trim((string) ($data['orv_id'] ?? '')) ?: null,
            'brand' => trim((string) ($data['brand'] ?? '')) ?: null,
            'model' => trim((string) ($data['model'] ?? '')) ?: null,
            'model_year' => $year !== '' ? (int) $year : null,
            'color' => trim((string) ($data['color'] ?? '')) ?: null,
            'fuel' => trim((string) ($data['fuel'] ?? '')) ?: null,
            'gearbox' => $gearbox !== '' && in_array($gearbox, Vehicle::GEARBOXES, true) ? $gearbox : null,
            'motorization' => trim((string) ($data['motorization'] ?? '')) ?: null,
            'mileage' => $mileage !== '' ? (int) $mileage : null,
            'fuel_level' => $fuelLevel !== '' ? $fuelLevel : null,
            'condition' => $condition !== [] ? $condition : null,
            'status' => in_array(($data['status'] ?? 'available'), Vehicle::STATUSES, true) ? (string) $data['status'] : 'available',
            'ownership' => in_array(($data['ownership'] ?? 'owned'), Vehicle::OWNERSHIPS, true) ? (string) $data['ownership'] : 'owned',
            'customer_id' => $customerId !== '' ? $customerId : null,
            'branch_id' => $branchId !== '' ? $branchId : null,
            'stk_at' => $stk !== '' ? new \DateTimeImmutable($stk) : null,
            'insurance_until' => $ins !== '' ? new \DateTimeImmutable($ins) : null,
            'vignette_until' => $vig !== '' ? new \DateTimeImmutable($vig) : null,
            'tires_at' => $tires !== '' ? new \DateTimeImmutable($tires) : null,
            'warranty_until' => $warranty !== '' ? new \DateTimeImmutable($warranty) : null,
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
            'tech' => $tech !== [] ? $tech : null,
        ];
    }

    /** @param list<Vehicle> $items @return array<string, Customer> */
    private function customerMap(array $items): array
    {
        if (!class_exists(Customer::class)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (Vehicle $v): ?string => $v->customerId(),
            $items,
        ))));
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
        if (!class_exists(Customer::class)) {
            return [];
        }

        return array_map(
            static fn (Customer $c): array => ['value' => $c->id(), 'label' => $c->name()],
            $this->em->getRepository(Customer::class)->createQueryBuilder('c')->orderBy('c.name', 'ASC')->getQuery()->getResult(),
        );
    }

    /** @return list<string> */
    private function brandNames(): array
    {
        return array_map(
            static fn (VehicleBrand $b): string => $b->name(),
            $this->em->getRepository(VehicleBrand::class)->findBy([], ['name' => 'ASC']),
        );
    }

    /** @return list<array{value: string, label: string}> */
    private function fuelOptions(): array
    {
        $opts = [['value' => '', 'label' => '—']];
        foreach (Vehicle::FUELS as $fuel) {
            $opts[] = ['value' => $fuel, 'label' => $this->translator->trans('fleet.fuel.' . $fuel)];
        }

        return $opts;
    }

    private function selectedBranchId(): ?string
    {
        if (!$this->capabilities->has('carservice.branch_context')) {
            return null;
        }
        $ctx = $this->capabilities->get('carservice.branch_context');
        if (!is_object($ctx) || !method_exists($ctx, 'branchId')) {
            return null;
        }
        $id = $ctx->branchId();

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function isValidBranchId(string $id): bool
    {
        if (!class_exists(\Modules\CarService\Domain\ServiceBranch::class)) {
            return false;
        }

        return $this->em->find(\Modules\CarService\Domain\ServiceBranch::class, $id) instanceof \Modules\CarService\Domain\ServiceBranch;
    }

    /**
     * @param list<Vehicle> $items
     * @return array<string, \Modules\CarService\Domain\ServiceBranch>
     */
    private function branchMap(array $items): array
    {
        if (!class_exists(\Modules\CarService\Domain\ServiceBranch::class)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (Vehicle $v): ?string => $v->branchId(),
            $items,
        ))));
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach (
            $this->em->getRepository(\Modules\CarService\Domain\ServiceBranch::class)
                ->createQueryBuilder('b')
                ->where('b.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->getQuery()
                ->getResult() as $branch
        ) {
            if ($branch instanceof \Modules\CarService\Domain\ServiceBranch) {
                $map[$branch->id()] = $branch;
            }
        }

        return $map;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function branchOptions(?string $selectedId = null): array
    {
        if (!class_exists(\Modules\CarService\Domain\ServiceBranch::class)) {
            return [];
        }
        /** @var list<\Modules\CarService\Domain\ServiceBranch> $branches */
        $branches = $this->em->getRepository(\Modules\CarService\Domain\ServiceBranch::class)
            ->findBy(['active' => true], ['name' => 'ASC']);
        $options = [];
        $seen = [];
        foreach ($branches as $branch) {
            $options[] = ['value' => $branch->id(), 'label' => $branch->selectLabel()];
            $seen[$branch->id()] = true;
        }
        if ($selectedId !== null && $selectedId !== '' && !isset($seen[$selectedId])) {
            $current = $this->em->find(\Modules\CarService\Domain\ServiceBranch::class, $selectedId);
            if ($current instanceof \Modules\CarService\Domain\ServiceBranch) {
                array_unshift($options, ['value' => $current->id(), 'label' => $current->selectLabel()]);
            }
        }

        return $options;
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
