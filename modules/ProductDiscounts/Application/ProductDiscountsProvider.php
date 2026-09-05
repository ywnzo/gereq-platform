<?php

declare(strict_types=1);

namespace Modules\ProductDiscounts\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\ProductDiscounts\Domain\ProductDiscount;
use Modules\Products\Domain\Product;

final class ProductDiscountsProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** Primary unconditional product discount (legacy single-slot UI / API). */
    public function findForProduct(string $productId): ?ProductDiscount
    {
        $items = $this->listForScope(ProductDiscount::SCOPE_PRODUCT, $productId);
        foreach ($items as $discount) {
            if ($discount->conditionType() === ProductDiscount::CONDITION_NONE) {
                return $discount;
            }
        }

        return $items[0] ?? null;
    }

    /** @return list<ProductDiscount> */
    public function listForProduct(string $productId): array
    {
        return $this->listForScope(ProductDiscount::SCOPE_PRODUCT, $productId);
    }

    /** @return list<ProductDiscount> */
    public function listForCategory(string $categoryId): array
    {
        return $this->listForScope(ProductDiscount::SCOPE_CATEGORY, $categoryId);
    }

    /** @return list<ProductDiscount> */
    public function listForScope(string $scopeType, string $scopeId): array
    {
        return $this->listForScopes($scopeType, [$scopeId])[$scopeId] ?? [];
    }

    /**
     * @param list<string> $scopeIds
     * @return array<string, list<ProductDiscount>>
     */
    public function listForScopes(string $scopeType, array $scopeIds): array
    {
        $scopeIds = array_values(array_unique(array_filter(
            $scopeIds,
            static fn (string $id): bool => $id !== '',
        )));
        $map = [];
        foreach ($scopeIds as $id) {
            $map[$id] = [];
        }
        if ($scopeIds === []) {
            return $map;
        }

        /** @var list<ProductDiscount> $items */
        $items = $this->em->getRepository(ProductDiscount::class)->createQueryBuilder('d')
            ->where('d.scopeType = :type')
            ->andWhere('d.scopeId IN (:ids)')
            ->setParameter('type', $scopeType)
            ->setParameter('ids', $scopeIds)
            ->orderBy('d.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        foreach ($items as $discount) {
            if ($discount instanceof ProductDiscount) {
                $map[$discount->scopeId()][] = $discount;
            }
        }

        return $map;
    }

    public function findActiveForProduct(string $productId, ?\DateTimeImmutable $now = null): ?ProductDiscount
    {
        $discount = $this->findForProduct($productId);
        if ($discount === null || !$discount->isCurrentlyActive($now)) {
            return null;
        }

        return $discount;
    }

    /**
     * @param list<string>|null $categoryIds
     * @return array{price: string, original_price: ?string, discount: ?array<string, mixed>}
     */
    public function priceForProduct(
        string $productId,
        string|float $basePrice,
        ?\DateTimeImmutable $now = null,
        ?int $stockQty = null,
        ?array $categoryIds = null,
    ): array {
        return $this->pricesForProducts([
            $productId => [
                'base' => $basePrice,
                'stock_qty' => $stockQty,
                'category_ids' => $categoryIds,
            ],
        ], $now)[$productId];
    }

    /**
     * @param array<string, array{base: string|float, stock_qty?: ?int, category_ids?: list<string>|null}> $products
     * @return array<string, array{price: string, original_price: ?string, discount: ?array<string, mixed>}>
     */
    public function pricesForProducts(array $products, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $productIds = array_keys($products);
        $productDiscounts = $this->listForScopes(ProductDiscount::SCOPE_PRODUCT, $productIds);

        $categoryIds = [];
        $categoryIdsByProduct = [];
        foreach ($products as $productId => $meta) {
            $ids = $meta['category_ids'] ?? null;
            if ($ids === null) {
                $ids = $this->categoryIdsForProduct($productId);
            }
            $categoryIdsByProduct[$productId] = $ids;
            foreach ($ids as $categoryId) {
                $categoryIds[$categoryId] = true;
            }
        }
        $categoryDiscounts = $this->listForScopes(
            ProductDiscount::SCOPE_CATEGORY,
            array_keys($categoryIds),
        );

        $out = [];
        foreach ($products as $productId => $meta) {
            $base = number_format((float) $meta['base'], 2, '.', '');
            $stockQty = $meta['stock_qty'] ?? null;
            $discount = $this->resolveBestDiscountFromMaps(
                $productDiscounts[$productId] ?? [],
                $categoryIdsByProduct[$productId] ?? [],
                $categoryDiscounts,
                $now,
                $stockQty,
            );
            if ($discount === null) {
                $out[$productId] = [
                    'price' => $base,
                    'original_price' => null,
                    'discount' => null,
                ];
                continue;
            }
            $priced = $this->applyCatalogDiscount($base, $discount->kind(), (float) $discount->value());
            $out[$productId] = [
                'price' => $priced['price'],
                'original_price' => $priced['original_price'],
                'discount' => $this->serialize($discount),
            ];
        }

        return $out;
    }

    /**
     * Product-scoped discounts win over category. Among matches, pick max savings.
     *
     * @param list<string>|null $categoryIds
     */
    public function resolveBestDiscount(
        string $productId,
        ?\DateTimeImmutable $now = null,
        ?int $stockQty = null,
        ?array $categoryIds = null,
    ): ?ProductDiscount {
        $now ??= new \DateTimeImmutable();
        $productMatches = $this->matchingDiscounts(
            $this->listForProduct($productId),
            $now,
            $stockQty,
        );
        if ($productMatches !== []) {
            return $this->pickBest($productMatches, 100.0);
        }

        $categoryIds ??= $this->categoryIdsForProduct($productId);
        $categoryMatches = [];
        foreach ($categoryIds as $categoryId) {
            foreach ($this->matchingDiscounts($this->listForCategory($categoryId), $now, $stockQty) as $d) {
                $categoryMatches[] = $d;
            }
        }

        return $categoryMatches !== [] ? $this->pickBest($categoryMatches, 100.0) : null;
    }

    /**
     * @param list<ProductDiscount> $productDiscounts
     * @param list<string> $categoryIds
     * @param array<string, list<ProductDiscount>> $categoryDiscounts
     */
    private function resolveBestDiscountFromMaps(
        array $productDiscounts,
        array $categoryIds,
        array $categoryDiscounts,
        \DateTimeImmutable $now,
        ?int $stockQty,
    ): ?ProductDiscount {
        $productMatches = $this->matchingDiscounts($productDiscounts, $now, $stockQty);
        if ($productMatches !== []) {
            return $this->pickBest($productMatches, 100.0);
        }

        $categoryMatches = [];
        foreach ($categoryIds as $categoryId) {
            foreach ($this->matchingDiscounts($categoryDiscounts[$categoryId] ?? [], $now, $stockQty) as $d) {
                $categoryMatches[] = $d;
            }
        }

        return $categoryMatches !== [] ? $this->pickBest($categoryMatches, 100.0) : null;
    }

    /**
     * @param list<ProductDiscount> $discounts
     * @return list<ProductDiscount>
     */
    private function matchingDiscounts(array $discounts, \DateTimeImmutable $now, ?int $stockQty): array
    {
        $out = [];
        foreach ($discounts as $discount) {
            if (!$discount->isCurrentlyActive($now)) {
                continue;
            }
            if (!$discount->matchesStockCondition($stockQty)) {
                continue;
            }
            $out[] = $discount;
        }

        return $out;
    }

    /**
     * @param list<ProductDiscount> $discounts
     */
    private function pickBest(array $discounts, float $probeBase): ?ProductDiscount
    {
        $best = null;
        $bestSave = -1.0;
        foreach ($discounts as $discount) {
            $priced = $this->applyCatalogDiscount($probeBase, $discount->kind(), (float) $discount->value());
            $save = $probeBase - (float) $priced['price'];
            if ($save > $bestSave) {
                $bestSave = $save;
                $best = $discount;
            }
        }

        return $best;
    }

    /** @return list<string> */
    private function categoryIdsForProduct(string $productId): array
    {
        $product = $this->em->find(Product::class, $productId);
        if (!$product instanceof Product) {
            return [];
        }
        $ids = [];
        foreach ($product->categories() as $category) {
            $ids[] = $category->id();
        }

        return $ids;
    }

    /**
     * @return array{price: string, original_price: ?string}
     */
    public function applyCatalogDiscount(string|float $basePrice, ?string $kind, ?float $value): array
    {
        $base = (float) $basePrice;
        $effective = $base;
        if ($kind !== null && $value !== null) {
            if ($kind === 'percent') {
                $effective = max(0, round($base * (1 - $value / 100) * 100) / 100);
            } elseif ($kind === 'amount') {
                $effective = max(0, round(($base - $value) * 100) / 100);
            }
        }

        if ($kind !== null && abs($effective - $base) > 0.00001) {
            return [
                'price' => number_format($effective, 2, '.', ''),
                'original_price' => number_format($base, 2, '.', ''),
            ];
        }

        return [
            'price' => number_format($base, 2, '.', ''),
            'original_price' => null,
        ];
    }

    /**
     * @param array{
     *   kind?: string,
     *   value?: string|float|int,
     *   description?: ?string,
     *   condition_type?: string,
     *   condition_value?: ?int,
     *   starts_at?: ?\DateTimeImmutable,
     *   ends_at?: ?\DateTimeImmutable,
     *   active?: bool
     * } $data
     */
    public function create(string $scopeType, string $scopeId, array $data): ProductDiscount
    {
        $discount = new ProductDiscount(
            $scopeType,
            $scopeId,
            (string) ($data['kind'] ?? 'percent'),
            (string) ($data['value'] ?? '0'),
        );
        $this->applyData($discount, $data);
        $this->em->persist($discount);
        $this->em->flush();

        return $discount;
    }

    /**
     * Upsert primary (condition none) product discount — keeps product panel / API compatible.
     *
     * @param array{
     *   kind?: string,
     *   value?: string|float|int,
     *   description?: ?string,
     *   condition_type?: string,
     *   condition_value?: ?int,
     *   starts_at?: ?\DateTimeImmutable,
     *   ends_at?: ?\DateTimeImmutable,
     *   active?: bool
     * } $data
     */
    public function upsert(string $productId, array $data): ProductDiscount
    {
        $conditionType = strtolower(trim((string) ($data['condition_type'] ?? ProductDiscount::CONDITION_NONE)));
        if ($conditionType !== ProductDiscount::CONDITION_NONE && $conditionType !== '') {
            return $this->create(ProductDiscount::SCOPE_PRODUCT, $productId, $data);
        }

        $discount = null;
        foreach ($this->listForProduct($productId) as $existing) {
            if ($existing->conditionType() === ProductDiscount::CONDITION_NONE) {
                $discount = $existing;
                break;
            }
        }

        if ($discount === null) {
            $discount = ProductDiscount::forProduct(
                $productId,
                (string) ($data['kind'] ?? 'percent'),
                (string) ($data['value'] ?? '0'),
            );
            $this->em->persist($discount);
        }

        $this->applyData($discount, $data);
        $this->em->flush();

        return $discount;
    }

    /**
     * @param array{
     *   kind?: string,
     *   value?: string|float|int,
     *   description?: ?string,
     *   condition_type?: string,
     *   condition_value?: ?int,
     *   starts_at?: ?\DateTimeImmutable,
     *   ends_at?: ?\DateTimeImmutable,
     *   active?: bool
     * } $data
     */
    public function update(ProductDiscount $discount, array $data): ProductDiscount
    {
        $this->applyData($discount, $data);
        $this->em->flush();

        return $discount;
    }

    /**
     * @param array{
     *   kind?: string,
     *   value?: string|float|int,
     *   description?: ?string,
     *   condition_type?: string,
     *   condition_value?: ?int,
     *   starts_at?: ?\DateTimeImmutable,
     *   ends_at?: ?\DateTimeImmutable,
     *   active?: bool
     * } $data
     */
    private function applyData(ProductDiscount $discount, array $data): void
    {
        if (isset($data['kind'])) {
            $discount->setKind((string) $data['kind']);
        }
        if (array_key_exists('value', $data)) {
            $discount->setValue((string) $data['value']);
        }
        if (array_key_exists('description', $data)) {
            $discount->setDescription($data['description'] !== null ? (string) $data['description'] : null);
        }
        if (array_key_exists('condition_type', $data) || array_key_exists('condition_value', $data)) {
            $type = (string) ($data['condition_type'] ?? $discount->conditionType());
            $value = array_key_exists('condition_value', $data)
                ? ($data['condition_value'] !== null && $data['condition_value'] !== '' ? (int) $data['condition_value'] : null)
                : $discount->conditionValue();
            $discount->setCondition($type, $value);
        }
        if (array_key_exists('starts_at', $data)) {
            $discount->setStartsAt($data['starts_at'] instanceof \DateTimeImmutable ? $data['starts_at'] : null);
        }
        if (array_key_exists('ends_at', $data)) {
            $discount->setEndsAt($data['ends_at'] instanceof \DateTimeImmutable ? $data['ends_at'] : null);
        }
        if (array_key_exists('active', $data)) {
            $discount->setActive((bool) $data['active']);
        }
    }

    public function delete(ProductDiscount $discount): void
    {
        $this->em->remove($discount);
        $this->em->flush();
    }

    public function deleteForProduct(string $productId): bool
    {
        $items = $this->listForProduct($productId);
        if ($items === []) {
            return false;
        }
        foreach ($items as $discount) {
            $this->em->remove($discount);
        }
        $this->em->flush();

        return true;
    }

    /** @return array<string, mixed> */
    public function serialize(?ProductDiscount $discount): ?array
    {
        if ($discount === null) {
            return null;
        }

        return [
            'id' => $discount->id(),
            'product_id' => $discount->productId(),
            'scope_type' => $discount->scopeType(),
            'scope_id' => $discount->scopeId(),
            'kind' => $discount->kind(),
            'value' => $discount->value(),
            'description' => $discount->description(),
            'condition_type' => $discount->conditionType(),
            'condition_value' => $discount->conditionValue(),
            'starts_at' => $discount->startsAt()?->format(\DateTimeInterface::ATOM),
            'ends_at' => $discount->endsAt()?->format(\DateTimeInterface::ATOM),
            'active' => $discount->isActive(),
            'currently_active' => $discount->isCurrentlyActive(),
        ];
    }
}
