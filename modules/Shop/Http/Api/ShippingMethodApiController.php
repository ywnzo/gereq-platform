<?php

declare(strict_types=1);

namespace Modules\Shop\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Currency\Application\CurrencyBuilder;
use Modules\Shop\Domain\ShippingMethod;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class ShippingMethodApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly CurrencyBuilder $money,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest($request, sortable: ['code', 'name', 'price', 'sortOrder'], filterable: ['active', 'currency', 'type', 'carrier']);
        $qb = $this->em->getRepository(ShippingMethod::class)->createQueryBuilder('s');

        $activeOnly = $request->query->get('active_only', '1') !== '0';
        if ($activeOnly && !isset($query->filters['active'])) {
            $qb->andWhere('s.active = true');
        }
        if (isset($query->filters['active']) && $query->filters['active'] !== '') {
            $qb->andWhere('s.active = :active')->setParameter('active', filter_var($query->filters['active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (isset($query->filters['currency']) && $query->filters['currency'] !== '') {
            $qb->andWhere('s.currency = :currency')->setParameter('currency', strtoupper((string) $query->filters['currency']));
        }
        if (isset($query->filters['type']) && $query->filters['type'] !== '') {
            $qb->andWhere('s.type = :type')->setParameter('type', (string) $query->filters['type']);
        }
        if (isset($query->filters['carrier']) && $query->filters['carrier'] !== '') {
            $qb->andWhere('s.carrier = :carrier')->setParameter('carrier', (string) $query->filters['carrier']);
        }
        self_repair_search($qb, 's', ['code', 'name', 'carrier'], $query->search);

        $sort = $query->sort ?? 'sortOrder';
        $qb->orderBy('s.' . $sort, strtoupper($query->direction));
        if ($sort !== 'code') {
            $qb->addOrderBy('s.code', 'ASC');
        }
        $total = (int) (clone $qb)->select('COUNT(s.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (ShippingMethod $m): array => $this->serialize($m), $items),
            $query->meta($total),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateBody($request, 'shop.shipping.save');
        $code = strtoupper(trim((string) $data['code']));
        $existing = $this->em->getRepository(ShippingMethod::class)->findOneBy(['code' => $code]);
        if ($existing instanceof ShippingMethod) {
            return ApiResponse::error('validation_error', 'Code taken', ['code' => 'taken'], 422);
        }

        $method = new ShippingMethod(
            $code,
            trim((string) $data['name']),
            (string) ($data['price'] ?? '0'),
            $this->money->code(isset($data['currency']) ? (string) $data['currency'] : null),
        );
        $activeRaw = $data['active'] ?? true;
        $method->setActive($activeRaw === true || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'on');
        $days = trim((string) ($data['estimated_days'] ?? ''));
        $method->setEstimatedDays($days === '' ? null : (int) $days);
        $method->setCarrier(trim((string) ($data['carrier'] ?? '')) ?: null);
        $method->setType(trim((string) ($data['type'] ?? 'courier')) ?: 'courier');
        $method->setDescription(trim((string) ($data['description'] ?? '')) ?: null);
        $method->setSortOrder((int) ($data['sort_order'] ?? 0));
        $this->em->persist($method);
        $this->em->flush();

        $payload = $this->serialize($method);
        $this->events->emit('shipping.method_created', ['type' => 'shipping_method', 'id' => $method->id()], $payload);

        return ApiResponse::data($payload, 201);
    }

    public function show(string $id): JsonResponse
    {
        $method = $this->em->find(ShippingMethod::class, $id);
        if (!$method instanceof ShippingMethod) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->serialize($method));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $method = $this->em->find(ShippingMethod::class, $id);
        if (!$method instanceof ShippingMethod) {
            return ApiResponse::notFound();
        }

        $data = $this->validateBody($request, 'shop.shipping.save');
        $code = strtoupper(trim((string) $data['code']));
        $dup = $this->em->getRepository(ShippingMethod::class)->findOneBy(['code' => $code]);
        if ($dup instanceof ShippingMethod && $dup->id() !== $method->id()) {
            return ApiResponse::error('validation_error', 'Code taken', ['code' => 'taken'], 422);
        }

        $method->setCode($code);
        $method->setName(trim((string) $data['name']));
        $method->setPrice((string) ($data['price'] ?? '0'));
        $method->setCurrency($this->money->code(isset($data['currency']) ? (string) $data['currency'] : null));
        $activeRaw = $data['active'] ?? $method->isActive();
        $method->setActive($activeRaw === true || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'on');
        $days = trim((string) ($data['estimated_days'] ?? ''));
        $method->setEstimatedDays($days === '' ? null : (int) $days);
        $method->setCarrier(trim((string) ($data['carrier'] ?? '')) ?: null);
        $method->setType(trim((string) ($data['type'] ?? $method->type())) ?: 'courier');
        $method->setDescription(trim((string) ($data['description'] ?? '')) ?: null);
        $method->setSortOrder((int) ($data['sort_order'] ?? $method->sortOrder()));
        $this->em->flush();

        $payload = $this->serialize($method);
        $this->events->emit('shipping.method_updated', ['type' => 'shipping_method', 'id' => $method->id()], $payload);

        return ApiResponse::data($payload);
    }

    public function destroy(string $id): JsonResponse
    {
        $method = $this->em->find(ShippingMethod::class, $id);
        if (!$method instanceof ShippingMethod) {
            return ApiResponse::notFound();
        }

        $payload = $this->serialize($method);
        $this->em->remove($method);
        $this->em->flush();
        $this->events->emit('shipping.method_deleted', ['type' => 'shipping_method', 'id' => $id], $payload);

        return ApiResponse::data(['id' => $id, 'deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function serialize(ShippingMethod $method): array
    {
        return [
            'id' => $method->id(),
            'code' => $method->code(),
            'name' => $method->name(),
            'carrier' => $method->carrier(),
            'type' => $method->type(),
            'description' => $method->description(),
            'price' => $method->price(),
            'currency' => $method->currency(),
            'active' => $method->isActive(),
            'estimated_days' => $method->estimatedDays(),
            'sort_order' => $method->sortOrder(),
            'created_at' => $method->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $method->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
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
}
