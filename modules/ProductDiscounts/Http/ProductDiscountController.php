<?php

declare(strict_types=1);

namespace Modules\ProductDiscounts\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Audit\Auditor;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\ProductDiscounts\Application\ProductDiscountsProvider;
use Modules\ProductDiscounts\Domain\ProductDiscount;
use Modules\Products\Domain\Product;
use Modules\Products\Domain\ProductCategory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ProductDiscountController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly ProductDiscountsProvider $discounts,
        private readonly Auditor $auditor,
        private readonly EventBus $events,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        return $this->redirect('admin.products.index');
    }

    public function create(): Response
    {
        return $this->redirect('admin.products.index');
    }

    public function store(Request $request): Response
    {
        return $this->redirect('admin.products.index');
    }

    public function edit(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            return $this->redirect('admin.products.index');
        }
        if ($item->scopeType() === ProductDiscount::SCOPE_CATEGORY) {
            return $this->redirectTo(
                $this->router->url('admin.products.categories.edit', ['id' => $item->scopeId()], $this->request()) . '#discounts'
            );
        }

        return $this->redirectTo(
            $this->router->url('admin.products.show', ['id' => $item->scopeId()], $this->request()) . '#discounts'
        );
    }

    public function update(Request $request, string $id): Response
    {
        return $this->edit($id);
    }

    public function destroy(string $id): Response
    {
        return $this->destroyOne($id);
    }

    public function upsertForProduct(Request $request, string $productId): Response
    {
        $product = $this->em->find(Product::class, $productId);
        if (!$product instanceof Product) {
            $this->abort(404, $this->translator->trans('product_discounts.error.product_not_found'));
        }
        $data = $this->validated($request, requireScope: false);
        $discount = $this->discounts->create(ProductDiscount::SCOPE_PRODUCT, $product->id(), $data);
        $this->events->emit('product_discount.saved', ['type' => 'product_discount', 'id' => $discount->id()], $this->discounts->serialize($discount) ?? []);
        $this->auditor->log('product_discounts.upserted', 'product_discount', $discount->id(), null, [
            'product_id' => $product->id(),
        ]);
        $this->forgetForm('product_discounts.save');
        $this->flash('success', $this->translator->trans('product_discounts.flash.saved'));

        return $this->redirectTo($this->router->url('admin.products.show', ['id' => $productId], $this->request()) . '#discounts');
    }

    public function destroyForProduct(string $productId): Response
    {
        $product = $this->em->find(Product::class, $productId);
        if (!$product instanceof Product) {
            $this->abort(404, $this->translator->trans('product_discounts.error.product_not_found'));
        }
        $existing = $this->discounts->findForProduct($product->id());
        $payload = $this->discounts->serialize($existing) ?? [];
        $this->discounts->deleteForProduct($product->id());
        if ($existing !== null) {
            $this->events->emit('product_discount.deleted', ['type' => 'product_discount', 'id' => $existing->id()], $payload);
            $this->auditor->log('product_discounts.deleted', 'product_discount', $existing->id());
        }
        $this->flash('success', $this->translator->trans('product_discounts.flash.deleted'));

        return $this->redirectTo($this->router->url('admin.products.show', ['id' => $productId], $this->request()) . '#discounts');
    }

    public function destroyOne(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('product_discounts.error.not_found'));
        }
        $scopeType = $item->scopeType();
        $scopeId = $item->scopeId();
        $payload = $this->discounts->serialize($item) ?? [];
        $this->discounts->delete($item);
        $this->events->emit('product_discount.deleted', ['type' => 'product_discount', 'id' => $id], $payload);
        $this->auditor->log('product_discounts.deleted', 'product_discount', $id);
        $this->flash('success', $this->translator->trans('product_discounts.flash.deleted'));

        if ($scopeType === ProductDiscount::SCOPE_CATEGORY) {
            return $this->redirectTo($this->router->url('admin.products.categories.edit', ['id' => $scopeId], $this->request()) . '#discounts');
        }

        return $this->redirectTo($this->router->url('admin.products.show', ['id' => $scopeId], $this->request()) . '#discounts');
    }

    public function storeForCategory(Request $request, string $categoryId): Response
    {
        $category = $this->em->find(ProductCategory::class, $categoryId);
        if (!$category instanceof ProductCategory) {
            $this->abort(404, $this->translator->trans('product_discounts.error.category_not_found'));
        }
        $data = $this->validated($request, requireScope: false);
        $discount = $this->discounts->create(ProductDiscount::SCOPE_CATEGORY, $category->id(), $data);
        $this->events->emit('product_discount.saved', ['type' => 'product_discount', 'id' => $discount->id()], $this->discounts->serialize($discount) ?? []);
        $this->auditor->log('product_discounts.created', 'product_discount', $discount->id(), null, [
            'scope_type' => 'category',
            'scope_id' => $category->id(),
        ]);
        $this->forgetForm('product_discounts.save');
        $this->flash('success', $this->translator->trans('product_discounts.flash.created'));

        return $this->redirectTo($this->router->url('admin.products.categories.edit', ['id' => $categoryId], $this->request()) . '#discounts');
    }

    private function find(string $id): ?ProductDiscount
    {
        $item = $this->em->find(ProductDiscount::class, $id);

        return $item instanceof ProductDiscount ? $item : null;
    }

    /**
     * @return array{
     *   scope_type: string,
     *   scope_id: ?string,
     *   kind: string,
     *   value: string,
     *   description: ?string,
     *   condition_type: string,
     *   condition_value: ?int,
     *   starts_at: ?\DateTimeImmutable,
     *   ends_at: ?\DateTimeImmutable,
     *   active: bool
     * }
     */
    private function validated(Request $request, bool $requireScope): array
    {
        $data = $this->validate($request, 'product_discounts.save');
        $scopeType = strtolower(trim((string) ($data['scope_type'] ?? $request->request->get('scope_type', ProductDiscount::SCOPE_PRODUCT))));
        if (!in_array($scopeType, ProductDiscount::SCOPES, true)) {
            $scopeType = ProductDiscount::SCOPE_PRODUCT;
        }
        $scopeId = trim((string) ($data['scope_id'] ?? $request->request->get('scope_id', '')));
        if ($scopeId === '') {
            $scopeId = trim((string) ($data['product_id'] ?? $request->request->get('product_id', '')));
        }
        if ($requireScope && $scopeId === '') {
            $this->abort(422, $this->translator->trans(
                $scopeType === ProductDiscount::SCOPE_CATEGORY
                    ? 'product_discounts.error.category_required'
                    : 'product_discounts.error.product_required'
            ));
        }
        $activeRaw = $data['active'] ?? $request->request->get('active');
        $conditionType = strtolower(trim((string) ($data['condition_type'] ?? ProductDiscount::CONDITION_NONE)));
        if (!in_array($conditionType, ProductDiscount::CONDITIONS, true)) {
            $conditionType = ProductDiscount::CONDITION_NONE;
        }
        $conditionValueRaw = trim((string) ($data['condition_value'] ?? $request->request->get('condition_value', '')));

        return [
            'scope_type' => $scopeType,
            'scope_id' => $scopeId !== '' ? $scopeId : null,
            'kind' => strtolower(trim((string) ($data['kind'] ?? 'percent'))),
            'value' => number_format((float) ($data['value'] ?? 0), 2, '.', ''),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'condition_type' => $conditionType,
            'condition_value' => $conditionValueRaw !== '' ? (int) $conditionValueRaw : null,
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
        } catch (\Throwable) {
            return null;
        }
    }
}
