<?php

declare(strict_types=1);

namespace Modules\CarService\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Setting\Settings;
use Modules\CarService\Domain\ServiceJob;
use Modules\CarService\Domain\ServiceOrder;
use Modules\CarService\Domain\ServiceOrderLink;
use Modules\CarService\Domain\ServiceTeamSwitch;
use Modules\Fleet\Application\FleetProvider;
use Modules\Fleet\Domain\Vehicle;

final class CarServiceProvider
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?Settings $settings = null,
        private readonly ?FleetProvider $fleet = null,
    ) {}

    public function getOrder(string $id): ?ServiceOrder
    {
        $o = $this->em->find(ServiceOrder::class, $id);

        return $o instanceof ServiceOrder ? $o : null;
    }

    /**
     * @param array{
     *   type?: string,
     *   customer_id?: ?string,
     *   branch_id?: ?string,
     *   team?: string,
     *   note?: ?string,
     *   service_info?: ?string,
     *   payment_type?: ?string,
     *   internal_cost?: null|float|string,
     *   external_cost?: null|float|string,
     *   intake_mileage?: ?int,
     *   intake_fuel_level?: ?string,
     *   link_type?: ?string,
     *   link_id?: ?string,
     *   intake_at?: ?\DateTimeImmutable,
     *   handover_at?: ?\DateTimeImmutable,
     *   sync_fleet?: bool
     * } $meta
     */
    public function createOrder(string $vehicleId, ?string $customerId = null, array $meta = []): ServiceOrder
    {
        if (!$this->em->find(Vehicle::class, $vehicleId) instanceof Vehicle) {
            throw new \InvalidArgumentException('carservice.error.invalid_vehicle');
        }
        $type = (string) ($meta['type'] ?? ($customerId ? 'external_customer' : 'internal_fleet'));
        $order = new ServiceOrder($vehicleId, $type);
        $order->setCustomerId($customerId ?? ($meta['customer_id'] ?? null));
        $order->setBranchId($meta['branch_id'] ?? null);
        $defaultTeam = $this->settings !== null ? (string) $this->settings->get('carservice.default_team', 'body_shop') : 'body_shop';
        $team = !empty($meta['team']) ? (string) $meta['team'] : $defaultTeam;
        if (in_array($team, ServiceOrder::TEAMS, true)) {
            $order->setTeam($team);
        }
        if (array_key_exists('note', $meta)) {
            $order->setNote($meta['note'] !== null ? (string) $meta['note'] : null);
        }
        if (array_key_exists('service_info', $meta)) {
            $order->setServiceInfo($meta['service_info'] !== null ? (string) $meta['service_info'] : null);
        }
        if (array_key_exists('payment_type', $meta)) {
            $order->setPaymentType($meta['payment_type'] !== null ? (string) $meta['payment_type'] : null);
        }
        if (array_key_exists('internal_cost', $meta)) {
            $order->setInternalCost($meta['internal_cost']);
        }
        if (array_key_exists('external_cost', $meta)) {
            $order->setExternalCost($meta['external_cost']);
        }
        if (array_key_exists('intake_mileage', $meta)) {
            $order->setIntakeMileage($meta['intake_mileage'] !== null ? (int) $meta['intake_mileage'] : null);
        }
        if (array_key_exists('intake_fuel_level', $meta)) {
            $order->setIntakeFuelLevel($meta['intake_fuel_level'] !== null ? (string) $meta['intake_fuel_level'] : null);
        }
        if (!empty($meta['intake_at']) && $meta['intake_at'] instanceof \DateTimeImmutable) {
            $order->setIntakeAt($meta['intake_at']);
        }
        if (!empty($meta['handover_at']) && $meta['handover_at'] instanceof \DateTimeImmutable) {
            $order->setHandoverAt($meta['handover_at']);
        }
        $this->em->persist($order);
        $this->em->persist(new ServiceTeamSwitch($order->id(), null, $order->team()));
        if (!empty($meta['link_type']) && !empty($meta['link_id'])) {
            $this->em->persist(new ServiceOrderLink($order->id(), (string) $meta['link_type'], (string) $meta['link_id']));
            if ($order->type() !== 'linked') {
                $order->setType('linked');
            }
        }
        $this->em->flush();

        if (($meta['sync_fleet'] ?? true) === true) {
            $this->syncFleetStatus($vehicleId, 'in_service', 'carservice_created');
            $this->applyIntakeSnapshotToVehicle($order);
        }

        return $order;
    }

    /** @return list<ServiceOrder> */
    public function listForVehicle(string $vehicleId, int $limit = 50): array
    {
        return $this->em->getRepository(ServiceOrder::class)->findBy(
            ['vehicleId' => $vehicleId],
            ['updatedAt' => 'DESC'],
            $limit,
        );
    }

    /** @return list<ServiceOrder> */
    public function listForCustomer(string $customerId, int $limit = 20): array
    {
        return $this->em->getRepository(ServiceOrder::class)->findBy(
            ['customerId' => $customerId],
            ['updatedAt' => 'DESC'],
            $limit,
        );
    }

    /**
     * Hledání zakázek pro admin search (id, poznámka, service info, SPZ vozidla).
     *
     * @return list<ServiceOrder>
     */
    public function searchOrders(string $query, int $limit = 8): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $vehicleIds = [];
        if ($this->fleet !== null) {
            foreach ($this->fleet->list(['q' => $query], min(20, $limit * 2)) as $vehicle) {
                $vehicleIds[] = $vehicle->id();
            }
        }

        $qb = $this->em->getRepository(ServiceOrder::class)->createQueryBuilder('o')
            ->orderBy('o.updatedAt', 'DESC')
            ->setMaxResults($limit);
        $like = '%' . mb_strtolower($query) . '%';
        if ($vehicleIds !== []) {
            $qb->andWhere(
                'LOWER(o.id) LIKE :q OR LOWER(COALESCE(o.note, \'\')) LIKE :q OR LOWER(COALESCE(o.serviceInfo, \'\')) LIKE :q OR o.vehicleId IN (:vids)'
            )->setParameter('q', $like)->setParameter('vids', $vehicleIds);
        } else {
            $qb->andWhere(
                'LOWER(o.id) LIKE :q OR LOWER(COALESCE(o.note, \'\')) LIKE :q OR LOWER(COALESCE(o.serviceInfo, \'\')) LIKE :q OR LOWER(o.vehicleId) LIKE :q'
            )->setParameter('q', $like);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @param array{status?: string, team?: string, vehicle_id?: string, customer_id?: string, open_only?: bool} $filters
     * @return list<ServiceOrder>
     */
    public function listOpen(array $filters = [], int $limit = 100): array
    {
        $qb = $this->em->getRepository(ServiceOrder::class)->createQueryBuilder('o')->orderBy('o.updatedAt', 'DESC');
        if (!empty($filters['open_only']) || !isset($filters['status'])) {
            $qb->andWhere('o.status NOT IN (:closed)')->setParameter('closed', ['done', 'cancelled']);
        } elseif (in_array($filters['status'], ServiceOrder::STATUSES, true)) {
            $qb->andWhere('o.status = :status')->setParameter('status', $filters['status']);
        }
        if (!empty($filters['team']) && in_array($filters['team'], ServiceOrder::TEAMS, true)) {
            $qb->andWhere('o.team = :team')->setParameter('team', $filters['team']);
        }
        if (!empty($filters['vehicle_id'])) {
            $qb->andWhere('o.vehicleId = :vid')->setParameter('vid', $filters['vehicle_id']);
        }
        if (!empty($filters['customer_id'])) {
            $qb->andWhere('o.customerId = :cid')->setParameter('cid', $filters['customer_id']);
        }

        return $qb->setMaxResults($limit)->getQuery()->getResult();
    }

    /** Fronta týmu pro tracker (RPS getOpenServicesForTeam). */
    /** @return list<ServiceOrder> */
    public function teamQueue(string $team, ?string $excludeOrderId = null, int $limit = 20): array
    {
        if (!in_array($team, ServiceOrder::TEAMS, true)) {
            return [];
        }
        $qb = $this->em->getRepository(ServiceOrder::class)->createQueryBuilder('o')
            ->andWhere('o.team = :team')
            ->andWhere('o.status NOT IN (:closed)')
            ->setParameter('team', $team)
            ->setParameter('closed', ['done', 'cancelled'])
            ->orderBy('o.updatedAt', 'ASC')
            ->setMaxResults($limit);
        if ($excludeOrderId !== null && $excludeOrderId !== '') {
            $qb->andWhere('o.id != :ex')->setParameter('ex', $excludeOrderId);
        }

        return $qb->getQuery()->getResult();
    }

    public function switchTeam(string $orderId, string $team, ?string $userId = null, ?string $note = null): ServiceOrder
    {
        $order = $this->requireOrder($orderId);
        if (!$order->isOpen()) {
            throw new \InvalidArgumentException('carservice.error.order_closed');
        }
        $from = $order->team();
        if ($from === $team) {
            return $order;
        }
        $order->setTeam($team);
        $this->em->persist(new ServiceTeamSwitch($orderId, $from, $team, $userId, $note));
        $this->em->flush();

        return $order;
    }

    public function addJob(string $orderId, string $type = 'work', ?string $employeeUserId = null, ?string $note = null): ServiceJob
    {
        $order = $this->requireOrder($orderId);
        if (!$order->isOpen()) {
            throw new \InvalidArgumentException('carservice.error.order_closed');
        }
        // RPS: auto-stop běžící job zaměstnance na jiné zakázce
        if ($employeeUserId !== null && $employeeUserId !== '') {
            $this->stopEmployeeRunningJobs($employeeUserId, $orderId);
        }
        $running = $this->em->getRepository(ServiceJob::class)->findOneBy(['orderId' => $orderId, 'endedAt' => null]);
        if ($running instanceof ServiceJob) {
            throw new \InvalidArgumentException('carservice.error.job_running');
        }
        $job = new ServiceJob($orderId, $type, $employeeUserId);
        $job->setNote($note);
        if ($order->status() === 'intake') {
            $order->setStatus('in_progress');
        }
        $this->em->persist($job);
        $this->em->flush();

        return $job;
    }

    public function completeJob(string $jobId): ServiceJob
    {
        $job = $this->em->find(ServiceJob::class, $jobId);
        if (!$job instanceof ServiceJob) {
            throw new \InvalidArgumentException('carservice.error.job_not_found');
        }
        // RPS hard cap: clamp délky na soft limit hodin
        $maxSeconds = (int) round($this->jobSoftLimitHours() * 3600);
        $job->complete(null, $maxSeconds > 0 ? $maxSeconds : null);
        $this->em->flush();

        return $job;
    }

    public function jobExceededSoftLimit(ServiceJob $job): bool
    {
        $limitHours = $this->jobSoftLimitHours();
        $seconds = $job->durationSeconds() ?? 0;

        return $limitHours > 0 && $seconds >= $limitHours * 3600 - 1;
    }

    /** @return list<string> */
    public function mediaPhases(): array
    {
        $default = ['before', 'progress', 'paint', 'after'];
        if ($this->settings === null) {
            return $default;
        }
        $raw = $this->settings->get('carservice.media_phases', $default);
        if (is_string($raw)) {
            $parts = array_values(array_filter(array_map('trim', explode(',', $raw))));

            return $parts !== [] ? $parts : $default;
        }
        if (is_array($raw)) {
            $parts = array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $raw)));

            return $parts !== [] ? $parts : $default;
        }

        return $default;
    }

    /**
     * @param array{
     *   payment_type?: ?string,
     *   internal_cost?: null|float|string,
     *   external_cost?: null|float|string,
     *   service_info?: ?string,
     *   intake_mileage?: ?int,
     *   intake_fuel_level?: ?string,
     *   note?: ?string
     * } $finishMeta
     */
    public function finishOrder(string $orderId, array $finishMeta = []): ServiceOrder
    {
        $order = $this->requireOrder($orderId);
        if (!$order->isOpen()) {
            throw new \InvalidArgumentException('carservice.error.order_closed');
        }
        foreach ($this->em->getRepository(ServiceJob::class)->findBy(['orderId' => $orderId, 'endedAt' => null]) as $job) {
            if ($job instanceof ServiceJob) {
                $maxSeconds = (int) round($this->jobSoftLimitHours() * 3600);
                $job->complete(null, $maxSeconds > 0 ? $maxSeconds : null);
            }
        }
        $this->applyFinishMeta($order, $finishMeta);
        $order->setStatus('done');
        $this->em->flush();
        $this->syncFleetStatus($order->vehicleId(), 'available', 'carservice_finished');

        return $order;
    }

    public function cancelOrder(string $orderId): ServiceOrder
    {
        $order = $this->requireOrder($orderId);
        if (!$order->isOpen()) {
            throw new \InvalidArgumentException('carservice.error.order_closed');
        }
        foreach ($this->em->getRepository(ServiceJob::class)->findBy(['orderId' => $orderId, 'endedAt' => null]) as $job) {
            if ($job instanceof ServiceJob) {
                $job->complete();
            }
        }
        $order->setStatus('cancelled');
        $this->em->flush();
        $this->syncFleetStatus($order->vehicleId(), 'available', 'carservice_cancelled');

        return $order;
    }

    public function resolveByTrackerToken(string $token): ?ServiceOrder
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        // Deep-link URL může obsahovat celou cestu s ?token=
        if (str_contains($token, 'token=')) {
            parse_str((string) parse_url($token, PHP_URL_QUERY), $q);
            $token = (string) ($q['token'] ?? $token);
        }
        $o = $this->em->getRepository(ServiceOrder::class)->findOneBy(['trackerToken' => $token]);

        return $o instanceof ServiceOrder ? $o : null;
    }

    public function resolveByPlate(string $plate): ?ServiceOrder
    {
        $vehicle = $this->em->getRepository(Vehicle::class)->findOneBy(['plate' => Vehicle::normalizePlate($plate)]);
        if (!$vehicle instanceof Vehicle) {
            return null;
        }
        $orders = $this->listOpen(['vehicle_id' => $vehicle->id(), 'open_only' => true], 1);

        return $orders[0] ?? null;
    }

    /** @return list<ServiceJob> */
    public function jobsForOrder(string $orderId): array
    {
        return $this->em->getRepository(ServiceJob::class)->findBy(['orderId' => $orderId], ['startedAt' => 'DESC']);
    }

    public function totalJobSeconds(string $orderId): int
    {
        $total = 0;
        foreach ($this->jobsForOrder($orderId) as $job) {
            $total += $job->durationSeconds() ?? 0;
        }

        return $total;
    }

    public function jobSoftLimitHours(): float
    {
        if ($this->settings === null) {
            return 8.0;
        }
        $raw = $this->settings->get('carservice.job_soft_limit_hours', 8);

        return is_numeric($raw) ? (float) $raw : 8.0;
    }

    /** @return array<string, mixed> */
    public function serialize(ServiceOrder $o): array
    {
        return [
            'id' => $o->id(),
            'type' => $o->type(),
            'status' => $o->status(),
            'team' => $o->team(),
            'vehicle_id' => $o->vehicleId(),
            'customer_id' => $o->customerId(),
            'branch_id' => $o->branchId(),
            'tracker_token' => $o->trackerToken(),
            'service_info' => $o->serviceInfo(),
            'payment_type' => $o->paymentType(),
            'internal_cost' => $o->internalCost(),
            'external_cost' => $o->externalCost(),
            'intake_mileage' => $o->intakeMileage(),
            'intake_fuel_level' => $o->intakeFuelLevel(),
            'note' => $o->note(),
            'intake_at' => $o->intakeAt()?->format('Y-m-d'),
            'handover_at' => $o->handoverAt()?->format('Y-m-d'),
            'finished_at' => $o->finishedAt()?->format(\DateTimeInterface::ATOM),
            'job_seconds' => $this->totalJobSeconds($o->id()),
            'created_at' => $o->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $o->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function serializeJob(ServiceJob $j): array
    {
        return [
            'id' => $j->id(),
            'order_id' => $j->orderId(),
            'type' => $j->type(),
            'note' => $j->note(),
            'employee_user_id' => $j->employeeUserId(),
            'started_at' => $j->startedAt()->format(\DateTimeInterface::ATOM),
            'ended_at' => $j->endedAt()?->format(\DateTimeInterface::ATOM),
            'duration_seconds' => $j->durationSeconds(),
            'running' => $j->isRunning(),
        ];
    }

    private function stopEmployeeRunningJobs(string $employeeUserId, string $exceptOrderId): void
    {
        $running = $this->em->getRepository(ServiceJob::class)->findBy([
            'employeeUserId' => $employeeUserId,
            'endedAt' => null,
        ]);
        $maxSeconds = (int) round($this->jobSoftLimitHours() * 3600);
        foreach ($running as $job) {
            if (!$job instanceof ServiceJob || $job->orderId() === $exceptOrderId) {
                continue;
            }
            $job->complete(null, $maxSeconds > 0 ? $maxSeconds : null);
        }
    }

    private function syncFleetStatus(string $vehicleId, string $status, string $reason): void
    {
        if ($this->fleet === null) {
            return;
        }
        $vehicle = $this->fleet->find($vehicleId);
        if ($vehicle === null) {
            return;
        }
        // Nepřepisovat rented / out_of_service při finish
        if ($status === 'available' && !in_array($vehicle->status(), ['in_service', 'repair_wait'], true)) {
            return;
        }
        if ($status === 'in_service' && $vehicle->status() === 'rented') {
            return;
        }
        try {
            $this->fleet->setStatus($vehicleId, $status, $reason);
        } catch (\InvalidArgumentException) {
            // ignore invalid transitions
        }
    }

    private function applyIntakeSnapshotToVehicle(ServiceOrder $order): void
    {
        $vehicle = $this->em->find(Vehicle::class, $order->vehicleId());
        if (!$vehicle instanceof Vehicle) {
            return;
        }
        if ($order->intakeMileage() !== null) {
            $vehicle->setMileage($order->intakeMileage());
        }
        if ($order->intakeFuelLevel() !== null) {
            $vehicle->setFuelLevel($order->intakeFuelLevel());
        }
        $this->em->flush();
    }

    /** @param array<string, mixed> $meta */
    private function applyFinishMeta(ServiceOrder $order, array $meta): void
    {
        if (array_key_exists('payment_type', $meta)) {
            $order->setPaymentType($meta['payment_type'] !== null && $meta['payment_type'] !== '' ? (string) $meta['payment_type'] : null);
        }
        if (array_key_exists('internal_cost', $meta)) {
            $order->setInternalCost($meta['internal_cost']);
        }
        if (array_key_exists('external_cost', $meta)) {
            $order->setExternalCost($meta['external_cost']);
        }
        if (array_key_exists('service_info', $meta)) {
            $order->setServiceInfo($meta['service_info'] !== null ? (string) $meta['service_info'] : null);
        }
        if (array_key_exists('intake_mileage', $meta)) {
            $order->setIntakeMileage($meta['intake_mileage'] !== null && $meta['intake_mileage'] !== '' ? (int) $meta['intake_mileage'] : null);
        }
        if (array_key_exists('intake_fuel_level', $meta)) {
            $order->setIntakeFuelLevel($meta['intake_fuel_level'] !== null ? (string) $meta['intake_fuel_level'] : null);
        }
        if (array_key_exists('note', $meta) && $meta['note'] !== null) {
            $order->setNote((string) $meta['note']);
        }
    }

    private function requireOrder(string $id): ServiceOrder
    {
        $order = $this->getOrder($id);
        if ($order === null) {
            throw new \InvalidArgumentException('carservice.error.not_found');
        }

        return $order;
    }
}
