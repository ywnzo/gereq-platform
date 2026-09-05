<?php

declare(strict_types=1);

namespace Modules\Warehouse\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Warehouse\Application\StockService;
use Modules\Warehouse\Domain\StockItem;
use Modules\Warehouse\Domain\StockMovement;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class StockApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly StockService $stock,
        private readonly Authenticator $auth,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest($request, sortable: ['qty', 'updatedAt'], filterable: ['warehouse_id', 'product_id']);
        $qb = $this->em->getRepository(StockItem::class)->createQueryBuilder('s');
        if (isset($query->filters['warehouse_id']) && $query->filters['warehouse_id'] !== '') {
            $qb->andWhere('s.warehouseId = :wid')->setParameter('wid', $query->filters['warehouse_id']);
        }
        if (isset($query->filters['product_id']) && $query->filters['product_id'] !== '') {
            $qb->andWhere('s.productId = :pid')->setParameter('pid', $query->filters['product_id']);
        }
        $sort = $query->sort ?? 'updatedAt';
        $qb->orderBy('s.' . $sort, strtoupper($query->direction));
        $total = (int) (clone $qb)->select('COUNT(s.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (StockItem $i): array => $this->stock->serializeStock($i), $items),
            $query->meta($total),
        );
    }

    public function movements(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest($request, sortable: ['createdAt'], filterable: ['warehouse_id', 'product_id', 'type']);
        $qb = $this->em->getRepository(StockMovement::class)->createQueryBuilder('m');
        if (isset($query->filters['warehouse_id']) && $query->filters['warehouse_id'] !== '') {
            $qb->andWhere('m.warehouseId = :wid')->setParameter('wid', $query->filters['warehouse_id']);
        }
        if (isset($query->filters['product_id']) && $query->filters['product_id'] !== '') {
            $qb->andWhere('m.productId = :pid')->setParameter('pid', $query->filters['product_id']);
        }
        if (isset($query->filters['type']) && $query->filters['type'] !== '') {
            $qb->andWhere('m.type = :type')->setParameter('type', $query->filters['type']);
        }
        $qb->orderBy('m.createdAt', strtoupper($query->direction));
        $total = (int) (clone $qb)->select('COUNT(m.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (StockMovement $m): array => $this->stock->serializeMovement($m), $items),
            $query->meta($total),
        );
    }

    public function storeMovement(Request $request): JsonResponse
    {
        $data = gereq()->make(ValidationRegistry::class)->validate('warehouse.movement.save', $this->body($request));
        try {
            $result = $this->stock->createMovement(
                trim((string) $data['warehouse_id']),
                trim((string) $data['product_id']),
                (string) $data['type'],
                (string) $data['qty'],
                isset($data['target_warehouse_id']) ? trim((string) $data['target_warehouse_id']) : null,
                isset($data['note']) ? trim((string) $data['note']) : null,
                $this->auth->user()?->id(),
            );
        } catch (\RuntimeException $e) {
            return ApiResponse::error('insufficient_stock', $e->getMessage(), [], 422);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }

        return ApiResponse::data($this->stock->serializeMovement($result['movement']), 201);
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
