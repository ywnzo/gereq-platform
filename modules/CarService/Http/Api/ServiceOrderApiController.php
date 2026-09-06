<?php

declare(strict_types=1);

namespace Modules\CarService\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\CarService\Application\BranchContext;
use Modules\CarService\Application\CarServiceProvider;
use Modules\CarService\Domain\ServiceBranch;
use Modules\CarService\Domain\ServiceOrder;
use Modules\Fleet\Domain\Vehicle;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class ServiceOrderApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly CarServiceProvider $carservice,
        private readonly Authenticator $auth,
        private readonly BranchContext $branchContext,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest($request, sortable: ['status', 'team', 'createdAt', 'updatedAt'], filterable: ['status', 'team', 'vehicle_id', 'customer_id']);
        $qb = $this->em->getRepository(ServiceOrder::class)->createQueryBuilder('o');
        if (!empty($query->filters['status'])) {
            $qb->andWhere('o.status = :s')->setParameter('s', $query->filters['status']);
        }
        if (!empty($query->filters['team'])) {
            $qb->andWhere('o.team = :t')->setParameter('t', $query->filters['team']);
        }
        if (!empty($query->filters['vehicle_id'])) {
            $qb->andWhere('o.vehicleId = :v')->setParameter('v', $query->filters['vehicle_id']);
        }
        if (!empty($query->filters['customer_id'])) {
            $qb->andWhere('o.customerId = :c')->setParameter('c', $query->filters['customer_id']);
        }
        $sort = $query->sort ?? 'updatedAt';
        $qb->orderBy('o.' . $sort, strtoupper($query->direction));
        $total = (int) (clone $qb)->select('COUNT(o.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(array_map(fn (ServiceOrder $o) => $this->carservice->serialize($o), $items), $query->meta($total));
    }

    public function store(Request $request): JsonResponse
    {
        $data = gereq()->make(ValidationRegistry::class)->validate('carservice.save', $this->body($request));
        $vehicleId = trim((string) $data['vehicle_id']);
        if (!$this->em->find(Vehicle::class, $vehicleId) instanceof Vehicle) {
            return ApiResponse::error('validation_error', 'Invalid vehicle', [], 422);
        }
        $branchId = trim((string) ($data['branch_id'] ?? ''));
        if ($branchId !== '' && !$this->em->find(ServiceBranch::class, $branchId) instanceof ServiceBranch) {
            return ApiResponse::error('validation_error', 'Invalid branch', [], 422);
        }
        $user = $this->auth->user();
        if ($user instanceof \Gereq\User\Entity\User && !$user->isAdministrator()) {
            $home = $this->branchContext->defaultBranchId($user);
            if ($home !== null) {
                $branchId = $home;
            }
        }
        try {
            $order = $this->carservice->createOrder($vehicleId, trim((string) ($data['customer_id'] ?? '')) ?: null, [
                'type' => $data['type'] ?? null,
                'team' => $data['team'] ?? null,
                'branch_id' => $branchId !== '' ? $branchId : null,
                'note' => $data['note'] ?? null,
                'link_type' => $this->body($request)['link_type'] ?? null,
                'link_id' => $this->body($request)['link_id'] ?? null,
            ]);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }
        $payload = $this->carservice->serialize($order);
        $this->events->emit('carservice.order_created', ['type' => 'carservice_order', 'id' => $order->id()], $payload);

        return ApiResponse::data($payload, 201);
    }

    public function show(string $id): JsonResponse
    {
        $order = $this->carservice->getOrder($id);
        if ($order === null) {
            return ApiResponse::notFound();
        }
        $jobs = $this->carservice->jobsForOrder($id);
        $data = $this->carservice->serialize($order, $jobs);
        $data['jobs'] = array_map(fn ($j) => $this->carservice->serializeJob($j), $jobs);

        return ApiResponse::data($data);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $order = $this->carservice->getOrder($id);
        if ($order === null) {
            return ApiResponse::notFound();
        }
        if (!$this->branchContext->canMutateOrder($this->auth->user(), $order)) {
            return ApiResponse::forbidden();
        }
        if (!$order->isOpen()) {
            return ApiResponse::error('order_locked', 'carservice.error.order_locked', [], 409);
        }
        $data = gereq()->make(ValidationRegistry::class)->validate('carservice.save', $this->body($request));
        $vehicleId = trim((string) $data['vehicle_id']);
        if (!$this->em->find(Vehicle::class, $vehicleId) instanceof Vehicle) {
            return ApiResponse::error('validation_error', 'Invalid vehicle', [], 422);
        }
        $order->setVehicleId($vehicleId);
        $order->setCustomerId(trim((string) ($data['customer_id'] ?? '')) ?: null);
        if (isset($data['type'])) {
            $order->setType((string) $data['type']);
        }
        if (isset($data['status'])) {
            $order->setStatus((string) $data['status']);
        }
        if (isset($data['team'])) {
            $order->setTeam((string) $data['team']);
        }
        if (array_key_exists('note', $data)) {
            $order->setNote($data['note'] !== null ? (string) $data['note'] : null);
        }
        $this->em->flush();
        $payload = $this->carservice->serialize($order);
        $this->events->emit('carservice.order_updated', ['type' => 'carservice_order', 'id' => $id], $payload);

        return ApiResponse::data($payload);
    }

    public function switchTeam(Request $request, string $id): JsonResponse
    {
        $order = $this->carservice->getOrder($id);
        if ($order === null) {
            return ApiResponse::notFound();
        }
        if (!$this->branchContext->canMutateOrder($this->auth->user(), $order)) {
            return ApiResponse::forbidden();
        }
        $body = $this->body($request);
        try {
            $order = $this->carservice->switchTeam($id, (string) ($body['team'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }
        $payload = $this->carservice->serialize($order);
        $this->events->emit('carservice.team_switched', ['type' => 'carservice_order', 'id' => $id], $payload);

        return ApiResponse::data($payload);
    }

    public function addJob(Request $request, string $id): JsonResponse
    {
        $order = $this->carservice->getOrder($id);
        if ($order === null) {
            return ApiResponse::notFound();
        }
        if (!$this->branchContext->canMutateOrder($this->auth->user(), $order)) {
            return ApiResponse::forbidden();
        }
        $data = gereq()->make(ValidationRegistry::class)->validate('carservice.job', $this->body($request));
        try {
            $job = $this->carservice->addJob(
                $id,
                trim((string) ($data['type'] ?? 'work')) ?: 'work',
                trim((string) ($data['employee_user_id'] ?? '')) ?: null,
                trim((string) ($data['note'] ?? '')) ?: null,
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }
        $payload = $this->carservice->serializeJob($job);
        $this->events->emit('carservice.job_started', ['type' => 'carservice_job', 'id' => $job->id()], $payload);

        return ApiResponse::data($payload, 201);
    }

    public function completeJob(string $id, string $jobId): JsonResponse
    {
        $order = $this->carservice->getOrder($id);
        if ($order === null) {
            return ApiResponse::notFound();
        }
        if (!$this->branchContext->canMutateOrder($this->auth->user(), $order)) {
            return ApiResponse::forbidden();
        }
        if (!$order->isOpen()) {
            return ApiResponse::error('order_locked', 'carservice.error.order_locked', [], 409);
        }
        try {
            $job = $this->carservice->completeJob($jobId);
            if ($job->orderId() !== $id) {
                return ApiResponse::notFound();
            }
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }
        $payload = $this->carservice->serializeJob($job);
        $this->events->emit('carservice.job_finished', ['type' => 'carservice_job', 'id' => $jobId], $payload);

        return ApiResponse::data($payload);
    }

    public function finish(string $id): JsonResponse
    {
        try {
            $order = $this->carservice->finishOrder($id);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }
        $payload = $this->carservice->serialize($order);
        $this->events->emit('carservice.order_finished', ['type' => 'carservice_order', 'id' => $id], $payload);

        return ApiResponse::data($payload);
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
