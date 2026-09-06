<?php

declare(strict_types=1);

namespace Modules\Products\Http\Public;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\PublicCors;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Modules\Currency\Application\CurrencyBuilder;
use Modules\Products\Application\ProductPersonalizationService;
use Modules\Products\Application\ProductVariantOptionsService;
use Modules\Products\Domain\Product;
use Modules\Products\Domain\ProductCategory;
use Modules\Products\Domain\ProductImage;
use Modules\Products\Domain\ProductOptionGroup;
use Modules\Products\Domain\ProductPrice;
use Modules\Products\Domain\ProductVariant;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** Public catalog API for Vue storefront (no Bearer token). */
final class PublicStoreCatalogController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly ProductPersonalizationService $personalization,
        private readonly ProductVariantOptionsService $variantOptions,
        private readonly CapabilityRegistry $capabilities,
        private readonly CurrencyBuilder $money,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function options(): Response
    {
        return $this->cors(new Response('', 204), 'GET, OPTIONS');
    }

    public function categories(Request $request): JsonResponse
    {
        $items = $this->em->getRepository(ProductCategory::class)->findBy([], ['name' => 'ASC']);

        return $this->cors(ApiResponse::collection(array_map(
            static fn (ProductCategory $c): array => [
                'id' => $c->id(),
                'name' => $c->name(),
                'slug' => $c->slug(),
            ],
            $items,
        )));
    }

    public function index(Request $request): JsonResponse
    {
        $qb = $this->em->getRepository(Product::class)->createQueryBuilder('p')
            ->andWhere('p.status = :status')
            ->setParameter('status', 'active')
            ->orderBy('p.name', 'ASC');

        $category = trim((string) $request->query->get('category', ''));
        if ($category !== '') {
            $qb->innerJoin('p.categories', 'c')
                ->andWhere('c.slug = :cat OR c.id = :catId')
                ->setParameter('cat', $category)
                ->setParameter('catId', $category);
        }

        $q = trim((string) $request->query->get('q', ''));
        if ($q !== '') {
            self_repair_search($qb, 'p', ['name', 'sku', 'description'], $q);
        }

        $limit = max(1, min(100, (int) $request->query->get('limit', $request->query->get('per_page', 48))));
        $page = max(1, (int) $request->query->get('page', 1));

        $total = (int) (clone $qb)->select('COUNT(DISTINCT p.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<Product> $products */
        $products = $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

        return $this->cors(ApiResponse::collection(
            $this->serializeStoreMany($products),
            [
                'page' => $page,
                'per_page' => $limit,
                'total' => $total,
                'pages' => (int) ceil(max(1, $total) / $limit),
            ],
        ));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $product = $this->em->find(Product::class, $id);
        if (!$product instanceof Product || $product->status() !== 'active') {
            $bySku = $this->em->getRepository(Product::class)->findOneBy(['sku' => strtoupper($id)]);
            $product = $bySku instanceof Product && $bySku->status() === 'active' ? $bySku : null;
        }
        if ($product === null) {
            return $this->cors(ApiResponse::notFound());
        }

        return $this->cors(ApiResponse::data($this->serializeStore($product, true)));
    }

    /**
     * @param list<Product> $products
     * @return list<array<string, mixed>>
     */
    private function serializeStoreMany(array $products): array
    {
        if ($products === []) {
            return [];
        }

        $ids = array_map(static fn (Product $p): string => $p->id(), $products);
        $this->warmCategories($ids);

        $prices = $this->currentPricesByProductIds($ids, $this->money->defaultCode());
        $images = $this->primaryImageUrlsByProductIds($ids);
        $variantFlags = $this->productIdsWithActiveVariants($ids);
        $allCategoryIds = $this->categoryIdsFromProducts($products);
        $optionFlags = $this->variantOptions->optionGroupFlags($ids, $allCategoryIds);
        $rules = $this->personalization->activeRulesByProductIds($ids);

        $pricedInputs = [];
        foreach ($products as $product) {
            $productCategoryIds = [];
            foreach ($product->categories() as $category) {
                if ($category instanceof ProductCategory) {
                    $productCategoryIds[] = $category->id();
                }
            }
            $pricedInputs[$product->id()] = [
                'base' => $prices[$product->id()] ?? '0.00',
                'stock_qty' => null,
                'category_ids' => $productCategoryIds,
            ];
        }
        $pricedMap = $this->applyProductDiscountsMany($pricedInputs);

        $out = [];
        foreach ($products as $product) {
            $id = $product->id();
            $priced = $pricedMap[$id] ?? [
                'price' => number_format((float) ($prices[$id] ?? '0'), 2, '.', ''),
                'original_price' => null,
                'discount' => null,
            ];
            $perso = $this->personalization->serializeForStore($product, [
                'rule' => $rules[$id] ?? null,
            ]);
            $hasInheritedOptions = false;
            if ($product->inheritsCategoryOptions()) {
                foreach ($product->categories() as $category) {
                    if ($category instanceof ProductCategory && ($optionFlags['categories'][$category->id()] ?? false)) {
                        $hasInheritedOptions = true;
                        break;
                    }
                }
            }

            $out[] = [
                'id' => $id,
                'sku' => $product->sku(),
                'name' => $product->name(),
                'description' => $product->description() ?? '',
                'unit' => $product->unit(),
                'price' => [
                    'amount' => $priced['price'],
                    'currency_code' => $this->money->defaultCode(),
                ],
                'original_price' => $priced['original_price'] !== null
                    ? ['amount' => $priced['original_price'], 'currency_code' => $this->money->defaultCode()]
                    : null,
                'discount' => $priced['discount'],
                'image_url' => $images[$id] ?? null,
                'categories' => array_map(
                    static fn (ProductCategory $c): array => [
                        'id' => $c->id(),
                        'name' => $c->name(),
                        'slug' => $c->slug(),
                    ],
                    array_values(array_filter(
                        $product->categories()->toArray(),
                        static fn ($c): bool => $c instanceof ProductCategory,
                    )),
                ),
                'customer_note_mode' => $perso['customer_note_mode'],
                'has_user_images' => ($perso['user_image_rule'] ?? null) !== null,
                'has_variants' => ($variantFlags[$id] ?? false)
                    || ($optionFlags['products'][$id] ?? false)
                    || $hasInheritedOptions,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function serializeStore(Product $product, bool $detail = false): array
    {
        $basePrice = $this->currentPrice($product->id(), $this->money->defaultCode());
        $categoryIds = [];
        foreach ($product->categories() as $category) {
            if ($category instanceof ProductCategory) {
                $categoryIds[] = $category->id();
            }
        }
        $priced = $this->applyProductDiscount($product->id(), $basePrice, null, $categoryIds);
        $image = $this->primaryImageUrl($product->id());

        $row = [
            'id' => $product->id(),
            'sku' => $product->sku(),
            'name' => $product->name(),
            'description' => $product->description() ?? '',
            'unit' => $product->unit(),
            'price' => [
                'amount' => $priced['price'],
                'currency_code' => $this->money->defaultCode(),
            ],
            'original_price' => $priced['original_price'] !== null
                ? ['amount' => $priced['original_price'], 'currency_code' => $this->money->defaultCode()]
                : null,
            'discount' => $priced['discount'],
            'image_url' => $image,
            'categories' => array_map(
                static fn (ProductCategory $c): array => [
                    'id' => $c->id(),
                    'name' => $c->name(),
                    'slug' => $c->slug(),
                ],
                $product->categories()->toArray(),
            ),
        ];

        if ($detail) {
            $row['images'] = $this->allImageUrls($product->id());
            $row['option_groups'] = $this->variantOptions->serializeGroupsForProduct($product);
            $row['variants'] = $this->activeVariants($product);
            $row['inherit_category_options'] = $product->inheritsCategoryOptions();
            $row['base_price'] = $basePrice;
            $row = array_merge($row, $this->personalization->serializeForStore($product));
        } else {
            $perso = $this->personalization->serializeForStore($product);
            $row['customer_note_mode'] = $perso['customer_note_mode'];
            $row['has_user_images'] = ($perso['user_image_rule'] ?? null) !== null;
            $row['has_variants'] = $this->activeVariants($product) !== []
                || $this->variantOptions->serializeGroupsForProduct($product) !== [];
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function activeVariants(Product $product): array
    {
        $variants = $this->em->getRepository(ProductVariant::class)->findBy(
            ['productId' => $product->id(), 'active' => true],
            ['sku' => 'ASC'],
        );
        $productBase = $this->currentPrice($product->id(), $this->money->defaultCode());
        $out = [];
        foreach ($variants as $variant) {
            if (!$variant instanceof ProductVariant) {
                continue;
            }
            if ($variant->priceAmount() !== null) {
                $baseVariantPrice = number_format((float) $variant->priceAmount(), 2, '.', '');
            } else {
                $baseVariantPrice = $this->variantOptions->applyAttributePriceAdjust(
                    $product,
                    $variant->attributes(),
                    $productBase,
                );
            }
            $priced = $this->applyProductDiscount($product->id(), $baseVariantPrice, $variant->stockQty());
            $out[] = [
                'id' => $variant->id(),
                'sku' => $variant->sku(),
                'name' => $variant->name() ?: $variant->sku(),
                'attributes' => $variant->attributes(),
                'price' => [
                    'amount' => $priced['price'],
                    'currency_code' => $variant->priceCurrency() ?: $this->money->defaultCode(),
                    'original_amount' => $priced['original_price'],
                ],
                'stock_qty' => $variant->stockQty(),
            ];
        }

        return $out;
    }

    /**
     * @param list<string>|null $categoryIds
     * @return array{price: string, original_price: ?string, discount: ?array<string, mixed>}
     */
    private function applyProductDiscount(
        string $productId,
        string $basePrice,
        ?int $stockQty = null,
        ?array $categoryIds = null,
    ): array {
        if (
            $this->capabilities->has('product_discounts')
            && class_exists(\Modules\ProductDiscounts\Application\ProductDiscountsProvider::class)
        ) {
            /** @var \Modules\ProductDiscounts\Application\ProductDiscountsProvider $provider */
            $provider = $this->capabilities->get('product_discounts');

            return $provider->priceForProduct($productId, $basePrice, null, $stockQty, $categoryIds);
        }

        return [
            'price' => number_format((float) $basePrice, 2, '.', ''),
            'original_price' => null,
            'discount' => null,
        ];
    }

    /**
     * @param array<string, array{base: string|float, stock_qty?: ?int, category_ids?: list<string>|null}> $products
     * @return array<string, array{price: string, original_price: ?string, discount: ?array<string, mixed>}>
     */
    private function applyProductDiscountsMany(array $products): array
    {
        if (
            $this->capabilities->has('product_discounts')
            && class_exists(\Modules\ProductDiscounts\Application\ProductDiscountsProvider::class)
        ) {
            /** @var \Modules\ProductDiscounts\Application\ProductDiscountsProvider $provider */
            $provider = $this->capabilities->get('product_discounts');

            return $provider->pricesForProducts($products);
        }

        $out = [];
        foreach ($products as $productId => $meta) {
            $out[$productId] = [
                'price' => number_format((float) $meta['base'], 2, '.', ''),
                'original_price' => null,
                'discount' => null,
            ];
        }

        return $out;
    }

    private function currentPrice(string $productId, string $currency): string
    {
        return $this->currentPricesByProductIds([$productId], $currency)[$productId] ?? '0.00';
    }

    /**
     * @param list<string> $productIds
     * @return array<string, string>
     */
    private function currentPricesByProductIds(array $productIds, string $currency): array
    {
        $map = array_fill_keys($productIds, '0.00');
        if ($productIds === []) {
            return $map;
        }

        /** @var list<ProductPrice> $prices */
        $prices = $this->em->getRepository(ProductPrice::class)->createQueryBuilder('pp')
            ->where('pp.productId IN (:ids)')
            ->andWhere('pp.currencyCode = :currency')
            ->setParameter('ids', $productIds)
            ->setParameter('currency', $currency)
            ->orderBy('pp.validFrom', 'DESC')
            ->getQuery()
            ->getResult();

        $now = new \DateTimeImmutable('today');
        $seen = [];
        foreach ($prices as $price) {
            if (!$price instanceof ProductPrice || isset($seen[$price->productId()])) {
                continue;
            }
            if ($price->validFrom() !== null && $price->validFrom() > $now) {
                continue;
            }
            if ($price->validTo() !== null && $price->validTo() < $now) {
                continue;
            }
            $map[$price->productId()] = number_format((float) $price->amount(), 2, '.', '');
            $seen[$price->productId()] = true;
        }

        return $map;
    }

    private function primaryImageUrl(string $productId): ?string
    {
        return $this->primaryImageUrlsByProductIds([$productId])[$productId] ?? null;
    }

    /**
     * @param list<string> $productIds
     * @return array<string, string>
     */
    private function primaryImageUrlsByProductIds(array $productIds): array
    {
        $map = [];
        if ($productIds === []) {
            return $map;
        }

        /** @var list<ProductImage> $images */
        $images = $this->em->getRepository(ProductImage::class)->createQueryBuilder('i')
            ->where('i.productId IN (:ids)')
            ->setParameter('ids', $productIds)
            ->orderBy('i.isPrimary', 'DESC')
            ->addOrderBy('i.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($images as $image) {
            if (!$image instanceof ProductImage || isset($map[$image->productId()])) {
                continue;
            }
            $map[$image->productId()] = '/media/' . $image->mediaAssetId() . '/file';
        }

        return $map;
    }

    /**
     * @param list<string> $productIds
     * @return array<string, bool>
     */
    private function productIdsWithActiveVariants(array $productIds): array
    {
        $map = array_fill_keys($productIds, false);
        if ($productIds === []) {
            return $map;
        }

        $rows = $this->em->createQueryBuilder()
            ->select('DISTINCT v.productId AS productId')
            ->from(ProductVariant::class, 'v')
            ->where('v.productId IN (:ids)')
            ->andWhere('v.active = true')
            ->setParameter('ids', $productIds)
            ->getQuery()
            ->getScalarResult();

        foreach ($rows as $row) {
            $id = (string) ($row['productId'] ?? '');
            if ($id !== '') {
                $map[$id] = true;
            }
        }

        return $map;
    }

    /**
     * @param list<Product> $products
     * @return list<string>
     */
    private function categoryIdsFromProducts(array $products): array
    {
        $ids = [];
        foreach ($products as $product) {
            foreach ($product->categories() as $category) {
                if ($category instanceof ProductCategory) {
                    $ids[$category->id()] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /** @param list<string> $productIds */
    private function warmCategories(array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        $this->em->createQueryBuilder()
            ->select('p', 'c')
            ->from(Product::class, 'p')
            ->leftJoin('p.categories', 'c')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $productIds)
            ->getQuery()
            ->getResult();
    }

    /** @return list<array{url: string, alt: ?string, is_primary: bool}> */
    private function allImageUrls(string $productId): array
    {
        $images = $this->em->getRepository(ProductImage::class)->findBy(
            ['productId' => $productId],
            ['isPrimary' => 'DESC', 'sortOrder' => 'ASC'],
        );
        $out = [];
        foreach ($images as $image) {
            $out[] = [
                'url' => '/media/' . $image->mediaAssetId() . '/file',
                'alt' => $image->alt(),
                'is_primary' => $image->isPrimary(),
            ];
        }

        return $out;
    }

    private function cors(Response $response, string $methods = 'GET, OPTIONS'): Response
    {
        return PublicCors::apply($response, $this->request(), $methods);
    }
}
