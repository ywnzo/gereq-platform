<?php

declare(strict_types=1);

namespace Modules\Products\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Products\Domain\Events\ProductActivated;
use Modules\Products\Domain\Events\ProductCreated;
use Modules\Products\Domain\Events\ProductDeactivated;
use Modules\Products\Domain\Events\ProductUpdated;
use Modules\Products\Domain\Product;
use Modules\Products\Domain\ProductCategory;
use Modules\Products\Domain\ProductImage;
use Modules\Products\Domain\ProductPrice;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class ProductApiController extends Controller
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
            sortable: ['sku', 'name', 'status', 'createdAt', 'updatedAt'],
            filterable: ['status', 'sku'],
        );

        $qb = $this->em->getRepository(Product::class)->createQueryBuilder('p');
        if ($query->search !== '') {
            $qb->andWhere('p.name LIKE :q OR p.sku LIKE :q OR p.description LIKE :q')
                ->setParameter('q', '%' . $query->search . '%');
        }
        if (isset($query->filters['status']) && $query->filters['status'] !== '') {
            $qb->andWhere('p.status = :status')->setParameter('status', $query->filters['status']);
        }
        if (isset($query->filters['sku']) && $query->filters['sku'] !== '') {
            $qb->andWhere('p.sku = :sku')->setParameter('sku', strtoupper((string) $query->filters['sku']));
        }

        $sort = $query->sort ?? 'updatedAt';
        $qb->orderBy('p.' . $sort, strtoupper($query->direction));

        $total = (int) (clone $qb)->select('COUNT(p.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<Product> $items */
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (Product $p): array => $this->serialize($p), $items),
            $query->meta($total),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->parseProductData($this->validateBody($request, 'products.product.save'));
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $existing = $this->em->getRepository(Product::class)->findOneBy(['sku' => $data['sku']]);
        if ($existing instanceof Product) {
            return ApiResponse::error('validation_error', 'SKU už existuje.', ['sku' => 'unique'], 422);
        }

        $product = new Product($data['sku'], $data['name']);
        $product->setDescription($data['description']);
        $product->setStatus($data['status']);
        $product->setUnit($data['unit']);
        $this->applyCategories($product, $data['category_ids']);
        $this->em->persist($product);
        $this->em->flush();

        $payload = $this->serialize($product);
        $this->events->dispatch(ProductCreated::make($product->id(), $payload));
        if ($product->status() === 'active') {
            $this->events->dispatch(ProductActivated::make($product->id(), $payload));
        }

        return ApiResponse::data($payload, 201);
    }

    public function show(string $id): JsonResponse
    {
        $product = $this->find($id);
        if ($product === null) {
            return ApiResponse::notFound();
        }

        $payload = $this->serialize($product);
        $prices = $this->em->getRepository(ProductPrice::class)->findBy(['productId' => $id]);
        $payload['prices'] = array_map(static fn (ProductPrice $price): array => [
            'id' => $price->id(),
            'currency_code' => $price->currencyCode(),
            'amount' => $price->amount(),
            'valid_from' => $price->validFrom()?->format('Y-m-d'),
            'valid_to' => $price->validTo()?->format('Y-m-d'),
        ], $prices);
        $payload['images'] = $this->serializeImages($id);

        return ApiResponse::data($payload);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $product = $this->find($id);
        if ($product === null) {
            return ApiResponse::notFound();
        }

        $data = $this->parseProductData($this->validateBody($request, 'products.product.save'));
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $skuOwner = $this->em->getRepository(Product::class)->findOneBy(['sku' => $data['sku']]);
        if ($skuOwner instanceof Product && $skuOwner->id() !== $product->id()) {
            return ApiResponse::error('validation_error', 'SKU už existuje.', ['sku' => 'unique'], 422);
        }

        $previousStatus = $product->status();
        $product->setSku($data['sku']);
        $product->setName($data['name']);
        $product->setDescription($data['description']);
        $product->setStatus($data['status']);
        $product->setUnit($data['unit']);
        $this->applyCategories($product, $data['category_ids']);
        $this->em->flush();

        $payload = $this->serialize($product);
        $this->events->dispatch(ProductUpdated::make($product->id(), $payload));
        if ($previousStatus !== $product->status()) {
            if ($product->status() === 'active') {
                $this->events->dispatch(ProductActivated::make($product->id(), $payload));
            }
            if ($previousStatus === 'active' && $product->status() !== 'active') {
                $this->events->dispatch(ProductDeactivated::make($product->id(), $payload));
            }
        }

        return ApiResponse::data($payload);
    }

    public function destroy(string $id): JsonResponse
    {
        $product = $this->find($id);
        if ($product === null) {
            return ApiResponse::notFound();
        }

        $payload = $this->serialize($product);
        $prices = $this->em->getRepository(ProductPrice::class)->findBy(['productId' => $id]);
        foreach ($prices as $price) {
            $this->em->remove($price);
        }
        $images = $this->em->getRepository(ProductImage::class)->findBy(['productId' => $id]);
        foreach ($images as $image) {
            $this->em->remove($image);
        }
        $product->clearCategories();
        $this->em->remove($product);
        $this->em->flush();

        return ApiResponse::data(['id' => $id, 'deleted' => true, 'product' => $payload]);
    }

    public function categoriesIndex(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest($request, sortable: ['name', 'slug'], filterable: []);
        $qb = $this->em->getRepository(ProductCategory::class)->createQueryBuilder('c');
        if ($query->search !== '') {
            $qb->andWhere('c.name LIKE :q OR c.slug LIKE :q')
                ->setParameter('q', '%' . $query->search . '%');
        }
        $sort = $query->sort ?? 'name';
        $qb->orderBy('c.' . $sort, strtoupper($query->direction));

        $total = (int) (clone $qb)->select('COUNT(c.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<ProductCategory> $items */
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(static fn (ProductCategory $c): array => [
                'id' => $c->id(),
                'name' => $c->name(),
                'slug' => $c->slug(),
            ], $items),
            $query->meta($total),
        );
    }

    private function find(string $id): ?Product
    {
        $product = $this->em->find(Product::class, $id);

        return $product instanceof Product ? $product : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{sku: string, name: string, description: ?string, status: string, unit: string, category_ids: list<string>}|JsonResponse
     */
    private function parseProductData(array $data): array|JsonResponse
    {
        $status = (string) ($data['status'] ?? 'draft');
        if (!in_array($status, Product::STATUSES, true)) {
            return ApiResponse::error('validation_error', 'Neplatný stav.', ['status' => 'invalid'], 422);
        }

        $description = trim((string) ($data['description'] ?? ''));
        $categoryIds = $data['category_ids'] ?? [];
        if (!is_array($categoryIds)) {
            $categoryIds = $categoryIds !== '' && $categoryIds !== null ? [(string) $categoryIds] : [];
        }

        return [
            'sku' => strtoupper(trim((string) $data['sku'])),
            'name' => trim((string) $data['name']),
            'description' => $description !== '' ? $description : null,
            'status' => $status,
            'unit' => trim((string) ($data['unit'] ?? 'ks')) ?: 'ks',
            'category_ids' => array_values(array_filter(array_map('strval', $categoryIds))),
        ];
    }

    /** @param list<string> $categoryIds */
    private function applyCategories(Product $product, array $categoryIds): void
    {
        $product->clearCategories();
        foreach ($categoryIds as $categoryId) {
            $category = $this->em->find(ProductCategory::class, $categoryId);
            if ($category instanceof ProductCategory) {
                $product->addCategory($category);
            }
        }
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

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function validateBody(Request $request, string $ruleset, array $context = []): array
    {
        return gereq()->make(ValidationRegistry::class)->validate($ruleset, $this->body($request), $context);
    }

    /** @return array<string, mixed> */
    private function serialize(Product $product): array
    {
        return [
            'id' => $product->id(),
            'sku' => $product->sku(),
            'name' => $product->name(),
            'description' => $product->description(),
            'status' => $product->status(),
            'unit' => $product->unit(),
            'categories' => array_map(
                static fn (ProductCategory $c): array => [
                    'id' => $c->id(),
                    'name' => $c->name(),
                    'slug' => $c->slug(),
                ],
                $product->categories()->toArray(),
            ),
            'created_at' => $product->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $product->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function serializeImages(string $productId): array
    {
        $images = $this->em->getRepository(ProductImage::class)->findBy(
            ['productId' => $productId],
            ['isPrimary' => 'DESC', 'sortOrder' => 'ASC', 'createdAt' => 'ASC'],
        );

        $out = [];
        foreach ($images as $image) {
            $row = [
                'id' => $image->id(),
                'media_asset_id' => $image->mediaAssetId(),
                'sort_order' => $image->sortOrder(),
                'is_primary' => $image->isPrimary(),
                'alt' => $image->alt(),
                'url' => '/media/' . $image->mediaAssetId() . '/file',
            ];

            if (class_exists(\Modules\Media\Domain\MediaAsset::class)) {
                $asset = $this->em->find(\Modules\Media\Domain\MediaAsset::class, $image->mediaAssetId());
                if ($asset instanceof \Modules\Media\Domain\MediaAsset) {
                    $row['title'] = $asset->title();
                    $row['mime'] = $asset->mime();
                    $row['width'] = $asset->width();
                    $row['height'] = $asset->height();
                    if ($row['alt'] === null) {
                        $row['alt'] = $asset->alt();
                    }
                }
            }

            $out[] = $row;
        }

        return $out;
    }
}
