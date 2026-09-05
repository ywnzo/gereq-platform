<?php

declare(strict_types=1);

namespace Modules\Payments\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Payments\Domain\PaymentMethod;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class PaymentMethodApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest(
            $request,
            sortable: ['code', 'name', 'sortOrder', 'createdAt'],
            filterable: ['type', 'provider'],
        );

        $qb = $this->em->getRepository(PaymentMethod::class)->createQueryBuilder('m')
            ->where('m.active = true');

        self_repair_search($qb, 'm', ['code', 'name', 'provider'], $query->search);
        if (isset($query->filters['type']) && $query->filters['type'] !== '') {
            $qb->andWhere('m.type = :type')->setParameter('type', $query->filters['type']);
        }
        if (isset($query->filters['provider']) && $query->filters['provider'] !== '') {
            $qb->andWhere('m.provider = :provider')->setParameter('provider', $query->filters['provider']);
        }

        $sort = $query->sort ?? 'sortOrder';
        $qb->orderBy('m.' . $sort, strtoupper($query->direction));
        if ($sort !== 'code') {
            $qb->addOrderBy('m.code', 'ASC');
        }

        $total = (int) (clone $qb)->select('COUNT(m.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<PaymentMethod> $items */
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (PaymentMethod $m): array => $this->serialize($m), $items),
            $query->meta($total),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        if ($this->em->getRepository(PaymentMethod::class)->findOneBy(['code' => $data['code']]) instanceof PaymentMethod) {
            return ApiResponse::error('validation_error', 'Code taken', ['code' => 'taken'], 422);
        }

        $method = new PaymentMethod($data['code'], $data['name'], $data['type']);
        $this->applyData($method, $data);
        $this->em->persist($method);
        $this->em->flush();

        $payload = $this->serialize($method);
        $this->events->emit('payment.method_created', ['type' => 'payment_method', 'id' => $method->id()], $payload);

        return ApiResponse::data($payload, 201);
    }

    public function show(string $id): JsonResponse
    {
        $method = $this->find($id);
        if ($method === null) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->serialize($method));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $method = $this->find($id);
        if ($method === null) {
            return ApiResponse::notFound();
        }

        $data = $this->validated($request);
        $dup = $this->em->getRepository(PaymentMethod::class)->findOneBy(['code' => $data['code']]);
        if ($dup instanceof PaymentMethod && $dup->id() !== $method->id()) {
            return ApiResponse::error('validation_error', 'Code taken', ['code' => 'taken'], 422);
        }

        $method->setCode($data['code']);
        $method->setName($data['name']);
        $method->setType($data['type']);
        $this->applyData($method, $data);
        $this->em->flush();

        $payload = $this->serialize($method);
        $this->events->emit('payment.method_updated', ['type' => 'payment_method', 'id' => $method->id()], $payload);

        return ApiResponse::data($payload);
    }

    public function destroy(string $id): JsonResponse
    {
        $method = $this->find($id);
        if ($method === null) {
            return ApiResponse::notFound();
        }

        $payload = $this->serialize($method);
        $this->em->remove($method);
        $this->em->flush();
        $this->events->emit('payment.method_deleted', ['type' => 'payment_method', 'id' => $id], $payload);

        return ApiResponse::data(['id' => $id, 'deleted' => true]);
    }

    private function find(string $id): ?PaymentMethod
    {
        $method = $this->em->find(PaymentMethod::class, $id);

        return $method instanceof PaymentMethod ? $method : null;
    }

    /**
     * @return array{
     *     code: string,
     *     name: string,
     *     type: string,
     *     provider: ?string,
     *     fee_fixed: string,
     *     fee_percent: string,
     *     currency: string,
     *     instructions: ?string,
     *     sort_order: int,
     *     active: bool
     * }
     */
    private function validated(Request $request): array
    {
        $data = $this->validateBody($request, 'payments.method.save');
        $activeRaw = $data['active'] ?? true;
        $provider = trim((string) ($data['provider'] ?? ''));
        $instructions = trim((string) ($data['instructions'] ?? ''));

        return [
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'type' => strtolower(trim((string) ($data['type'] ?? 'bank_transfer'))),
            'provider' => $provider === '' ? null : $provider,
            'fee_fixed' => number_format((float) ($data['fee_fixed'] ?? 0), 2, '.', ''),
            'fee_percent' => number_format((float) ($data['fee_percent'] ?? 0), 4, '.', ''),
            'currency' => strtoupper(trim((string) ($data['currency'] ?? 'CZK'))) ?: 'CZK',
            'instructions' => $instructions === '' ? null : $instructions,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'active' => $activeRaw === true || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'on',
        ];
    }

    /** @param array{provider: ?string, fee_fixed: string, fee_percent: string, currency: string, instructions: ?string, sort_order: int, active: bool} $data */
    private function applyData(PaymentMethod $method, array $data): void
    {
        $method->setProvider($data['provider']);
        $method->setFeeFixed($data['fee_fixed']);
        $method->setFeePercent($data['fee_percent']);
        $method->setCurrency($data['currency']);
        $method->setInstructions($data['instructions']);
        $method->setSortOrder($data['sort_order']);
        $method->setActive($data['active']);
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function validateBody(Request $request, string $ruleset, array $context = []): array
    {
        return gereq()->make(ValidationRegistry::class)->validate($ruleset, $this->body($request), $context);
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $data = $request->request->all();
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_merge($data, $decoded);
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function serialize(PaymentMethod $method): array
    {
        return [
            'id' => $method->id(),
            'code' => $method->code(),
            'name' => $method->name(),
            'type' => $method->type(),
            'provider' => $method->provider(),
            'fee_fixed' => $method->feeFixed(),
            'fee_percent' => $method->feePercent(),
            'currency' => $method->currency(),
            'instructions' => $method->instructions(),
            'sort_order' => $method->sortOrder(),
            'active' => $method->isActive(),
            'created_at' => $method->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $method->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
