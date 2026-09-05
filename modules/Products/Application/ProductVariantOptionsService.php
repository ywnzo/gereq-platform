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
        $groups = $this->em->getRepository(ProductOptionGroup::class)->findBy(
            ['scopeType' => $scopeType, 'scopeId' => $scopeId],
            ['sortOrder' => 'ASC', 'name' => 'ASC'],
        );

        return $this->hydrateGroups($groups, inherited: false);
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
        $wanted = [];
        foreach ($lines as $line) {
            $label = trim((string) $line);
            if ($label === '') {
                continue;
            }
            $code = $this->codeFromLabel($label);
            if ($code === '') {
                continue;
            }
            if (!isset($wanted[$code])) {
                $wanted[$code] = $label;
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
        foreach ($wanted as $code => $label) {
            if (isset($byCode[$code])) {
                $byCode[$code]->setName($label);
                $byCode[$code]->setSortOrder($order);
                unset($byCode[$code]);
            } else {
                $value = new ProductOptionValue($group->id(), $code, $label);
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
        $out = [];
        foreach ($groups as $group) {
            if (!$group instanceof ProductOptionGroup) {
                continue;
            }
            $values = $this->em->getRepository(ProductOptionValue::class)->findBy(
                ['groupId' => $group->id()],
                ['sortOrder' => 'ASC', 'name' => 'ASC'],
            );
            /** @var list<ProductOptionValue> $values */
            $out[] = [
                'group' => $group,
                'values' => $values,
                'options_text' => implode("\n", array_map(
                    static fn (ProductOptionValue $v): string => $v->name(),
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
