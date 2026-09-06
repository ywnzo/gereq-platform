<?php

declare(strict_types=1);

namespace Modules\Products\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Currency\Application\CurrencyBuilder;
use Modules\Products\Domain\Product;
use Modules\Products\Domain\ProductOptionGroup;
use Modules\Products\Domain\ProductOptionValue;
use Modules\Products\Domain\ProductVariant;
use Modules\Products\Domain\QuantityPriceTier;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class VariantApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly CurrencyBuilder $money,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(string $productId): JsonResponse
    {
        $product = $this->em->find(Product::class, $productId);
        if (!$product instanceof Product) {
            return ApiResponse::notFound();
        }
        $groups = $this->em->getRepository(ProductOptionGroup::class)->findBy(
            ['scopeType' => ProductOptionGroup::SCOPE_PRODUCT, 'scopeId' => $productId],
            ['sortOrder' => 'ASC'],
        );
        $variants = $this->em->getRepository(ProductVariant::class)->findBy(['productId' => $productId], ['sku' => 'ASC']);
        $tiers = $this->em->getRepository(QuantityPriceTier::class)->findBy(
            ['scopeType' => 'product', 'scopeId' => $productId],
            ['sortOrder' => 'ASC', 'minQty' => 'ASC'],
        );

        $groupPayload = [];
        foreach ($groups as $group) {
            $values = $this->em->getRepository(ProductOptionValue::class)->findBy(['groupId' => $group->id()], ['sortOrder' => 'ASC']);
            $groupPayload[] = [
                'id' => $group->id(),
                'code' => $group->code(),
                'name' => $group->name(),
                'sort_order' => $group->sortOrder(),
                'values' => array_map(static fn (ProductOptionValue $v): array => [
                    'id' => $v->id(),
                    'code' => $v->code(),
                    'name' => $v->name(),
                    'sort_order' => $v->sortOrder(),
                ], $values),
                'required' => $group->isRequired(),
            ];
        }

        return ApiResponse::data([
            'product_id' => $productId,
            'option_groups' => $groupPayload,
            'variants' => array_map(fn (ProductVariant $v): array => $this->serVariant($v), $variants),
            'quantity_tiers' => array_map(static fn (QuantityPriceTier $t): array => [
                'id' => $t->id(),
                'min_qty' => $t->minQty(),
                'max_qty' => $t->maxQty(),
                'unit_price' => $t->unitPrice(),
                'currency' => $t->currency(),
            ], $tiers),
        ]);
    }

    public function storeVariant(Request $request, string $productId): JsonResponse
    {
        $product = $this->em->find(Product::class, $productId);
        if (!$product instanceof Product) {
            return ApiResponse::notFound();
        }
        $data = gereq()->make(ValidationRegistry::class)->validate('products.variant.save', $this->body($request));
        $variant = new ProductVariant($productId, (string) $data['sku']);
        $variant->setName(isset($data['name']) ? (string) $data['name'] : null);
        $attrs = $data['attributes'] ?? [];
        if (is_array($attrs)) {
            $variant->setAttributes(array_map('strval', $attrs));
        }
        if (isset($data['price_amount'])) {
            $variant->setPrice((string) $data['price_amount'], isset($data['price_currency']) ? (string) $data['price_currency'] : $this->money->defaultCode());
        }
        if (isset($data['stock_qty'])) {
            $variant->setStockQty((int) $data['stock_qty']);
        }
        if (isset($data['ean'])) {
            $variant->setEan((string) $data['ean']);
        }
        $active = $data['active'] ?? true;
        $variant->setActive($active === true || $active === '1' || $active === 1);
        $this->em->persist($variant);
        $this->em->flush();

        return ApiResponse::data($this->serVariant($variant), 201);
    }

    public function storeOptionGroup(Request $request, string $productId): JsonResponse
    {
        $product = $this->em->find(Product::class, $productId);
        if (!$product instanceof Product) {
            return ApiResponse::notFound();
        }
        $data = gereq()->make(ValidationRegistry::class)->validate('products.option_group.save', $this->body($request));
        $group = new ProductOptionGroup(
            ProductOptionGroup::SCOPE_PRODUCT,
            $productId,
            (string) ($data['code'] ?? 'field'),
            (string) $data['name'],
        );
        $group->setSortOrder((int) ($data['sort_order'] ?? 0));
        $this->em->persist($group);
        $this->em->flush();

        return ApiResponse::data([
            'id' => $group->id(),
            'code' => $group->code(),
            'name' => $group->name(),
            'sort_order' => $group->sortOrder(),
            'required' => $group->isRequired(),
        ], 201);
    }

    /** @return array<string, mixed> */
    private function serVariant(ProductVariant $v): array
    {
        return [
            'id' => $v->id(),
            'product_id' => $v->productId(),
            'sku' => $v->sku(),
            'name' => $v->name(),
            'attributes' => $v->attributes(),
            'price_amount' => $v->priceAmount(),
            'price_currency' => $v->priceCurrency(),
            'stock_qty' => $v->stockQty(),
            'ean' => $v->ean(),
            'active' => $v->isActive(),
        ];
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
