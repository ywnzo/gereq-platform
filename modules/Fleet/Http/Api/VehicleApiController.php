<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Customers\Domain\Customer;
use Modules\Fleet\Application\FleetProvider;
use Modules\Fleet\Domain\Vehicle;
use Modules\Fleet\Domain\VehicleStatusLog;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class VehicleApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly FleetProvider $fleet,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest($request, sortable: ['plate', 'status', 'createdAt', 'updatedAt', 'brand'], filterable: ['status', 'ownership', 'customer_id']);
        $qb = $this->em->getRepository(Vehicle::class)->createQueryBuilder('v');
        if ($query->search !== '') {
            $q = '%' . mb_strtolower($query->search) . '%';
            $qb->andWhere('LOWER(v.plate) LIKE :q OR LOWER(v.vin) LIKE :q OR LOWER(v.brand) LIKE :q OR LOWER(v.model) LIKE :q')
                ->setParameter('q', $q);
        }
        if (!empty($query->filters['status'])) {
            $qb->andWhere('v.status = :s')->setParameter('s', $query->filters['status']);
        }
        if (!empty($query->filters['ownership'])) {
            $qb->andWhere('v.ownership = :o')->setParameter('o', $query->filters['ownership']);
        }
        if (!empty($query->filters['customer_id'])) {
            $qb->andWhere('v.customerId = :c')->setParameter('c', $query->filters['customer_id']);
        }
        $sort = $query->sort ?? 'updatedAt';
        $qb->orderBy('v.' . $sort, strtoupper($query->direction));
        $total = (int) (clone $qb)->select('COUNT(v.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(array_map(fn (Vehicle $v) => $this->fleet->serialize($v), $items), $query->meta($total));
    }

    public function store(Request $request): JsonResponse
    {
        $data = gereq()->make(ValidationRegistry::class)->validate('fleet.save', $this->body($request));
        $plate = Vehicle::normalizePlate((string) $data['plate']);
        if ($this->fleet->findByPlate($plate) !== null) {
            return ApiResponse::error('validation_error', 'Plate already exists', [], 422);
        }
        $vehicle = new Vehicle($plate, in_array(($data['ownership'] ?? 'owned'), Vehicle::OWNERSHIPS, true) ? (string) $data['ownership'] : 'owned');
        $this->fill($vehicle, $data);
        $this->em->persist($vehicle);
        $this->em->persist(new VehicleStatusLog($vehicle->id(), null, $vehicle->status(), 'api_created'));
        $this->em->flush();
        $payload = $this->fleet->serialize($vehicle);
        $this->events->emit('fleet.vehicle_created', ['type' => 'fleet_vehicle', 'id' => $vehicle->id()], $payload);

        return ApiResponse::data($payload, 201);
    }

    public function show(string $id): JsonResponse
    {
        $v = $this->fleet->find($id);
        if ($v === null) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->fleet->serialize($v));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $v = $this->fleet->find($id);
        if ($v === null) {
            return ApiResponse::notFound();
        }
        $data = gereq()->make(ValidationRegistry::class)->validate('fleet.save', $this->body($request));
        $plate = Vehicle::normalizePlate((string) $data['plate']);
        $other = $this->fleet->findByPlate($plate);
        if ($other !== null && $other->id() !== $id) {
            return ApiResponse::error('validation_error', 'Plate already exists', [], 422);
        }
        $prev = $v->status();
        $this->fill($v, $data);
        if ($prev !== $v->status()) {
            $this->em->persist(new VehicleStatusLog($id, $prev, $v->status(), 'api_update'));
        }
        $this->em->flush();
        $payload = $this->fleet->serialize($v);
        $this->events->emit('fleet.vehicle_updated', ['type' => 'fleet_vehicle', 'id' => $id], $payload);
        if ($prev !== $v->status()) {
            $this->events->emit('fleet.vehicle_status_changed', ['type' => 'fleet_vehicle', 'id' => $id], $payload);
        }

        return ApiResponse::data($payload);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $v = $this->fleet->find($id);
        if ($v === null) {
            return ApiResponse::notFound();
        }
        $body = $this->body($request);
        $status = (string) ($body['status'] ?? '');
        if (!in_array($status, Vehicle::STATUSES, true)) {
            return ApiResponse::error('validation_error', 'Invalid status', [], 422);
        }
        $this->fleet->setStatus($id, $status, isset($body['reason']) ? (string) $body['reason'] : null);
        $payload = $this->fleet->serialize($this->fleet->find($id) ?? $v);
        $this->events->emit('fleet.vehicle_status_changed', ['type' => 'fleet_vehicle', 'id' => $id], $payload);

        return ApiResponse::data($payload);
    }

    public function destroy(string $id): JsonResponse
    {
        $v = $this->fleet->find($id);
        if ($v === null) {
            return ApiResponse::notFound();
        }
        foreach ($this->em->getRepository(VehicleStatusLog::class)->findBy(['vehicleId' => $id]) as $log) {
            $this->em->remove($log);
        }
        $this->em->remove($v);
        $this->em->flush();
        $this->events->emit('fleet.vehicle_deleted', ['type' => 'fleet_vehicle', 'id' => $id], ['id' => $id]);

        return ApiResponse::data(['id' => $id, 'deleted' => true]);
    }

    /** @param array<string, mixed> $data */
    private function fill(Vehicle $v, array $data): void
    {
        $v->setPlate(Vehicle::normalizePlate((string) $data['plate']));
        $v->setVin(isset($data['vin']) ? (string) $data['vin'] : null);
        $v->setBrand(isset($data['brand']) ? (string) $data['brand'] : null);
        $v->setModel(isset($data['model']) ? (string) $data['model'] : null);
        if (isset($data['model_year']) && $data['model_year'] !== '' && $data['model_year'] !== null) {
            $v->setModelYear((int) $data['model_year']);
        }
        $v->setColor(isset($data['color']) ? (string) $data['color'] : null);
        $v->setFuel(isset($data['fuel']) ? (string) $data['fuel'] : null);
        if (isset($data['mileage']) && $data['mileage'] !== '' && $data['mileage'] !== null) {
            $v->setMileage((int) $data['mileage']);
        }
        if (isset($data['status']) && in_array($data['status'], Vehicle::STATUSES, true)) {
            $v->setStatus((string) $data['status']);
        }
        if (isset($data['ownership']) && in_array($data['ownership'], Vehicle::OWNERSHIPS, true)) {
            $v->setOwnership((string) $data['ownership']);
        }
        $cid = trim((string) ($data['customer_id'] ?? ''));
        if ($cid !== '' && class_exists(Customer::class) && !$this->em->find(Customer::class, $cid) instanceof Customer) {
            throw new \InvalidArgumentException('fleet.error.invalid_customer');
        }
        $v->setCustomerId($cid !== '' ? $cid : null);
        $stk = trim((string) ($data['stk_at'] ?? ''));
        $ins = trim((string) ($data['insurance_until'] ?? ''));
        $v->setStkAt($stk !== '' ? new \DateTimeImmutable($stk) : null);
        $v->setInsuranceUntil($ins !== '' ? new \DateTimeImmutable($ins) : null);
        $v->setNote(isset($data['note']) ? (string) $data['note'] : null);
    }

    /** @return array<string, mixed> */
    private function body(Request $r): array
    {
        $d = $r->request->all();
        $raw = $r->getContent();
        if (is_string($raw) && $raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) {
                return array_merge($d, $j);
            }
        }

        return $d;
    }
}
