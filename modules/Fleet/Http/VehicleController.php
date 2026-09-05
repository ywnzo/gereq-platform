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
use Modules\Customers\Domain\Customer;
use Modules\Fleet\Application\FleetProvider;
use Modules\Fleet\Application\VehicleCalendarService;
use Modules\Fleet\Application\VehicleMediaService;
use Modules\Fleet\Domain\Vehicle;
use Modules\Fleet\Domain\VehicleBrand;
use Modules\Fleet\Domain\VehicleStatusLog;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
        $qb->orderBy('v.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(v.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@fleet/vehicles/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('fleet.title'),
            'items' => $items,
            'customers' => $this->customerMap($items),
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
            'brandOptions' => $this->brandNames(),
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
        $vehicle = $this->find($id);
        if ($vehicle === null) {
            $this->abort(404, $this->translator->trans('fleet.error.not_found'));
        }
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

        return $this->page('@fleet/vehicles/show.html.twig', [
            'pageTitle' => $vehicle->label(),
            'item' => $vehicle,
            'customer' => $customer instanceof Customer ? $customer : null,
            'logs' => $logs,
            'statuses' => Vehicle::STATUSES,
            'hookSections' => $sections,
            'mediaAvailable' => $this->vehicleMedia->available(),
            'mediaItems' => $this->vehicleMedia->forVehicle($id),
        ]);
    }

    public function edit(string $id): Response
    {
        $vehicle = $this->find($id);
        if ($vehicle === null) {
            $this->abort(404, $this->translator->trans('fleet.error.not_found'));
        }

        return $this->page('@fleet/vehicles/form.html.twig', [
            'pageTitle' => $this->translator->trans('fleet.edit'),
            'item' => $vehicle,
            'customerId' => $vehicle->customerId() ?? '',
            'customerOptions' => $this->customerOptions(),
            'statuses' => Vehicle::STATUSES,
            'ownerships' => Vehicle::OWNERSHIPS,
            'brandOptions' => $this->brandNames(),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $vehicle = $this->find($id);
        if ($vehicle === null) {
            $this->abort(404, $this->translator->trans('fleet.error.not_found'));
        }
        $data = $this->validated($request);
        $other = $this->fleet->findByPlate($data['plate']);
        if ($other !== null && $other->id() !== $id) {
            $this->flash('error', $this->translator->trans('fleet.error.plate_exists'));

            return $this->redirect('admin.fleet.edit', ['id' => $id]);
        }
        $prevStatus = $vehicle->status();
        $this->apply($vehicle, $data);
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
        $vehicle = $this->find($id);
        if ($vehicle === null) {
            $this->abort(404, $this->translator->trans('fleet.error.not_found'));
        }
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
        $vehicle = $this->find($id);
        if ($vehicle === null) {
            $this->abort(404, $this->translator->trans('fleet.error.not_found'));
        }
        $label = trim((string) $request->request->get('label', 'photo')) ?: 'photo';
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->flash('error', $this->translator->trans('fleet.error.file_required'));

            return $this->redirect('admin.fleet.show', ['id' => $id]);
        }
        try {
            $this->vehicleMedia->upload($id, $file, $label);
        } catch (\Throwable $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.fleet.show', ['id' => $id]);
        }
        $this->flash('success', $this->translator->trans('fleet.flash.media_uploaded'));

        return $this->redirect('admin.fleet.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $vehicle = $this->find($id);
        if ($vehicle === null) {
            $this->abort(404, $this->translator->trans('fleet.error.not_found'));
        }
        $plate = $vehicle->plate();
        foreach ($this->em->getRepository(VehicleStatusLog::class)->findBy(['vehicleId' => $id]) as $log) {
            $this->em->remove($log);
        }
        $this->em->remove($vehicle);
        $this->em->flush();
        $this->events->emit('fleet.vehicle_deleted', ['type' => 'fleet_vehicle', 'id' => $id], ['id' => $id, 'plate' => $plate]);
        $this->flash('success', $this->translator->trans('fleet.flash.deleted', ['%plate%' => $plate]));

        return $this->redirect('admin.fleet.index');
    }

    private function find(string $id): ?Vehicle
    {
        return $this->fleet->find($id);
    }

    /** @param array{plate: string, vin: ?string, brand: ?string, model: ?string, model_year: ?int, color: ?string, fuel: ?string, mileage: ?int, status: string, ownership: string, customer_id: ?string, stk_at: ?\DateTimeImmutable, insurance_until: ?\DateTimeImmutable, vignette_until: ?\DateTimeImmutable, note: ?string} $data */
    private function apply(Vehicle $vehicle, array $data): void
    {
        $vehicle->setPlate($data['plate']);
        $vehicle->setVin($data['vin']);
        $vehicle->setBrand($data['brand']);
        $vehicle->setModel($data['model']);
        $vehicle->setModelYear($data['model_year']);
        $vehicle->setColor($data['color']);
        $vehicle->setFuel($data['fuel']);
        $vehicle->setMileage($data['mileage']);
        $vehicle->setStatus($data['status']);
        $vehicle->setOwnership($data['ownership']);
        $vehicle->setCustomerId($data['customer_id']);
        $vehicle->setStkAt($data['stk_at']);
        $vehicle->setInsuranceUntil($data['insurance_until']);
        $vehicle->setVignetteUntil($data['vignette_until']);
        $vehicle->setNote($data['note']);
    }

    /** @return array{plate: string, vin: ?string, brand: ?string, model: ?string, model_year: ?int, color: ?string, fuel: ?string, mileage: ?int, status: string, ownership: string, customer_id: ?string, stk_at: ?\DateTimeImmutable, insurance_until: ?\DateTimeImmutable, vignette_until: ?\DateTimeImmutable, note: ?string} */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'fleet.save');
        $customerId = trim((string) ($data['customer_id'] ?? ''));
        if ($customerId !== '' && class_exists(Customer::class) && !$this->em->find(Customer::class, $customerId) instanceof Customer) {
            $this->abort(422, $this->translator->trans('fleet.error.invalid_customer'));
        }
        $year = trim((string) ($data['model_year'] ?? ''));
        $mileage = trim((string) ($data['mileage'] ?? ''));
        $stk = trim((string) ($data['stk_at'] ?? ''));
        $ins = trim((string) ($data['insurance_until'] ?? ''));
        $vig = trim((string) ($data['vignette_until'] ?? ''));

        return [
            'plate' => Vehicle::normalizePlate((string) $data['plate']),
            'vin' => trim((string) ($data['vin'] ?? '')) ?: null,
            'brand' => trim((string) ($data['brand'] ?? '')) ?: null,
            'model' => trim((string) ($data['model'] ?? '')) ?: null,
            'model_year' => $year !== '' ? (int) $year : null,
            'color' => trim((string) ($data['color'] ?? '')) ?: null,
            'fuel' => trim((string) ($data['fuel'] ?? '')) ?: null,
            'mileage' => $mileage !== '' ? (int) $mileage : null,
            'status' => in_array(($data['status'] ?? 'available'), Vehicle::STATUSES, true) ? (string) $data['status'] : 'available',
            'ownership' => in_array(($data['ownership'] ?? 'owned'), Vehicle::OWNERSHIPS, true) ? (string) $data['ownership'] : 'owned',
            'customer_id' => $customerId !== '' ? $customerId : null,
            'stk_at' => $stk !== '' ? new \DateTimeImmutable($stk) : null,
            'insurance_until' => $ins !== '' ? new \DateTimeImmutable($ins) : null,
            'vignette_until' => $vig !== '' ? new \DateTimeImmutable($vig) : null,
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
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
