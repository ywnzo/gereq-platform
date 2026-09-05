<?php

declare(strict_types=1);

namespace Modules\Warehouse\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Warehouse\Application\StockService;
use Modules\Warehouse\Domain\Warehouse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class WarehouseApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly StockService $stock,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest($request, sortable: ['code', 'name', 'createdAt'], filterable: ['active']);
        $qb = $this->em->getRepository(Warehouse::class)->createQueryBuilder('w');
        if ($query->search !== '') {
            $qb->andWhere('w.code LIKE :q OR w.name LIKE :q')->setParameter('q', '%' . $query->search . '%');
        }
        if (isset($query->filters['active']) && $query->filters['active'] !== '') {
            $qb->andWhere('w.active = :active')->setParameter('active', filter_var($query->filters['active'], FILTER_VALIDATE_BOOLEAN));
        }
        $sort = $query->sort ?? 'code';
        $qb->orderBy('w.' . $sort, strtoupper($query->direction));
        $total = (int) (clone $qb)->select('COUNT(w.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (Warehouse $w): array => $this->stock->serializeWarehouse($w), $items),
            $query->meta($total),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateBody($request, 'warehouse.save');
        $code = strtoupper(trim((string) $data['code']));
        if ($this->em->getRepository(Warehouse::class)->findOneBy(['code' => $code]) instanceof Warehouse) {
            return ApiResponse::error('validation_error', 'Code taken', ['code' => 'taken'], 422);
        }
        $w = new Warehouse($code, trim((string) $data['name']));
        $active = $data['active'] ?? true;
        $w->setActive($active === true || $active === '1' || $active === 1);
        $this->em->persist($w);
        $this->em->flush();

        return ApiResponse::data($this->stock->serializeWarehouse($w), 201);
    }

    public function show(string $id): JsonResponse
    {
        $w = $this->em->find(Warehouse::class, $id);
        if (!$w instanceof Warehouse) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->stock->serializeWarehouse($w));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $w = $this->em->find(Warehouse::class, $id);
        if (!$w instanceof Warehouse) {
            return ApiResponse::notFound();
        }
        $data = $this->validateBody($request, 'warehouse.save');
        $code = strtoupper(trim((string) $data['code']));
        $dup = $this->em->getRepository(Warehouse::class)->findOneBy(['code' => $code]);
        if ($dup instanceof Warehouse && $dup->id() !== $w->id()) {
            return ApiResponse::error('validation_error', 'Code taken', ['code' => 'taken'], 422);
        }
        $w->setCode($code);
        $w->setName(trim((string) $data['name']));
        $active = $data['active'] ?? $w->isActive();
        $w->setActive($active === true || $active === '1' || $active === 1);
        $this->em->flush();

        return ApiResponse::data($this->stock->serializeWarehouse($w));
    }

    public function destroy(string $id): JsonResponse
    {
        $w = $this->em->find(Warehouse::class, $id);
        if (!$w instanceof Warehouse) {
            return ApiResponse::notFound();
        }
        $this->em->remove($w);
        $this->em->flush();

        return ApiResponse::data(['id' => $id, 'deleted' => true]);
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
