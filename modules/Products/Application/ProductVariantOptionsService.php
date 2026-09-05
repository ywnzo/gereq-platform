<?php

declare(strict_types=1);

namespace Modules\Products\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Products\Domain\Product;
use Modules\Products\Domain\ProductCategory;
use Modules\Products\Domain\ProductOptionGroup;
use Modules\Products\Domain\ProductOptionValue;
use Modules\Products\Domain\ProductVariant;

final class ProductVariantOptionsService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return list<array{
     *   group: ProductOptionGroup,
     *   values: list<ProductOptionValue>,
     *   options_text: string,
     *   inherited: bool
     * }>
     */
    public function groupsForScope(string $scopeType, string $scopeId): array
    {
        return $this->groupsForScopes($scopeType, [$scopeId])[$scopeId] ?? [];
    }

    /**
     * @param list<string> $scopeIds
     * @return array<string, list<array{group: ProductOptionGroup, values: list<ProductOptionValue>, options_text: string, inherited: bool}>>
     */
    public function groupsForScopes(string $scopeType, array $scopeIds): array
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

        /** @var list<ProductOptionGroup> $groups */
        $groups = $this->em->getRepository(ProductOptionGroup::class)->createQueryBuilder('g')
            ->where('g.scopeType = :type')
            ->andWhere('g.scopeId IN (:ids)')
            ->setParameter('type', $scopeType)
            ->setParameter('ids', $scopeIds)
            ->orderBy('g.sortOrder', 'ASC')
            ->addOrderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();

        $hydrated = $this->hydrateGroups($groups, inherited: false);
        foreach ($hydrated as $row) {
            $map[$row['group']->scopeId()][] = $row;
        }

        return $map;
    }

    /**
     * @param list<string> $productIds
     * @return array<string, bool>
     */
    public function productIdsWithOptionGroups(array $productIds): array
    {
        $flags = $this->scopeIdsWithOptionGroups(ProductOptionGroup::SCOPE_PRODUCT, $productIds);

        return $flags;
    }

    /**
     * @param list<string> $scopeIds
     * @return array<string, bool>
     */
    public function scopeIdsWithOptionGroups(string $scopeType, array $scopeIds): array
    {
        $scopeIds = array_values(array_unique(array_filter(
            $scopeIds,
            static fn (string $id): bool => $id !== '',
        )));
        $map = array_fill_keys($scopeIds, false);
        if ($scopeIds === []) {
            return $map;
        }

        $rows = $this->em->createQueryBuilder()
            ->select('DISTINCT g.scopeId AS scopeId')
            ->from(ProductOptionGroup::class, 'g')
            ->where('g.scopeType = :type')
            ->andWhere('g.scopeId IN (:ids)')
            ->setParameter('type', $scopeType)
            ->setParameter('ids', $scopeIds)
            ->getQuery()
            ->getScalarResult();

        foreach ($rows as $row) {
            $id = (string) ($row['scopeId'] ?? '');
            if ($id !== '') {
                $map[$id] = true;
            }
        }

        return $map;
    }

    /**
     * @param list<string> $productIds
     * @param list<string> $categoryIds
     * @return array{products: array<string, bool>, categories: array<string, bool>}
     */
    public function optionGroupFlags(array $productIds, array $categoryIds): array
    {
        $productIds = array_values(array_unique(array_filter(
            $productIds,
            static fn (string $id): bool => $id !== '',
        )));
        $categoryIds = array_values(array_unique(array_filter(
            $categoryIds,
            static fn (string $id): bool => $id !== '',
        )));
        $products = array_fill_keys($productIds, false);
        $categories = array_fill_keys($categoryIds, false);
        if ($productIds === [] && $categoryIds === []) {
            return ['products' => $products, 'categories' => $categories];
        }

        $qb = $this->em->createQueryBuilder()
            ->select('g.scopeType AS scopeType', 'g.scopeId AS scopeId')
            ->distinct()
            ->from(ProductOptionGroup::class, 'g');

        $parts = [];
        if ($productIds !== []) {
            $parts[] = '(g.scopeType = :productType AND g.scopeId IN (:productIds))';
            $qb->setParameter('productType', ProductOptionGroup::SCOPE_PRODUCT)
                ->setParameter('productIds', $productIds);
        }
        if ($categoryIds !== []) {
            $parts[] = '(g.scopeType = :categoryType AND g.scopeId IN (:categoryIds))';
            $qb->setParameter('categoryType', ProductOptionGroup::SCOPE_CATEGORY)
                ->setParameter('categoryIds', $categoryIds);
        }
        $qb->where(implode(' OR ', $parts));

        foreach ($qb->getQuery()->getScalarResult() as $row) {
            $type = (string) ($row['scopeType'] ?? '');
            $id = (string) ($row['scopeId'] ?? '');
            if ($type === ProductOptionGroup::SCOPE_PRODUCT && isset($products[$id])) {
                $products[$id] = true;
            } elseif ($type === ProductOptionGroup::SCOPE_CATEGORY && isset($categories[$id])) {
                $categories[$id] = true;
            }
        }

        return ['products' => $products, 'categories' => $categories];
    }

    /**
     * Product-owned groups only (not inherited).
     *
     * @return list<array{group: ProductOptionGroup, values: list<ProductOptionValue>, options_text: string, inherited: bool}>
     */
    public function groupsWithValues(string $productId): array
    {
        return $this->groupsForScope(ProductOptionGroup::SCOPE_PRODUCT, $productId);
    }

    /**
     * Effective groups for storefront + SKU generation (category first if inheriting, then product).
     *
     * @return list<array{group: ProductOptionGroup, values: list<ProductOptionValue>, options_text: string, inherited: bool}>
     */
    public function effectiveGroupsForProduct(Product $product): array
    {
        $out = [];
        $seenCodes = [];

        if ($product->inheritsCategoryOptions()) {
            $category = $this->primaryCategory($product);
            if ($category instanceof ProductCategory) {
                foreach ($this->groupsForScope(ProductOptionGroup::SCOPE_CATEGORY, $category->id()) as $row) {
                    $code = $row['group']->code();
                    if (isset($seenCodes[$code])) {
                        continue;
                    }
                    $seenCodes[$code] = true;
                    $row['inherited'] = true;
                    $out[] = $row;
                }
            }
        }

        foreach ($this->groupsWithValues($product->id()) as $row) {
            $code = $row['group']->code();
            if (isset($seenCodes[$code])) {
                // Product-level group with same code overrides category definition.
                $out = array_values(array_filter(
                    $out,
                    static fn (array $existing): bool => $existing['group']->code() !== $code,
                ));
            }
            $seenCodes[$code] = true;
            $row['inherited'] = false;
            $out[] = $row;
        }

        return $out;
    }

    public function primaryCategory(Product $product): ?ProductCategory
    {
        foreach ($product->categories() as $category) {
            if ($category instanceof ProductCategory) {
                return $category;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function serializeGroupsForProduct(Product $product): array
    {
        return $this->serializeRows($this->effectiveGroupsForProduct($product));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function serializeGroupsForCategory(string $categoryId): array
    {
        return $this->serializeRows($this->groupsForScope(ProductOptionGroup::SCOPE_CATEGORY, $categoryId));
    }

    /** @deprecated use serializeGroupsForProduct */
    public function serializeGroups(string $productId): array
    {
        $product = $this->em->find(Product::class, $productId);
        if ($product instanceof Product) {
            return $this->serializeGroupsForProduct($product);
        }

        return $this->serializeRows($this->groupsWithValues($productId));
    }

    public function createGroup(
        string $scopeType,
        string $scopeId,
        string $name,
        string $optionsText,
        bool $required = true,
    ): ProductOptionGroup {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('products.error.option_group_name');
        }
        $code = $this->uniqueGroupCode($scopeType, $scopeId, $this->codeFromLabel($name));
        $sort = (int) $this->em->createQueryBuilder()
            ->select('MAX(g.sortOrder)')
            ->from(ProductOptionGroup::class, 'g')
            ->andWhere('g.scopeType = :type')
            ->andWhere('g.scopeId = :sid')
            ->setParameter('type', $scopeType)
            ->setParameter('sid', $scopeId)
            ->getQuery()
            ->getSingleScalarResult();

        $group = new ProductOptionGroup($scopeType, $scopeId, $code, $name);
        $group->setSortOrder($sort + 1);
        $group->setRequired($required);
        $this->em->persist($group);
        $this->em->flush();
        $this->syncValues($group, $optionsText);
        $this->em->flush();

        return $group;
    }

    public function updateGroup(ProductOptionGroup $group, string $name, string $optionsText, bool $required = true): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('products.error.option_group_name');
        }
        $group->setName($name);
        $group->setRequired($required);
        $this->syncValues($group, $optionsText);
        $this->em->flush();
    }

    public function deleteGroup(ProductOptionGroup $group): void
    {
        $values = $this->em->getRepository(ProductOptionValue::class)->findBy(['groupId' => $group->id()]);
        foreach ($values as $value) {
            $this->em->remove($value);
        }
        $this->em->remove($group);
        $this->em->flush();
    }

    public function syncValues(ProductOptionGroup $group, string $optionsText): void
    {
        $lines = preg_split('/\R/u', $optionsText) ?: [];
        /** @var array<string, array{label: string, kind: string, value: string}> $wanted */
        $wanted = [];
        foreach ($lines as $line) {
            $parsed = $this->parseOptionLine((string) $line);
            if ($parsed === null) {
                continue;
            }
            $code = $this->codeFromLabel($parsed['label']);
            if ($code === '') {
                continue;
            }
            if (!isset($wanted[$code])) {
                $wanted[$code] = $parsed;
            }
        }

        $existing = $this->em->getRepository(ProductOptionValue::class)->findBy(['groupId' => $group->id()]);
        /** @var array<string, ProductOptionValue> $byCode */
        $byCode = [];
        foreach ($existing as $value) {
            if ($value instanceof ProductOptionValue) {
                $byCode[$value->code()] = $value;
            }
        }

        $order = 0;
        foreach ($wanted as $code => $parsed) {
            if (isset($byCode[$code])) {
                $byCode[$code]->setName($parsed['label']);
                $byCode[$code]->setPriceAdjust($parsed['kind'], $parsed['value']);
                $byCode[$code]->setSortOrder($order);
                unset($byCode[$code]);
            } else {
                $value = new ProductOptionValue($group->id(), $code, $parsed['label']);
                $value->setPriceAdjust($parsed['kind'], $parsed['value']);
                $value->setSortOrder($order);
                $this->em->persist($value);
            }
            $order++;
        }

        foreach ($byCode as $orphan) {
            $this->em->remove($orphan);
        }
    }

    /**
     * Parse "Dub|+500", "XL|-10%", "Bílá" → label + optional price adjust.
     *
     * @return array{label: string, kind: string, value: string}|null
     */
    public function parseOptionLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }
        if (preg_match('/^(.*?)\s*\|\s*([+-]?\d+(?:[.,]\d+)?)\s*(%|Kč|kc|CZK)?\s*$/iu', $line, $m)) {
            $label = trim($m[1]);
            if ($label === '') {
                return null;
            }
            $raw = str_replace(',', '.', $m[2]);
            $suffix = strtolower(trim((string) ($m[3] ?? '')));
            $kind = $suffix === '%' ? ProductOptionValue::ADJUST_PERCENT : ProductOptionValue::ADJUST_AMOUNT;

            return [
                'label' => $label,
                'kind' => $kind,
                'value' => number_format((float) $raw, 2, '.', ''),
            ];
        }

        return [
            'label' => $line,
            'kind' => ProductOptionValue::ADJUST_NONE,
            'value' => '0.00',
        ];
    }

    public function formatOptionLine(ProductOptionValue $value): string
    {
        $line = $value->name();
        if (!$value->hasPriceAdjust()) {
            return $line;
        }
        $num = (float) $value->priceAdjustValue();
        $formatted = rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
        if ($num > 0) {
            $formatted = '+' . $formatted;
        }
        if ($value->priceAdjustKind() === ProductOptionValue::ADJUST_PERCENT) {
            return $line . '|' . $formatted . '%';
        }

        return $line . '|' . $formatted;
    }

    /**
     * Apply option value price modifiers for selected attributes onto a base price.
     *
     * @param array<string, string> $attributes group_code => value_code
     */
    public function applyAttributePriceAdjust(Product $product, array $attributes, string|float $basePrice): string
    {
        $price = (float) $basePrice;
        if ($attributes === []) {
            return number_format($price, 2, '.', '');
        }

        $rows = $this->effectiveGroupsForProduct($product);
        /** @var array<string, array<string, ProductOptionValue>> $byGroup */
        $byGroup = [];
        foreach ($rows as $row) {
            $gCode = $row['group']->code();
            $byGroup[$gCode] = [];
            foreach ($row['values'] as $value) {
                $byGroup[$gCode][$value->code()] = $value;
            }
        }

        foreach ($attributes as $groupCode => $valueCode) {
            $value = $byGroup[(string) $groupCode][(string) $valueCode] ?? null;
            if ($value instanceof ProductOptionValue) {
                $price = $value->applyToPrice($price);
            }
        }

        return number_format($price, 2, '.', '');
    }

    /**
     * Creates missing cartesian SKUs and removes variants whose attributes
     * no longer match the current option groups (e.g. old 1-axis rows after a 2nd field was added).
     *
     * @return array{created: int, removed: int}
     */
    public function generateMissingVariants(Product $product): array
    {
        $combos = $this->cartesianCombinationsForProduct($product);
        if ($combos === []) {
            return ['created' => 0, 'removed' => 0];
        }

        $wantedKeys = [];
        foreach ($combos as $combo) {
            $wantedKeys[$this->attributesKey($combo['attributes'])] = $combo;
        }

        $existing = $this->em->getRepository(ProductVariant::class)->findBy(['productId' => $product->id()]);
        $existingKeys = [];
        $removed = 0;
        foreach ($existing as $variant) {
            if (!$variant instanceof ProductVariant) {
                continue;
            }
            $key = $this->attributesKey($variant->attributes());
            // Keep manually created blank variants; prune only attribute rows that are obsolete.
            if ($variant->attributes() !== [] && !isset($wantedKeys[$key])) {
                $this->em->remove($variant);
                $removed++;
                continue;
            }
            $existingKeys[$key] = true;
        }

        $created = 0;
        foreach ($wantedKeys as $key => $combo) {
            if (isset($existingKeys[$key])) {
                continue;
            }
            $suffix = implode('-', array_map('strtoupper', array_values($combo['attributes'])));
            $sku = $this->uniqueVariantSku($product->sku() . '-' . $suffix);
            $variant = new ProductVariant($product->id(), $sku);
            $variant->setName(implode(' + ', $combo['labels']));
            $variant->setAttributes($combo['attributes']);
            $variant->setActive(true);
            $this->em->persist($variant);
            $existingKeys[$key] = true;
            $created++;
        }

        if ($created > 0 || $removed > 0) {
            $this->em->flush();
        }

        return ['created' => $created, 'removed' => $removed];
    }

    /**
     * @return list<array{attributes: array<string, string>, labels: list<string>}>
     */
    public function cartesianCombinationsForProduct(Product $product): array
    {
        return $this->cartesianFromRows($this->effectiveGroupsForProduct($product));
    }

    public function codeFromLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return '';
        }
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
        $ascii = is_string($ascii) ? $ascii : $label;
        $code = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $ascii));
        $code = trim($code, '-');

        return $code !== '' ? substr($code, 0, 64) : 'opt';
    }

    /**
     * @param list<ProductOptionGroup> $groups
     * @return list<array{group: ProductOptionGroup, values: list<ProductOptionValue>, options_text: string, inherited: bool}>
     */
    private function hydrateGroups(array $groups, bool $inherited): array
    {
        $groupIds = [];
        foreach ($groups as $group) {
            if ($group instanceof ProductOptionGroup) {
                $groupIds[] = $group->id();
            }
        }

        /** @var array<string, list<ProductOptionValue>> $valuesByGroup */
        $valuesByGroup = array_fill_keys($groupIds, []);
        if ($groupIds !== []) {
            /** @var list<ProductOptionValue> $values */
            $values = $this->em->getRepository(ProductOptionValue::class)->createQueryBuilder('v')
                ->where('v.groupId IN (:ids)')
                ->setParameter('ids', $groupIds)
                ->orderBy('v.sortOrder', 'ASC')
                ->addOrderBy('v.name', 'ASC')
                ->getQuery()
                ->getResult();
            foreach ($values as $value) {
                if ($value instanceof ProductOptionValue) {
                    $valuesByGroup[$value->groupId()][] = $value;
                }
            }
        }

        $out = [];
        foreach ($groups as $group) {
            if (!$group instanceof ProductOptionGroup) {
                continue;
            }
            $values = $valuesByGroup[$group->id()] ?? [];
            $out[] = [
                'group' => $group,
                'values' => $values,
                'options_text' => implode("\n", array_map(
                    fn (ProductOptionValue $v): string => $this->formatOptionLine($v),
                    $values,
                )),
                'inherited' => $inherited,
            ];
        }

        return $out;
    }

    /**
     * @param list<array{group: ProductOptionGroup, values: list<ProductOptionValue>, options_text: string, inherited: bool}> $rows
     * @return list<array<string, mixed>>
     */
    private function serializeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $group = $row['group'];
            $out[] = [
                'id' => $group->id(),
                'code' => $group->code(),
                'name' => $group->name(),
                'required' => $group->isRequired(),
                'sort_order' => $group->sortOrder(),
                'inherited' => $row['inherited'],
                'scope' => $group->scopeType(),
                'values' => array_map(
                    static fn (ProductOptionValue $v): array => [
                        'id' => $v->id(),
                        'code' => $v->code(),
                        'name' => $v->name(),
                        'sort_order' => $v->sortOrder(),
                        'price_adjust_kind' => $v->priceAdjustKind(),
                        'price_adjust_value' => $v->priceAdjustValue(),
                    ],
                    $row['values'],
                ),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{group: ProductOptionGroup, values: list<ProductOptionValue>, options_text: string, inherited: bool}> $groups
     * @return list<array{attributes: array<string, string>, labels: list<string>}>
     */
    private function cartesianFromRows(array $groups): array
    {
        if ($groups === []) {
            return [];
        }

        $sets = [];
        foreach ($groups as $row) {
            if ($row['values'] === []) {
                return [];
            }
            $sets[] = array_map(
                static fn (ProductOptionValue $v): array => [
                    'group' => $row['group']->code(),
                    'code' => $v->code(),
                    'label' => $v->name(),
                ],
                $row['values'],
            );
        }

        $combos = [[]];
        foreach ($sets as $set) {
            $next = [];
            foreach ($combos as $prefix) {
                foreach ($set as $item) {
                    $next[] = array_merge($prefix, [$item]);
                }
            }
            $combos = $next;
        }

        $out = [];
        foreach ($combos as $combo) {
            $attributes = [];
            $labels = [];
            foreach ($combo as $item) {
                $attributes[$item['group']] = $item['code'];
                $labels[] = $item['label'];
            }
            ksort($attributes);
            $out[] = ['attributes' => $attributes, 'labels' => $labels];
        }

        return $out;
    }

    private function uniqueGroupCode(string $scopeType, string $scopeId, string $base): string
    {
        $code = $base !== '' ? $base : 'field';
        $i = 2;
        while ($this->em->getRepository(ProductOptionGroup::class)->findOneBy([
            'scopeType' => $scopeType,
            'scopeId' => $scopeId,
            'code' => $code,
        ])) {
            $code = substr($base, 0, 60) . '-' . $i;
            $i++;
        }

        return $code;
    }

    private function uniqueVariantSku(string $base): string
    {
        $sku = strtoupper(substr($base, 0, 64));
        $i = 2;
        while ($this->em->getRepository(ProductVariant::class)->findOneBy(['sku' => $sku])) {
            $suffix = '-' . $i;
            $sku = strtoupper(substr($base, 0, 64 - strlen($suffix)) . $suffix);
            $i++;
        }

        return $sku;
    }

    /** @param array<string, string> $attributes */
    private function attributesKey(array $attributes): string
    {
        ksort($attributes);

        return json_encode($attributes, JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
