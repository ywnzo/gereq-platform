<?php

declare(strict_types=1);

namespace Modules\Fleet\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Fleet\Domain\Vehicle;
use Modules\Fleet\Domain\VehicleBrand;
use Modules\Fleet\Domain\VehicleModel;
use Modules\Fleet\Domain\VehicleStatusLog;

final class FleetProvider
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function find(string $id): ?Vehicle
    {
        $v = $this->em->find(Vehicle::class, $id);

        return $v instanceof Vehicle ? $v : null;
    }

    public function findByPlate(string $plate): ?Vehicle
    {
        $normalized = Vehicle::normalizePlate($plate);
        if ($normalized === '') {
            return null;
        }
        $v = $this->em->getRepository(Vehicle::class)->findOneBy(['plate' => $normalized]);

        return $v instanceof Vehicle ? $v : null;
    }

    /**
     * @param array{status?: string, ownership?: string, customer_id?: string, q?: string} $filters
     * @return list<Vehicle>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $qb = $this->em->getRepository(Vehicle::class)->createQueryBuilder('v')->orderBy('v.updatedAt', 'DESC');
        if (!empty($filters['status']) && in_array($filters['status'], Vehicle::STATUSES, true)) {
            $qb->andWhere('v.status = :status')->setParameter('status', $filters['status']);
        }
        if (!empty($filters['ownership']) && in_array($filters['ownership'], Vehicle::OWNERSHIPS, true)) {
            $qb->andWhere('v.ownership = :ownership')->setParameter('ownership', $filters['ownership']);
        }
        if (!empty($filters['customer_id'])) {
            $qb->andWhere('v.customerId = :cid')->setParameter('cid', $filters['customer_id']);
        }
        if (!empty($filters['q'])) {
            $q = '%' . mb_strtolower((string) $filters['q']) . '%';
            $qb->andWhere('LOWER(v.plate) LIKE :q OR LOWER(v.vin) LIKE :q OR LOWER(v.brand) LIKE :q OR LOWER(v.model) LIKE :q OR LOWER(v.orvId) LIKE :q')
                ->setParameter('q', $q);
        }

        return $qb->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();
    }

    /** @return list<Vehicle> */
    public function forCustomer(string $customerId, int $limit = 20): array
    {
        return $this->em->getRepository(Vehicle::class)->findBy(
            ['customerId' => $customerId],
            ['updatedAt' => 'DESC'],
            $limit,
        );
    }

    public function setStatus(string $id, string $status, ?string $reason = null, ?string $userId = null): Vehicle
    {
        $vehicle = $this->find($id);
        if ($vehicle === null) {
            throw new \InvalidArgumentException('fleet.error.not_found');
        }
        $from = $vehicle->status();
        if ($from === $status) {
            return $vehicle;
        }
        $vehicle->setStatus($status);
        $this->em->persist(new VehicleStatusLog($id, $from, $status, $reason, $userId));
        $this->em->flush();

        return $vehicle;
    }

    /**
     * @param array{plate: string, vin?: ?string, brand?: ?string, model?: ?string, model_year?: ?int, color?: ?string, fuel?: ?string, mileage?: ?int, orv_id?: ?string, gearbox?: ?string, motorization?: ?string, fuel_level?: ?string} $plateData
     */
    public function ensureForCustomer(string $customerId, array $plateData): Vehicle
    {
        $plate = Vehicle::normalizePlate((string) ($plateData['plate'] ?? ''));
        if ($plate === '') {
            throw new \InvalidArgumentException('fleet.error.plate_required');
        }
        $existing = $this->findByPlate($plate);
        if ($existing !== null) {
            if ($existing->customerId() === null) {
                $existing->setCustomerId($customerId);
            }
            if ($existing->ownership() === 'owned' && $existing->customerId() === $customerId) {
                $existing->setOwnership('external');
            }
            $this->applyPlateData($existing, $plateData);
            $this->ensureBrandModel($existing);
            $this->em->flush();

            return $existing;
        }
        $vehicle = new Vehicle($plate, 'external');
        $vehicle->setCustomerId($customerId);
        $this->applyPlateData($vehicle, $plateData);
        $this->ensureBrandModel($vehicle);
        $this->em->persist($vehicle);
        $this->em->persist(new VehicleStatusLog($vehicle->id(), null, $vehicle->status(), 'ensure_for_customer'));
        $this->em->flush();

        return $vehicle;
    }

    /**
     * Jako RPS setCarBrandAndModel — doplní číselník značek/modelů a ID na vozidle.
     *
     * @return array{brand_id: ?string, model_id: ?string}
     */
    public function ensureBrandModel(Vehicle $vehicle): array
    {
        $brandName = $vehicle->brand();
        $modelName = $vehicle->model();
        if ($brandName === null || $brandName === '') {
            return ['brand_id' => null, 'model_id' => null];
        }

        $brand = $this->em->getRepository(VehicleBrand::class)->findOneBy(['name' => $brandName]);
        if (!$brand instanceof VehicleBrand) {
            $brand = new VehicleBrand($brandName);
            $this->em->persist($brand);
            $this->em->flush();
        }
        $vehicle->setBrandId($brand->id());

        $modelId = null;
        if ($modelName !== null && $modelName !== '') {
            $model = $this->em->getRepository(VehicleModel::class)->findOneBy([
                'brandId' => $brand->id(),
                'name' => $modelName,
            ]);
            if (!$model instanceof VehicleModel) {
                $model = new VehicleModel($brand->id(), $modelName);
                $this->em->persist($model);
                $this->em->flush();
            }
            $modelId = $model->id();
            $vehicle->setModelId($modelId);
        }

        return ['brand_id' => $brand->id(), 'model_id' => $modelId];
    }

    /** @return array<string, mixed> */
    public function serialize(Vehicle $v): array
    {
        return [
            'id' => $v->id(),
            'plate' => $v->plate(),
            'vin' => $v->vin(),
            'orv_id' => $v->orvId(),
            'brand' => $v->brand(),
            'model' => $v->model(),
            'brand_id' => $v->brandId(),
            'model_id' => $v->modelId(),
            'model_year' => $v->modelYear(),
            'color' => $v->color(),
            'fuel' => $v->fuel(),
            'gearbox' => $v->gearbox(),
            'motorization' => $v->motorization(),
            'mileage' => $v->mileage(),
            'fuel_level' => $v->fuelLevel(),
            'condition' => $v->conditionJson(),
            'status' => $v->status(),
            'ownership' => $v->ownership(),
            'customer_id' => $v->customerId(),
            'branch_id' => $v->branchId(),
            'stk_at' => $v->stkAt()?->format('Y-m-d'),
            'insurance_until' => $v->insuranceUntil()?->format('Y-m-d'),
            'vignette_until' => $v->vignetteUntil()?->format('Y-m-d'),
            'tires_at' => $v->tiresAt()?->format('Y-m-d'),
            'warranty_until' => $v->warrantyUntil()?->format('Y-m-d'),
            'note' => $v->note(),
            'tech' => $v->techJson(),
            'label' => $v->label(),
            'created_at' => $v->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $v->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @param array<string, mixed> $data */
    private function applyPlateData(Vehicle $vehicle, array $data): void
    {
        if (array_key_exists('vin', $data)) {
            $vehicle->setVin($data['vin'] !== null ? (string) $data['vin'] : null);
        }
        if (array_key_exists('orv_id', $data)) {
            $vehicle->setOrvId($data['orv_id'] !== null ? (string) $data['orv_id'] : null);
        }
        if (array_key_exists('brand', $data)) {
            $vehicle->setBrand($data['brand'] !== null ? (string) $data['brand'] : null);
        }
        if (array_key_exists('model', $data)) {
            $vehicle->setModel($data['model'] !== null ? (string) $data['model'] : null);
        }
        if (array_key_exists('model_year', $data) && $data['model_year'] !== null && $data['model_year'] !== '') {
            $vehicle->setModelYear((int) $data['model_year']);
        }
        if (array_key_exists('color', $data)) {
            $vehicle->setColor($data['color'] !== null ? (string) $data['color'] : null);
        }
        if (array_key_exists('fuel', $data)) {
            $vehicle->setFuel($data['fuel'] !== null ? (string) $data['fuel'] : null);
        }
        if (array_key_exists('gearbox', $data)) {
            $g = $data['gearbox'] !== null && $data['gearbox'] !== '' ? (string) $data['gearbox'] : null;
            if ($g === null || in_array($g, Vehicle::GEARBOXES, true)) {
                $vehicle->setGearbox($g);
            }
        }
        if (array_key_exists('motorization', $data)) {
            $vehicle->setMotorization($data['motorization'] !== null ? (string) $data['motorization'] : null);
        }
        if (array_key_exists('mileage', $data) && $data['mileage'] !== null && $data['mileage'] !== '') {
            $vehicle->setMileage((int) $data['mileage']);
        }
        if (array_key_exists('fuel_level', $data)) {
            $vehicle->setFuelLevel($data['fuel_level'] !== null ? (string) $data['fuel_level'] : null);
        }
    }
}
