<?php

declare(strict_types=1);

namespace Modules\Bundles\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Bundles\Application\BundlesProvider;
use Modules\Bundles\Domain\ProductBundle;
use Modules\Bundles\Domain\ProductBundleItem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class BundleApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly BundlesProvider $bundles,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest(
            $request,
            sortable: ['code', 'name', 'createdAt'],
            filterable: ['active'],
        );

        $qb = $this->em->getRepository(ProductBundle::class)->createQueryBuilder('b');
        self_repair_search($qb, 'b', ['code', 'name'], $query->search);
        if (isset($query->filters['active']) && $query->filters['active'] !== '') {
            $qb->andWhere('b.active = :active')
                ->setParameter('active', filter_var($query->filters['active'], FILTER_VALIDATE_BOOLEAN));
        }

        $sort = $query->sort ?? 'code';
        $qb->orderBy('b.' . $sort, strtoupper($query->direction));

        $total = (int) (clone $qb)->select('COUNT(b.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<ProductBundle> $items */
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (ProductBundle $b): array => $this->serialize($b), $items),
            $query->meta($total),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        if ($this->em->getRepository(ProductBundle::class)->findOneBy(['code' => $data['code']]) instanceof ProductBundle) {
            return ApiResponse::error('validation_error', 'Code taken', ['code' => 'taken'], 422);
        }

        $bundle = new ProductBundle($data['code'], $data['name']);
        $this->applyData($bundle, $data);
        $this->em->persist($bundle);
        $this->syncItems($bundle, $data['items']);
        $this->em->flush();

        $payload = $this->serialize($bundle);
        $this->events->emit('bundle.created', ['type' => 'product_bundle', 'id' => $bundle->id()], $payload);

        return ApiResponse::data($payload, 201);
    }

    public function show(string $id): JsonResponse
    {
        $bundle = $this->find($id);
        if ($bundle === null) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->serialize($bundle));
    }

    public function resolve(string $id): JsonResponse
    {
        $resolved = $this->bundles->resolve($id);
        if ($resolved === null) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($resolved);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $bundle = $this->find($id);
        if ($bundle === null) {
            return ApiResponse::notFound();
        }

        $data = $this->validated($request);
        $dup = $this->em->getRepository(ProductBundle::class)->findOneBy(['code' => $data['code']]);
        if ($dup instanceof ProductBundle && $dup->id() !== $bundle->id()) {
            return ApiResponse::error('validation_error', 'Code taken', ['code' => 'taken'], 422);
        }

        $bundle->setCode($data['code']);
        $bundle->setName($data['name']);
        $this->applyData($bundle, $data);
        $this->syncItems($bundle, $data['items']);
        $this->em->flush();

        $payload = $this->serialize($bundle);
        $this->events->emit('bundle.updated', ['type' => 'product_bundle', 'id' => $bundle->id()], $payload);

        return ApiResponse::data($payload);
    }

    public function destroy(string $id): JsonResponse
    {
        $bundle = $this->find($id);
        if ($bundle === null) {
            return ApiResponse::notFound();
        }

        $payload = $this->serialize($bundle);
        foreach ($this->em->getRepository(ProductBundleItem::class)->findBy(['bundleId' => $id]) as $row) {
            $this->em->remove($row);
        }
        $this->em->remove($bundle);
        $this->em->flush();
        $this->events->emit('bundle.deleted', ['type' => 'product_bundle', 'id' => $id], $payload);

        return ApiResponse::data(['id' => $id, 'deleted' => true]);
    }

    private function find(string $id): ?ProductBundle
    {
        $bundle = $this->em->find(ProductBundle::class, $id);

        return $bundle instanceof ProductBundle ? $bundle : null;
    }

    /**
     * @return array{
     *     code: string,
     *     name: string,
     *     description: ?string,
     *     discount_type: string,
     *     discount_value: string,
     *     currency: ?string,
     *     min_items: int,
     *     max_items: ?int,
     *     max_same_product: ?int,
     *     items: list<array{product_id: string, title: string, qty: int}>,
     *     active: bool
     * }
     */
    private function validated(Request $request): array
    {
        $data = $this->validateBody($request, 'bundles.save');
        $activeRaw = $data['active'] ?? true;
        $currency = trim((string) ($data['currency'] ?? ''));
        $maxItems = trim((string) ($data['max_items'] ?? ''));
        $maxSame = trim((string) ($data['max_same_product'] ?? ''));
        $items = $data['items'] ?? null;
        if (!is_array($items)) {
            $items = $this->parseItemsJson((string) ($data['items_json'] ?? '[]'));
        } else {
            $items = $this->normalizeItems($items);
        }

        return [
            'code' => strtoupper(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'discount_type' => strtolower(trim((string) ($data['discount_type'] ?? 'none'))),
            'discount_value' => number_format((float) ($data['discount_value'] ?? 0), 2, '.', ''),
            'currency' => $currency === '' ? null : strtoupper($currency),
            'min_items' => max(1, (int) ($data['min_items'] ?? 1)),
            'max_items' => $maxItems === '' ? null : (int) $maxItems,
            'max_same_product' => $maxSame === '' ? null : (int) $maxSame,
            'items' => $items,
            'active' => $activeRaw === true || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'on',
        ];
    }

    /**
     * @return list<array{product_id: string, title: string, qty: int}>
     */
    private function parseItemsJson(string $raw): array
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $this->normalizeItems($decoded) : [];
    }

    /**
     * @param list<mixed>|array<int|string, mixed> $rows
     * @return list<array{product_id: string, title: string, qty: int}>
     */
    private function normalizeItems(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $productId = trim((string) ($row['product_id'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            if ($productId === '' || $title === '') {
                continue;
            }
            $items[] = [
                'product_id' => $productId,
                'title' => $title,
                'qty' => max(1, (int) ($row['qty'] ?? 1)),
            ];
        }

        return $items;
    }

    /**
     * @param array{
     *     description: ?string,
     *     discount_type: string,
     *     discount_value: string,
     *     currency: ?string,
     *     min_items: int,
     *     max_items: ?int,
     *     max_same_product: ?int,
     *     active: bool
     * } $data
     */
    private function applyData(ProductBundle $bundle, array $data): void
    {
        $bundle->setDescription($data['description']);
        $bundle->setDiscountType($data['discount_type']);
        $bundle->setDiscountValue($data['discount_value']);
        $bundle->setCurrency($data['currency']);
        $bundle->setMinItems($data['min_items']);
        $bundle->setMaxItems($data['max_items']);
        $bundle->setMaxSameProduct($data['max_same_product']);
        $bundle->setActive($data['active']);
    }

    /** @param list<array{product_id: string, title: string, qty: int}> $items */
    private function syncItems(ProductBundle $bundle, array $items): void
    {
        foreach ($this->em->getRepository(ProductBundleItem::class)->findBy(['bundleId' => $bundle->id()]) as $row) {
            $this->em->remove($row);
        }
        foreach ($items as $i => $row) {
            $this->em->persist(new ProductBundleItem(
                $bundle->id(),
                $row['product_id'],
                $row['title'],
                $row['qty'],
                $i,
            ));
        }
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
    private function serialize(ProductBundle $bundle): array
    {
        /** @var list<ProductBundleItem> $rows */
        $rows = $this->em->getRepository(ProductBundleItem::class)->findBy(
            ['bundleId' => $bundle->id()],
            ['sortOrder' => 'ASC'],
        );

        return [
            'id' => $bundle->id(),
            'code' => $bundle->code(),
            'name' => $bundle->name(),
            'description' => $bundle->description(),
            'discount_type' => $bundle->discountType(),
            'discount_value' => $bundle->discountValue(),
            'currency' => $bundle->currency(),
            'min_items' => $bundle->minItems(),
            'max_items' => $bundle->maxItems(),
            'max_same_product' => $bundle->maxSameProduct(),
            'active' => $bundle->isActive(),
            'items' => array_map(static fn (ProductBundleItem $item): array => [
                'id' => $item->id(),
                'product_id' => $item->productId(),
                'title' => $item->title(),
                'qty' => $item->qty(),
                'sort_order' => $item->sortOrder(),
            ], $rows),
            'created_at' => $bundle->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $bundle->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
