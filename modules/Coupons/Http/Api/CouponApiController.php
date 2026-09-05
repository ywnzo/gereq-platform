<?php

declare(strict_types=1);

namespace Modules\Coupons\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Coupons\Application\CouponsProvider;
use Modules\Coupons\Domain\Coupon;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class CouponApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly CouponsProvider $coupons,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest(
            $request,
            sortable: ['code', 'name', 'createdAt', 'endsAt'],
            filterable: ['type', 'active'],
        );

        $qb = $this->em->getRepository(Coupon::class)->createQueryBuilder('c');
        self_repair_search($qb, 'c', ['code', 'name'], $query->search);
        if (isset($query->filters['type']) && $query->filters['type'] !== '') {
            $qb->andWhere('c.type = :type')->setParameter('type', $query->filters['type']);
        }
        if (isset($query->filters['active']) && $query->filters['active'] !== '') {
            $qb->andWhere('c.active = :active')
                ->setParameter('active', filter_var($query->filters['active'], FILTER_VALIDATE_BOOLEAN));
        }

        $sort = $query->sort ?? 'code';
        $qb->orderBy('c.' . $sort, strtoupper($query->direction));

        $total = (int) (clone $qb)->select('COUNT(c.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<Coupon> $items */
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (Coupon $c): array => $this->serialize($c), $items),
            $query->meta($total),
        );
    }

    public function validateCoupon(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $code = trim((string) ($body['code'] ?? ''));
        $subtotal = $body['subtotal'] ?? '0';
        if ($code === '') {
            return ApiResponse::error('validation_error', 'code required', ['code' => 'required'], 422);
        }
        $result = $this->coupons->validateForCart($code, $subtotal);

        return ApiResponse::data([
            'code' => strtoupper($code),
            'ok' => $result['ok'],
            'discount' => $result['discount'],
            'message' => $result['message'],
        ], $result['ok'] ? 200 : 422);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        if ($this->em->getRepository(Coupon::class)->findOneBy(['code' => $data['code']]) instanceof Coupon) {
            return ApiResponse::error('validation_error', 'Code taken', ['code' => 'taken'], 422);
        }

        $coupon = new Coupon($data['code'], $data['name'], $data['type'], $data['value']);
        $this->applyData($coupon, $data);
        $this->em->persist($coupon);
        $this->em->flush();

        $payload = $this->serialize($coupon);
        $this->events->emit('coupon.created', ['type' => 'coupon', 'id' => $coupon->id()], $payload);

        return ApiResponse::data($payload, 201);
    }

    public function show(string $id): JsonResponse
    {
        $coupon = $this->find($id);
        if ($coupon === null) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->serialize($coupon));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $coupon = $this->find($id);
        if ($coupon === null) {
            return ApiResponse::notFound();
        }

        $data = $this->validated($request);
        $dup = $this->em->getRepository(Coupon::class)->findOneBy(['code' => $data['code']]);
        if ($dup instanceof Coupon && $dup->id() !== $coupon->id()) {
            return ApiResponse::error('validation_error', 'Code taken', ['code' => 'taken'], 422);
        }

        $coupon->setCode($data['code']);
        $coupon->setName($data['name']);
        $coupon->setType($data['type']);
        $coupon->setValue($data['value']);
        $this->applyData($coupon, $data);
        $this->em->flush();

        $payload = $this->serialize($coupon);
        $this->events->emit('coupon.updated', ['type' => 'coupon', 'id' => $coupon->id()], $payload);

        return ApiResponse::data($payload);
    }

    public function destroy(string $id): JsonResponse
    {
        $coupon = $this->find($id);
        if ($coupon === null) {
            return ApiResponse::notFound();
        }

        $payload = $this->serialize($coupon);
        $this->em->remove($coupon);
        $this->em->flush();
        $this->events->emit('coupon.deleted', ['type' => 'coupon', 'id' => $id], $payload);

        return ApiResponse::data(['id' => $id, 'deleted' => true]);
    }

    private function find(string $id): ?Coupon
    {
        $coupon = $this->em->find(Coupon::class, $id);

        return $coupon instanceof Coupon ? $coupon : null;
    }

    /**
     * @return array{
     *     code: string,
     *     name: string,
     *     type: string,
     *     value: string,
     *     currency: ?string,
     *     min_order: ?string,
     *     max_uses: ?int,
     *     starts_at: ?\DateTimeImmutable,
     *     ends_at: ?\DateTimeImmutable,
     *     active: bool
     * }
     */
    private function validated(Request $request): array
    {
        $data = $this->validateBody($request, 'coupons.save');
        $activeRaw = $data['active'] ?? true;
        $currency = trim((string) ($data['currency'] ?? ''));
        $minOrder = trim((string) ($data['min_order'] ?? ''));
        $maxUses = trim((string) ($data['max_uses'] ?? ''));

        return [
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'type' => strtolower(trim((string) ($data['type'] ?? 'percent'))),
            'value' => number_format((float) ($data['value'] ?? 0), 2, '.', ''),
            'currency' => $currency === '' ? null : strtoupper($currency),
            'min_order' => $minOrder === '' ? null : number_format((float) $minOrder, 2, '.', ''),
            'max_uses' => $maxUses === '' ? null : (int) $maxUses,
            'starts_at' => $this->parseDate($data['starts_at'] ?? null),
            'ends_at' => $this->parseDate($data['ends_at'] ?? null),
            'active' => $activeRaw === true || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'on',
        ];
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array{
     *     currency: ?string,
     *     min_order: ?string,
     *     max_uses: ?int,
     *     starts_at: ?\DateTimeImmutable,
     *     ends_at: ?\DateTimeImmutable,
     *     active: bool
     * } $data
     */
    private function applyData(Coupon $coupon, array $data): void
    {
        $coupon->setCurrency($data['currency']);
        $coupon->setMinOrder($data['min_order']);
        $coupon->setMaxUses($data['max_uses']);
        $coupon->setStartsAt($data['starts_at']);
        $coupon->setEndsAt($data['ends_at']);
        $coupon->setActive($data['active']);
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
    private function serialize(Coupon $coupon): array
    {
        return [
            'id' => $coupon->id(),
            'code' => $coupon->code(),
            'name' => $coupon->name(),
            'type' => $coupon->type(),
            'value' => $coupon->value(),
            'currency' => $coupon->currency(),
            'min_order' => $coupon->minOrder(),
            'max_uses' => $coupon->maxUses(),
            'used_count' => $coupon->usedCount(),
            'starts_at' => $coupon->startsAt()?->format(\DateTimeInterface::ATOM),
            'ends_at' => $coupon->endsAt()?->format(\DateTimeInterface::ATOM),
            'active' => $coupon->isActive(),
            'created_at' => $coupon->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $coupon->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
