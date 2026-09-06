<?php

declare(strict_types=1);

namespace Modules\BikeService\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Debug\FullServiceLogger;
use Gereq\Setting\Settings;
use Modules\BikeService\Domain\Bike;
use Modules\BikeService\Domain\BikeJob;
use Modules\BikeService\Domain\BikeOrder;
use Modules\Customers\Domain\Customer;

final class BikeServiceProvider
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?Settings $settings = null,
    ) {}

    /** @return list<string> */
    public function mediaPhases(): array
    {
        $raw = $this->settings !== null
            ? (string) $this->settings->get('bikeservice.media_phases', 'before,progress,after')
            : 'before,progress,after';
        $phases = array_values(array_filter(array_map('trim', explode(',', $raw))));

        return $phases !== [] ? $phases : ['before', 'progress', 'after'];
    }

    public function getBike(string $id): ?Bike
    {
        $b = $this->em->find(Bike::class, $id);

        return $b instanceof Bike ? $b : null;
    }

    public function getOrder(string $id): ?BikeOrder
    {
        $o = $this->em->find(BikeOrder::class, $id);

        return $o instanceof BikeOrder ? $o : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createBike(array $data): Bike
    {
        $brand = trim((string) ($data['brand'] ?? ''));
        if ($brand === '') {
            throw new \InvalidArgumentException('bikeservice.error.brand_required');
        }
        $kind = (string) ($data['kind'] ?? 'bike');
        $bike = new Bike($brand, in_array($kind, Bike::KINDS, true) ? $kind : 'bike');
        $this->applyBike($bike, $data);
        $this->em->persist($bike);
        $this->em->flush();

        return $bike;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateBike(Bike $bike, array $data): Bike
    {
        if (isset($data['brand'])) {
            $brand = trim((string) $data['brand']);
            if ($brand === '') {
                throw new \InvalidArgumentException('bikeservice.error.brand_required');
            }
            $bike->setBrand($brand);
        }
        if (isset($data['kind']) && in_array((string) $data['kind'], Bike::KINDS, true)) {
            $bike->setKind((string) $data['kind']);
        }
        $this->applyBike($bike, $data);
        $this->em->flush();

        return $bike;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function createOrder(string $bikeId, ?string $customerId = null, array $meta = []): BikeOrder
    {
        $bike = $this->getBike($bikeId);
        if ($bike === null) {
            throw new \InvalidArgumentException('bikeservice.error.invalid_bike');
        }
        if ($customerId !== null && $customerId !== '' && !$this->em->find(Customer::class, $customerId) instanceof Customer) {
            throw new \InvalidArgumentException('bikeservice.error.invalid_customer');
        }
        $order = new BikeOrder($bikeId);
        $order->setCustomerId($customerId ?? $bike->customerId());
        $defaultTeam = $this->settings !== null
            ? (string) $this->settings->get('bikeservice.default_team', 'mechanics')
            : 'mechanics';
        if ($bike->kind() === 'ebike' || $bike->kind() === 'scooter') {
            $defaultTeam = 'electric';
        }
        $team = !empty($meta['team']) ? (string) $meta['team'] : $defaultTeam;
        if (in_array($team, BikeOrder::TEAMS, true)) {
            $order->setTeam($team);
        }
        $this->applyOrderMeta($order, $meta);
        $bike->setStatus('in_service');
        $this->em->persist($order);
        $this->em->flush();

        FullServiceLogger::log('bikeservice.order.created', [
            'order_id' => $order->id(),
            'bike_id' => $order->bikeId(),
            'customer_id' => $order->customerId(),
            'status' => $order->status(),
            'team' => $order->team(),
        ]);

        return $order;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function updateOrder(BikeOrder $order, array $meta): BikeOrder
    {
        if (!empty($meta['bike_id'])) {
            $bikeId = (string) $meta['bike_id'];
            if ($this->getBike($bikeId) === null) {
                throw new \InvalidArgumentException('bikeservice.error.invalid_bike');
            }
            $order->setBikeId($bikeId);
        }
        if (array_key_exists('customer_id', $meta)) {
            $cid = $meta['customer_id'] !== null ? (string) $meta['customer_id'] : null;
            if ($cid !== null && $cid !== '' && !$this->em->find(Customer::class, $cid) instanceof Customer) {
                throw new \InvalidArgumentException('bikeservice.error.invalid_customer');
            }
            $order->setCustomerId($cid);
        }
        if (!empty($meta['status']) && in_array((string) $meta['status'], BikeOrder::STATUSES, true)) {
            $prevStatus = $order->status();
            $order->setStatus((string) $meta['status']);
            if ($prevStatus !== $order->status()) {
                FullServiceLogger::log('bikeservice.order.status_changed', [
                    'order_id' => $order->id(),
                    'from' => $prevStatus,
                    'to' => $order->status(),
                    'source' => 'updateOrder',
                ]);
            }
        }
        if (!empty($meta['team']) && in_array((string) $meta['team'], BikeOrder::TEAMS, true)) {
            $order->setTeam((string) $meta['team']);
        }
        $this->applyOrderMeta($order, $meta);
        $this->em->flush();

        FullServiceLogger::log('bikeservice.order.updated', [
            'order_id' => $order->id(),
            'status' => $order->status(),
            'team' => $order->team(),
            'meta_keys' => array_keys($meta),
        ]);

        return $order;
    }

    public function finishOrder(BikeOrder $order): BikeOrder
    {
        $prevStatus = $order->status();
        $order->finish();
        $bike = $this->getBike($order->bikeId());
        $bikeStatus = $bike?->status();
        if ($bike !== null && $bike->status() === 'in_service') {
            $bike->setStatus('active');
        }
        $this->em->flush();

        FullServiceLogger::log('bikeservice.order.finished', [
            'order_id' => $order->id(),
            'status_from' => $prevStatus,
            'status_to' => $order->status(),
            'bike_id' => $order->bikeId(),
            'bike_status_from' => $bikeStatus,
            'bike_status_to' => $bike?->status(),
            'customer_id' => $order->customerId(),
        ]);

        return $order;
    }

    public function startJob(BikeOrder $order, string $type = 'work', ?string $employeeUserId = null, ?string $note = null): BikeJob
    {
        $prevStatus = $order->status();
        $job = new BikeJob($order->id(), $type);
        $job->setEmployeeUserId($employeeUserId);
        $job->setNote($note);
        if ($order->status() === 'intake') {
            $order->setStatus('in_progress');
        }
        $this->em->persist($job);
        $this->em->flush();

        FullServiceLogger::log('bikeservice.job.started', [
            'order_id' => $order->id(),
            'job_id' => $job->id(),
            'type' => $type,
            'employee_user_id' => $employeeUserId,
            'status_from' => $prevStatus,
            'status_to' => $order->status(),
        ]);

        return $job;
    }

    public function completeJob(BikeJob $job): BikeJob
    {
        $job->complete();
        $this->em->flush();

        FullServiceLogger::log('bikeservice.job.completed', [
            'order_id' => $job->orderId(),
            'job_id' => $job->id(),
            'type' => $job->type(),
            'duration_seconds' => $job->durationSeconds(),
        ]);

        return $job;
    }

    /** @return list<BikeOrder> */
    public function listForCustomer(string $customerId, int $limit = 50): array
    {
        return $this->em->getRepository(BikeOrder::class)->createQueryBuilder('o')
            ->where('o.customerId = :c')
            ->setParameter('c', $customerId)
            ->orderBy('o.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<BikeOrder> */
    public function listForBike(string $bikeId, int $limit = 50): array
    {
        return $this->em->getRepository(BikeOrder::class)->createQueryBuilder('o')
            ->where('o.bikeId = :b')
            ->setParameter('b', $bikeId)
            ->orderBy('o.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<BikeOrder> */
    public function searchOrders(string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $like = '%' . mb_strtolower($q) . '%';

        return $this->em->getRepository(BikeOrder::class)->createQueryBuilder('o')
            ->where('LOWER(o.id) LIKE :q OR LOWER(COALESCE(o.note, \'\')) LIKE :q OR LOWER(COALESCE(o.serviceInfo, \'\')) LIKE :q OR LOWER(o.trackerToken) LIKE :q')
            ->setParameter('q', $like)
            ->orderBy('o.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Bike> */
    public function searchBikes(string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $like = '%' . mb_strtolower($q) . '%';

        return $this->em->getRepository(Bike::class)->createQueryBuilder('b')
            ->where('LOWER(b.brand) LIKE :q OR LOWER(COALESCE(b.model, \'\')) LIKE :q OR LOWER(COALESCE(b.serialNumber, \'\')) LIKE :q')
            ->setParameter('q', $like)
            ->orderBy('b.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeOrder(BikeOrder $order): array
    {
        return [
            'id' => $order->id(),
            'status' => $order->status(),
            'team' => $order->team(),
            'bike_id' => $order->bikeId(),
            'customer_id' => $order->customerId(),
            'tracker_token' => $order->trackerToken(),
            'service_info' => $order->serviceInfo(),
            'payment_type' => $order->paymentType(),
            'labor_cost' => $order->laborCost(),
            'parts_cost' => $order->partsCost(),
            'intake_odometer_km' => $order->intakeOdometerKm(),
            'intake_battery_pct' => $order->intakeBatteryPct(),
            'note' => $order->note(),
            'intake_at' => $order->intakeAt()?->format('Y-m-d'),
            'handover_at' => $order->handoverAt()?->format('Y-m-d'),
            'finished_at' => $order->finishedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $order->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $order->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeBike(Bike $bike): array
    {
        return [
            'id' => $bike->id(),
            'kind' => $bike->kind(),
            'status' => $bike->status(),
            'brand' => $bike->brand(),
            'model' => $bike->model(),
            'serial_number' => $bike->serialNumber(),
            'color' => $bike->color(),
            'frame_size' => $bike->frameSize(),
            'battery_wh' => $bike->batteryWh(),
            'motor_w' => $bike->motorW(),
            'odometer_km' => $bike->odometerKm(),
            'customer_id' => $bike->customerId(),
            'bike_index_id' => $bike->bikeIndexId(),
            'note' => $bike->note(),
            'label' => $bike->label(),
            'created_at' => $bike->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $bike->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @param array<string, mixed> $data */
    private function applyBike(Bike $bike, array $data): void
    {
        if (array_key_exists('status', $data) && in_array((string) $data['status'], Bike::STATUSES, true)) {
            $bike->setStatus((string) $data['status']);
        }
        if (array_key_exists('model', $data)) {
            $bike->setModel($data['model'] !== null ? (string) $data['model'] : null);
        }
        if (array_key_exists('serial_number', $data)) {
            $bike->setSerialNumber($data['serial_number'] !== null ? (string) $data['serial_number'] : null);
        }
        if (array_key_exists('color', $data)) {
            $bike->setColor($data['color'] !== null ? (string) $data['color'] : null);
        }
        if (array_key_exists('frame_size', $data)) {
            $bike->setFrameSize($data['frame_size'] !== null ? (string) $data['frame_size'] : null);
        }
        if (array_key_exists('battery_wh', $data)) {
            $bike->setBatteryWh($data['battery_wh'] !== null && $data['battery_wh'] !== '' ? (int) $data['battery_wh'] : null);
        }
        if (array_key_exists('motor_w', $data)) {
            $bike->setMotorW($data['motor_w'] !== null && $data['motor_w'] !== '' ? (int) $data['motor_w'] : null);
        }
        if (array_key_exists('odometer_km', $data)) {
            $bike->setOdometerKm($data['odometer_km'] !== null && $data['odometer_km'] !== '' ? (int) $data['odometer_km'] : null);
        }
        if (array_key_exists('customer_id', $data)) {
            $cid = $data['customer_id'] !== null ? trim((string) $data['customer_id']) : null;
            if ($cid !== null && $cid !== '' && !$this->em->find(Customer::class, $cid) instanceof Customer) {
                throw new \InvalidArgumentException('bikeservice.error.invalid_customer');
            }
            $bike->setCustomerId($cid !== '' ? $cid : null);
        }
        if (array_key_exists('bike_index_id', $data)) {
            $bike->setBikeIndexId($data['bike_index_id'] !== null && $data['bike_index_id'] !== '' ? (int) $data['bike_index_id'] : null);
        }
        if (array_key_exists('note', $data)) {
            $bike->setNote($data['note'] !== null ? (string) $data['note'] : null);
        }
    }

    /** @param array<string, mixed> $meta */
    private function applyOrderMeta(BikeOrder $order, array $meta): void
    {
        if (array_key_exists('note', $meta)) {
            $order->setNote($meta['note'] !== null ? (string) $meta['note'] : null);
        }
        if (array_key_exists('service_info', $meta)) {
            $order->setServiceInfo($meta['service_info'] !== null ? (string) $meta['service_info'] : null);
        }
        if (array_key_exists('payment_type', $meta)) {
            $order->setPaymentType($meta['payment_type'] !== null ? (string) $meta['payment_type'] : null);
        }
        if (array_key_exists('labor_cost', $meta)) {
            $order->setLaborCost($meta['labor_cost']);
        }
        if (array_key_exists('parts_cost', $meta)) {
            $order->setPartsCost($meta['parts_cost']);
        }
        if (array_key_exists('intake_odometer_km', $meta)) {
            $order->setIntakeOdometerKm($meta['intake_odometer_km'] !== null && $meta['intake_odometer_km'] !== '' ? (int) $meta['intake_odometer_km'] : null);
        }
        if (array_key_exists('intake_battery_pct', $meta)) {
            $order->setIntakeBatteryPct($meta['intake_battery_pct'] !== null && $meta['intake_battery_pct'] !== '' ? (int) $meta['intake_battery_pct'] : null);
        }
        if (!empty($meta['intake_at']) && $meta['intake_at'] instanceof \DateTimeImmutable) {
            $order->setIntakeAt($meta['intake_at']);
        } elseif (array_key_exists('intake_at', $meta) && $meta['intake_at'] === null) {
            $order->setIntakeAt(null);
        }
        if (!empty($meta['handover_at']) && $meta['handover_at'] instanceof \DateTimeImmutable) {
            $order->setHandoverAt($meta['handover_at']);
        } elseif (array_key_exists('handover_at', $meta) && $meta['handover_at'] === null) {
            $order->setHandoverAt(null);
        }
    }
}
