<?php

declare(strict_types=1);

namespace Modules\Products\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\File\FileManager;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Gereq\Publish\Adapter\ProductPublishAdapter;
use Gereq\Publish\ProductionFreeze;
use Modules\Currency\Application\CurrencyBuilder;
use Modules\Currency\Domain\Currency;
use Modules\Products\Application\ProductPersonalizationService;
use Modules\Products\Application\ProductVariantOptionsService;
use Modules\Products\Domain\Events\ProductActivated;
use Modules\Products\Domain\Events\ProductCreated;
use Modules\Products\Domain\Events\ProductDeactivated;
use Modules\Products\Domain\Events\ProductPriceChanged;
use Modules\Products\Domain\Events\ProductUpdated;
use Modules\Products\Domain\Product;
use Modules\Products\Domain\ProductCategory;
use Modules\Products\Domain\ProductImage;
use Modules\Products\Domain\ProductOptionGroup;
use Modules\Products\Domain\ProductPrice;
use Modules\Products\Domain\ProductVariant;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ProductController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Auditor $auditor,
        private readonly EventBus $events,
        private readonly TranslatorInterface $translator,
        private readonly FileManager $files,
        private readonly CapabilityRegistry $capabilities,
        private readonly ProductPersonalizationService $personalization,
        private readonly ProductVariantOptionsService $variantOptions,
        private readonly CurrencyBuilder $money,
        private readonly ProductionFreeze $freeze,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    private function freezeProductIfPublic(Product $product): void
    {
        if ($product->status() !== 'active' && !$this->freeze->hasFreeze('products', $product->id())) {
            return;
        }
        $this->freeze->freezeIfNeeded(
            'products',
            'product',
            $product->id(),
            fn (): array => ProductPublishAdapter::capture($product),
            $product->name(),
            $this->auth->user()?->id(),
        );
    }

    public function index(Request $request): Response
    {
        $list = \Gereq\Http\ListQuery::fromRequest(
            $request,
            sortable: ['sku', 'name', 'status', 'unit', 'updatedAt', 'createdAt'],
            defaultSort: 'updatedAt',
            defaultDir: 'desc',
            filterKeys: ['status', 'category_id', 'unit'],
        );
        $status = $list->filter('status');
        $categoryId = $list->filter('category_id');
        $unit = $list->filter('unit');

        $qb = $this->em->getRepository(Product::class)->createQueryBuilder('p')
            ->leftJoin('p.categories', 'cats')
            ->addSelect('cats');
        $list->applySearch($qb, 'p', ['name', 'sku']);
        if (in_array($status, Product::STATUSES, true)) {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }
        if ($categoryId !== '') {
            $qb->innerJoin('p.categories', 'filterCat')
                ->andWhere('filterCat.id = :cid')
                ->setParameter('cid', $categoryId);
        }
        if ($unit !== '') {
            $qb->andWhere('LOWER(p.unit) = :unit')->setParameter('unit', mb_strtolower($unit));
        }

        $deleteKeys = $this->freeze->pendingDeleteKeys('products');
        if ($deleteKeys !== []) {
            $qb->andWhere('p.id NOT IN (:pendingDelete)')->setParameter('pendingDelete', $deleteKeys);
        }

        $qb->orderBy('p.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(DISTINCT p.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<Product> $items */
        $items = $qb->distinct(true)->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        $unitRows = $this->em->createQueryBuilder()
            ->select('DISTINCT p.unit AS unit')
            ->from(Product::class, 'p')
            ->orderBy('p.unit', 'ASC')
            ->getQuery()
            ->getScalarResult();
        $units = array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['unit'] ?? ''),
            $unitRows,
        )));

        return $this->page('@products/products/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('products.title'),
            'items' => $items,
            'status' => $status,
            'statuses' => Product::STATUSES,
            'category_id' => $categoryId,
            'unit' => $unit,
            'units' => $units,
            'categoryOptions' => $this->categoryOptions(),
            'thumbs' => $this->primaryThumbs($items),
        ]));
    }

    public function create(): Response
    {
        return $this->page('@products/products/form.html.twig', [
            'pageTitle' => $this->translator->trans('products.new'),
            'item' => null,
            'categoryOptions' => $this->categoryOptions(),
            'selectedCategories' => [],
            'imageRule' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $existing = $this->em->getRepository(Product::class)->findOneBy(['sku' => $data['sku']]);
        if ($existing instanceof Product) {
            $this->flash('error', $this->translator->trans('products.error.sku_exists'));

            return $this->redirect('admin.products.create');
        }

        $product = new Product($data['sku'], $data['name']);
        $product->setDescription($data['description']);
        $product->setStatus($data['status']);
        $product->setUnit($data['unit']);
        $product->setCustomerNoteMode($data['customer_note_mode']);
        $this->applyCategories($product, $data['category_ids']);
        $this->em->persist($product);
        $this->em->flush();
        $this->personalization->upsertRule($product->id(), $data['image_rule']);

        if ($product->status() === 'active') {
            $this->freeze->stageCreate(
                'products',
                'product',
                $product->id(),
                $product->name(),
                $this->auth->user()?->id(),
            );
        }

        $payload = $this->publicProduct($product);
        $this->events->dispatch(ProductCreated::make($product->id(), $payload));
        if ($product->status() === 'active') {
            $this->events->dispatch(ProductActivated::make($product->id(), $payload));
        }
        $this->auditor->log('product.created', 'product', $product->id(), null, [
            'sku' => $product->sku(),
            'name' => $product->name(),
        ]);
        $this->forgetForm('products.product.save');
        $this->flash(
            'success',
            $product->status() === 'active'
                ? $this->translator->trans('publish.flash.staged')
                : $this->translator->trans('products.flash.created', ['%name%' => $product->name()]),
        );

        return $this->redirect('admin.products.show', ['id' => $product->id()]);
    }

    public function show(string $id): Response
    {
        $product = $this->find($id);
        if ($product === null) {
            $this->abort(404, $this->translator->trans('products.error.not_found'));
        }

        $prices = $this->em->getRepository(ProductPrice::class)->findBy(
            ['productId' => $product->id()],
            ['currencyCode' => 'ASC'],
        );

        $images = $this->em->getRepository(ProductImage::class)->findBy(
            ['productId' => $product->id()],
            ['isPrimary' => 'DESC', 'sortOrder' => 'ASC', 'createdAt' => 'ASC'],
        );
        $variants = $this->em->getRepository(ProductVariant::class)->findBy(
            ['productId' => $product->id()],
            ['sku' => 'ASC'],
        );
        $optionGroups = $this->variantOptions->groupsWithValues($product->id());
        $categoryOptionGroups = [];
        $primaryCategory = $this->variantOptions->primaryCategory($product);
        if ($primaryCategory !== null) {
            $categoryOptionGroups = $this->variantOptions->groupsForScope(
                ProductOptionGroup::SCOPE_CATEGORY,
                $primaryCategory->id(),
            );
        }

        return $this->page('@products/products/show.html.twig', [
            'pageTitle' => $product->name(),
            'item' => $product,
            'prices' => $prices,
            'images' => $images,
            'variants' => $variants,
            'optionGroups' => $optionGroups,
            'categoryOptionGroups' => $categoryOptionGroups,
            'primaryCategory' => $primaryCategory,
            'imageAssets' => $this->resolveImageAssets($images),
            'libraryImages' => $this->libraryImageOptions($images),
            'hasMedia' => $this->capabilities->has('media') && class_exists(\Modules\Media\Domain\MediaAsset::class),
            'hasProductDiscounts' => $this->capabilities->has('product_discounts'),
            'productDiscount' => $this->capabilities->has('product_discounts')
                && class_exists(\Modules\ProductDiscounts\Application\ProductDiscountsProvider::class)
                ? $this->capabilities->get('product_discounts')->findForProduct($product->id())
                : null,
            'productDiscounts' => $this->capabilities->has('product_discounts')
                && class_exists(\Modules\ProductDiscounts\Application\ProductDiscountsProvider::class)
                ? $this->capabilities->get('product_discounts')->listForProduct($product->id())
                : [],
            'discountConditions' => class_exists(\Modules\ProductDiscounts\Domain\ProductDiscount::class)
                ? \Modules\ProductDiscounts\Domain\ProductDiscount::CONDITIONS
                : ['none'],
            'statuses' => Product::STATUSES,
            'currencyOptions' => $this->currencyOptions(),
        ]);
    }

    public function edit(string $id): Response
    {
        $product = $this->find($id);
        if ($product === null) {
            $this->abort(404, $this->translator->trans('products.error.not_found'));
        }

        $selected = [];
        foreach ($product->categories() as $category) {
            $selected[] = $category->id();
        }

        return $this->page('@products/products/form.html.twig', [
            'pageTitle' => $this->translator->trans('products.edit'),
            'item' => $product,
            'categoryOptions' => $this->categoryOptions(),
            'selectedCategories' => $selected,
            'imageRule' => $this->personalization->findRule($product->id()),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $product = $this->find($id);
        if ($product === null) {
            $this->abort(404, $this->translator->trans('products.error.not_found'));
        }

        $data = $this->validated($request);
        $skuOwner = $this->em->getRepository(Product::class)->findOneBy(['sku' => $data['sku']]);
        if ($skuOwner instanceof Product && $skuOwner->id() !== $product->id()) {
            $this->flash('error', $this->translator->trans('products.error.sku_exists'));

            return $this->redirect('admin.products.edit', ['id' => $id]);
        }

        $old = ['sku' => $product->sku(), 'name' => $product->name(), 'status' => $product->status()];
        $previousStatus = $product->status();
        $wantActive = $data['status'] === 'active';
        if ($previousStatus === 'active' || $wantActive) {
            $this->freeze->freezeIfNeeded(
                'products',
                'product',
                $product->id(),
                fn (): array => ProductPublishAdapter::capture($product),
                $product->name(),
                $this->auth->user()?->id(),
            );
        }
        $product->setSku($data['sku']);
        $product->setName($data['name']);
        $product->setDescription($data['description']);
        $product->setStatus($data['status']);
        $product->setUnit($data['unit']);
        $product->setCustomerNoteMode($data['customer_note_mode']);
        $this->applyCategories($product, $data['category_ids']);
        $this->em->flush();
        $this->personalization->upsertRule($product->id(), $data['image_rule']);

        if ($wantActive && $previousStatus !== 'active' && !$this->freeze->hasFreeze('products', $product->id())) {
            $this->freeze->stageCreate(
                'products',
                'product',
                $product->id(),
                $product->name(),
                $this->auth->user()?->id(),
            );
        }

        $payload = $this->publicProduct($product);
        $this->events->dispatch(ProductUpdated::make($product->id(), $payload));
        $this->emitStatusChange($previousStatus, $product->status(), $product->id(), $payload);
        $this->auditor->log('product.updated', 'product', $product->id(), $old, [
            'sku' => $product->sku(),
            'name' => $product->name(),
            'status' => $product->status(),
        ]);
        $this->forgetForm('products.product.save');
        $this->flash(
            'success',
            ($previousStatus === 'active' || $wantActive)
                ? $this->translator->trans('publish.flash.staged')
                : $this->translator->trans('products.flash.saved'),
        );

        return $this->redirect('admin.products.show', ['id' => $id]);
    }

    public function setStatus(Request $request, string $id): Response
    {
        $product = $this->find($id);
        if ($product === null || $this->freeze->isPendingDelete('products', $id)) {
            $this->abort(404, $this->translator->trans('products.error.not_found'));
        }

        $status = (string) $request->request->get('status', '');
        if (!in_array($status, Product::STATUSES, true)) {
            $this->flash('error', $this->translator->trans('products.flash.status_invalid'));

            return $this->redirect('admin.products.show', ['id' => $id]);
        }

        $previousStatus = $product->status();
        if ($previousStatus === 'active' || $status === 'active') {
            $this->freeze->freezeIfNeeded(
                'products',
                'product',
                $product->id(),
                fn (): array => ProductPublishAdapter::capture($product),
                $product->name(),
                $this->auth->user()?->id(),
            );
        }
        $product->setStatus($status);
        $this->em->flush();

        $payload = $this->publicProduct($product);
        $this->events->dispatch(ProductUpdated::make($product->id(), $payload));
        $this->emitStatusChange($previousStatus, $status, $product->id(), $payload);
        $this->auditor->log('product.status_changed', 'product', $product->id(), ['status' => $previousStatus], ['status' => $status]);
        $this->flash('success', $this->translator->trans('publish.flash.staged'));

        return $this->redirect('admin.products.show', ['id' => $id]);
    }

    public function storePrice(Request $request, string $id): Response
    {
        $product = $this->find($id);
        if ($product === null) {
            $this->abort(404, $this->translator->trans('products.error.not_found'));
        }
        $this->freezeProductIfPublic($product);

        $data = $this->validate($request, 'products.price.save');
        $amountRaw = trim((string) $data['amount']);
        $normalized = str_replace([' ', ','], ['', '.'], $amountRaw);
        if (!is_numeric($normalized)) {
            $this->flash('error', $this->translator->trans('products.error.invalid_amount'));

            return $this->redirect('admin.products.show', ['id' => $id]);
        }

        $currency = strtoupper(trim((string) $data['currency_code']));
        $amount = number_format((float) $normalized, 4, '.', '');

        $price = new ProductPrice($product->id(), $currency, $amount);
        $from = trim((string) ($data['valid_from'] ?? ''));
        $to = trim((string) ($data['valid_to'] ?? ''));
        if ($from !== '') {
            try {
                $price->setValidFrom(new \DateTimeImmutable($from));
            } catch (\Exception) {
                $this->flash('error', $this->translator->trans('products.error.invalid_date'));

                return $this->redirect('admin.products.show', ['id' => $id]);
            }
        }
        if ($to !== '') {
            try {
                $price->setValidTo(new \DateTimeImmutable($to));
            } catch (\Exception) {
                $this->flash('error', $this->translator->trans('products.error.invalid_date'));

                return $this->redirect('admin.products.show', ['id' => $id]);
            }
        }

        $this->em->persist($price);
        $this->em->flush();

        $payload = array_merge($this->publicProduct($product), [
            'price' => [
                'id' => $price->id(),
                'currency_code' => $price->currencyCode(),
                'amount' => $price->amount(),
            ],
        ]);
        $this->events->dispatch(ProductPriceChanged::make($product->id(), $payload));
        $this->auditor->log('product.price_changed', 'product', $product->id(), null, [
            'price_id' => $price->id(),
            'amount' => $price->amount(),
            'currency' => $price->currencyCode(),
        ]);
        $this->forgetForm('products.price.save');
        $this->flash('success', $this->translator->trans('products.flash.price_saved'));

        return $this->redirect('admin.products.show', ['id' => $id]);
    }

    public function destroyPrice(string $id, string $priceId): Response
    {
        $product = $this->find($id);
        if ($product === null) {
            $this->abort(404, $this->translator->trans('products.error.not_found'));
        }

        $price = $this->em->find(ProductPrice::class, $priceId);
        if (!$price instanceof ProductPrice || $price->productId() !== $product->id()) {
            $this->abort(404, $this->translator->trans('products.error.price_not_found'));
        }

        $old = ['amount' => $price->amount(), 'currency' => $price->currencyCode()];
        $this->em->remove($price);
        $this->em->flush();

        $payload = array_merge($this->publicProduct($product), ['removed_price' => $old]);
        $this->events->dispatch(ProductPriceChanged::make($product->id(), $payload));
        $this->auditor->log('product.price_removed', 'product', $product->id(), $old, null);
        $this->flash('success', $this->translator->trans('products.flash.price_deleted'));

        return $this->redirect('admin.products.show', ['id' => $id]);
    }

    public function storeVariant(Request $request, string $id): Response
    {
        $product = $this->find($id);
        if ($product === null) {
            $this->abort(404, $this->translator->trans('products.error.not_found'));
        }

        $data = $this->validate($request, 'products.variant.save');
        $sku = strtoupper(trim((string) $data['sku']));
        $existing = $this->em->getRepository(ProductVariant::class)->findOneBy(['sku' => $sku]);
        if ($existing instanceof ProductVariant) {
            $this->flash('error', $this->translator->trans('products.error.sku_exists'));

            return $this->redirect('admin.products.show', ['id' => $id]);
        }

        $variant = new ProductVariant($product->id(), $sku);
        $variant->setName(isset($data['name']) ? trim((string) $data['name']) : null);
        $attrs = $data['attributes'] ?? [];
        if (is_array($attrs) && $attrs !== []) {
            $variant->setAttributes(array_map('strval', $attrs));
        }
        if (isset($data['price_amount']) && trim((string) $data['price_amount']) !== '') {
            $variant->setPrice(
                (string) $data['price_amount'],
                isset($data['price_currency']) ? (string) $data['price_currency'] : $this->money->defaultCode(),
            );
        }
        if (isset($data['stock_qty']) && $data['stock_qty'] !== '') {
            $variant->setStockQty((int) $data['stock_qty']);
        }
        if (isset($data['ean'])) {
            $variant->setEan((string) $data['ean']);
        }
        $active = $data['active'] ?? true;
        $variant->setActive($active === true || $active === '1' || $active === 1 || $active === 'on');
        $this->em->persist($variant);
        $this->em->flush();
        $this->auditor->log('product.variant_created', 'product', $product->id(), null, ['sku' => $sku]);
        $this->flash('success', 'Varianta uložena.');

        return $this->redirectToVariants($id);
    }

    public function updateVariant(Request $request, string $id, string $variantId): Response
    {
        $product = $this->find($id);
        if ($product === null) {
            $this->abort(404, $this->translator->trans('products.error.not_found'));
        }
        $variant = $this->em->find(ProductVariant::class, $variantId);
        if (!$variant instanceof ProductVariant || $variant->productId() !== $product->id()) {
            $this->abort(404);
        }

        $data = $this->validate($request, 'products.variant.update');
        if (isset($data['sku']) && trim((string) $data['sku']) !== '') {
            $sku = strtoupper(trim((string) $data['sku']));
            $owner = $this->em->getRepository(ProductVariant::class)->findOneBy(['sku' => $sku]);
            if ($owner instanceof ProductVariant && $owner->id() !== $variant->id()) {
                $this->flash('error', $this->translator->trans('products.error.sku_exists'));

                return $this->redirectToVariants($id);
            }
            $variant->setSku($sku);
        }
        if (array_key_exists('name', $data)) {
            $variant->setName(trim((string) $data['name']));
        }
        if (isset($data['price_amount']) && trim((string) $data['price_amount']) !== '') {
            $variant->setPrice(
                (string) $data['price_amount'],
                isset($data['price_currency']) ? (string) $data['price_currency'] : ($variant->priceCurrency() ?: $this->money->defaultCode()),
            );
        } elseif (isset($data['price_amount']) && trim((string) $data['price_amount']) === '') {
            $variant->setPrice(null);
        }
        if (array_key_exists('stock_qty', $data)) {
            $stock = trim((string) $data['stock_qty']);
            $variant->setStockQty($stock === '' ? null : (int) $stock);
        }
        if (array_key_exists('ean', $data)) {
            $variant->setEan(trim((string) $data['ean']));
        }
        $this->em->flush();
        $this->flash('success', 'Varianta aktualizována.');

        return $this->redirectToVariants($id);
    }

    public function destroyVariant(string $id, string $variantId): Response
    {
        $product = $this->find($id);
        if ($product === null) {
            $this->abort(404, $this->translator->trans('products.error.not_found'));
        }
        $variant = $this->em->find(ProductVariant::class, $variantId);
        if (!$variant instanceof ProductVariant || $variant->productId() !== $product->id()) {
            $this->abort(404);
        }
        $this->em->remove($variant);
        $this->em->flush();
        $this->auditor->log('product.variant_deleted', 'product', $product->id(), ['sku' => $variant->sku()], null);
        $this->flash('success', 'Varianta smazána.');

        return $this->redirectToVariants($id);
    }

    public function storeOptionGroup(Request $request, string $id): Response
    {
        $product = $this->find($id);
        if ($product === null) {
            $this->abort(404, $this->translator->trans('products.error.not_found'));
        }
        $data = $this->validate($request, 'products.option_group.save');
        try {
            $this->variantOptions->createGroup(
                ProductOptionGroup::SCOPE_PRODUCT,
                $product->id(),
                (string) $data['name'],
                (string) ($data['options_text'] ?? ''),
                $this->truthy($request, $data, 'required', true),
            );
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirectToVariants($id);
        }
        $this->flash('success', 'Kolonka variant vytvořena.');

        return $this->redirectToVariants($id);
    }

    public function updateOptionGroup(Request $request, string $id, string $groupId): Response
    {
        $product = $this->find($id);
        if ($product === null) {
            $this->abort(404, $this->translator->trans('products.error.not_found'));
        }
        $group = $this->em->find(ProductOptionGroup::class, $groupId);
        if (
            !$group instanceof ProductOptionGroup
            || $group->scopeType() !== ProductOptionGroup::SCOPE_PRODUCT
            || $group->scopeId() !== $product->id()
        ) {
            $this->abort(404);
        }
        $data = $this->validate($request, 'products.option_group.save');
        try {
            $this->variantOptions->updateGroup(
                $group,
                (string) $data['name'],
                (string) ($data['options_text'] ?? ''),
                $this->truthy($request, $data, 'required', true),
            );
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirectToVariants($id);
        }
        $this->flash('success', 'Kolonka uložena.');

        return $this->redirectToVariants($id);
    }

    public function destroyOptionGroup(string $id, string $groupId): Response
    {
        $product = $this->find($id);
        if ($product === null) {
            $this->abort(404, $this->translator->trans('products.error.not_found'));
        }
        $group = $this->em->find(ProductOptionGroup::class, $groupId);
        if (
            !$group instanceof ProductOptionGroup
            || $group->scopeType() !== ProductOptionGroup::SCOPE_PRODUCT
            || $group->scopeId() !== $product->id()
        ) {
            $this->abort(404);
        }
        $this->variantOptions->deleteGroup($group);
        $this->flash('success', 'Kolonka smazána.');

        return $this->redirectToVariants($id);
    }

    public function generateVariants(string $id): Response
    {
        $product = $this->find($id);
        if ($product === null) {
            $this->abort(404, $this->translator->trans('products.error.not_found'));
        }
        $result = $this->variantOptions->generateMissingVariants($product);
        $created = $result['created'];
        $removed = $result['removed'];
        if ($created === 0 && $removed === 0) {
            $this->flash('success', 'Žádná změna — všechny platné kombinace už existují, nebo chybí položky v kolonkách.');
        } elseif ($removed > 0 && $created === 0) {
            $this->flash('success', sprintf('Odstraněno %d neplatných kombinací (neodpovídají současným kolonkám).', $removed));
        } elseif ($removed > 0) {
            $this->flash('success', sprintf('Vygenerováno %d nových a odstraněno %d neplatných kombinací.', $created, $removed));
        } else {
            $this->flash('success', sprintf('Vygenerováno %d nových kombinací (SKU).', $created));
        }

        return $this->redirectToVariants($id);
    }

    public function setInheritCategoryOptions(Request $request, string $id): Response
    {
        $product = $this->find($id);
        if ($product === null) {
            $this->abort(404, $this->translator->trans('products.error.not_found'));
        }
        $enabled = $request->request->get('inherit_category_options') === '1'
            || $request->request->get('inherit_category_options') === 'on';
        $product->setInheritCategoryOptions($enabled);
        $this->em->flush();
        $this->flash('success', $enabled
            ? 'Produkt dědí kolonky z kategorie.'
            : 'Dědění z kategorie vypnuto — platí jen kolonky produktu.');

        return $this->redirectToVariants($id);
    }

    private function redirectToVariants(string $productId): Response
    {
        return $this->redirectTo($this->router->url('admin.products.show', ['id' => $productId], $this->request()) . '#variants');
    }

    /** @param array<string, mixed> $data */
    private function truthy(Request $request, array $data, string $key, bool $default = false): bool
    {
        $raw = $request->request->all()[$key] ?? ($data[$key] ?? null);
        if ($raw === null) {
            return $default;
        }
        if (is_array($raw)) {
            $raw = end($raw);
        }

        return $raw === '1' || $raw === 1 || $raw === true || $raw === 'on' || $raw === 'true';
    }

    public function storeImage(Request $request, string $id): Response
    {
        $product = $this->find($id);
        if ($product === null) {
            $this->abort(404, $this->translator->trans('products.error.not_found'));
        }
        if (!$this->mediaAvailable()) {
            $this->flash('error', $this->translator->trans('products.images.error.media_required'));

            return $this->redirect('admin.products.show', ['id' => $id]);
        }

        $data = $this->validate($request, 'products.image.upload');
        $uploaded = $request->files->get('file');
        if (!$uploaded instanceof UploadedFile || !$uploaded->isValid()) {
            $this->flash('error', $this->translator->trans('products.images.error.file_required'));

            return $this->redirect('admin.products.show', ['id' => $id]);
        }

        $mime = (string) ($uploaded->getMimeType() ?: '');
        if (!str_starts_with($mime, 'image/')) {
            $this->flash('error', $this->translator->trans('products.images.error.not_image'));

            return $this->redirect('admin.products.show', ['id' => $id]);
        }

        try {
            $stored = $this->files->store($uploaded, $this->auth->user());
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('admin.products.show', ['id' => $id]);
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = $product->name() . ' — ' . $stored->originalName();
        }
        $alt = trim((string) ($data['alt'] ?? ''));
        [$width, $height] = $this->imageDimensions($uploaded, $stored);

        $asset = new \Modules\Media\Domain\MediaAsset(
            $title,
            $stored->path(),
            $stored->mime(),
            $stored->size(),
            $this->auth->user()?->id(),
            $alt !== '' ? $alt : null,
            $width,
            $height,
        );
        $this->em->persist($asset);

        $count = (int) $this->em->getRepository(ProductImage::class)->count(['productId' => $product->id()]);
        $image = new ProductImage($product->id(), $asset->id(), $count);
        if ($alt !== '') {
            $image->setAlt($alt);
        }
        if ($count === 0) {
            $image->setPrimary(true);
        }
        $this->em->persist($image);
        $this->em->flush();

        if (class_exists(\Modules\Media\Domain\Events\MediaUploaded::class)) {
            $this->events->dispatch(\Modules\Media\Domain\Events\MediaUploaded::make($asset->id(), [
                'id' => $asset->id(),
                'title' => $asset->title(),
                'mime' => $asset->mime(),
                'path' => $asset->path(),
            ]));
        }

        $this->auditor->log('product.image_uploaded', 'product', $product->id(), null, [
            'image_id' => $image->id(),
            'media_asset_id' => $asset->id(),
        ]);
        $this->forgetForm('products.image.upload');
        $this->flash('success', $this->translator->trans('products.flash.image_uploaded'));

        return $this->redirect('admin.products.show', ['id' => $id]);
    }

    public function attachImage(Request $request, string $id): Response
    {
        $product = $this->find($id);
        if ($product === null) {
            $this->abort(404, $this->translator->trans('products.error.not_found'));
        }
        if (!$this->mediaAvailable()) {
            $this->flash('error', $this->translator->trans('products.images.error.media_required'));

            return $this->redirect('admin.products.show', ['id' => $id]);
        }

        $data = $this->validate($request, 'products.image.attach');
        $mediaId = trim((string) $data['media_asset_id']);
        $asset = $this->em->find(\Modules\Media\Domain\MediaAsset::class, $mediaId);
        if (!$asset instanceof \Modules\Media\Domain\MediaAsset || !$asset->isImage()) {
            $this->flash('error', $this->translator->trans('products.images.error.media_not_found'));

            return $this->redirect('admin.products.show', ['id' => $id]);
        }

        $existing = $this->em->getRepository(ProductImage::class)->findOneBy([
            'productId' => $product->id(),
            'mediaAssetId' => $asset->id(),
        ]);
        if ($existing instanceof ProductImage) {
            $this->flash('error', $this->translator->trans('products.images.error.already_attached'));

            return $this->redirect('admin.products.show', ['id' => $id]);
        }

        $count = (int) $this->em->getRepository(ProductImage::class)->count(['productId' => $product->id()]);
        $image = new ProductImage($product->id(), $asset->id(), $count);
        $alt = trim((string) ($data['alt'] ?? ''));
        if ($alt !== '') {
            $image->setAlt($alt);
        } elseif ($asset->alt()) {
            $image->setAlt($asset->alt());
        }
        if ($count === 0) {
            $image->setPrimary(true);
        }
        $this->em->persist($image);
        $this->em->flush();

        $this->auditor->log('product.image_attached', 'product', $product->id(), null, [
            'image_id' => $image->id(),
            'media_asset_id' => $asset->id(),
        ]);
        $this->forgetForm('products.image.attach');
        $this->flash('success', $this->translator->trans('products.flash.image_attached'));

        return $this->redirect('admin.products.show', ['id' => $id]);
    }

    public function setPrimaryImage(string $id, string $imageId): Response
    {
        $product = $this->find($id);
        if ($product === null) {
            $this->abort(404, $this->translator->trans('products.error.not_found'));
        }

        $image = $this->em->find(ProductImage::class, $imageId);
        if (!$image instanceof ProductImage || $image->productId() !== $product->id()) {
            $this->abort(404, $this->translator->trans('products.images.error.not_found'));
        }

        foreach ($this->em->getRepository(ProductImage::class)->findBy(['productId' => $product->id()]) as $row) {
            $row->setPrimary($row->id() === $image->id());
        }
        $this->em->flush();

        $this->auditor->log('product.image_primary', 'product', $product->id(), null, ['image_id' => $image->id()]);
        $this->flash('success', $this->translator->trans('products.flash.image_primary'));

        return $this->redirect('admin.products.show', ['id' => $id]);
    }

    public function destroyImage(string $id, string $imageId): Response
    {
        $product = $this->find($id);
        if ($product === null) {
            $this->abort(404, $this->translator->trans('products.error.not_found'));
        }

        $image = $this->em->find(ProductImage::class, $imageId);
        if (!$image instanceof ProductImage || $image->productId() !== $product->id()) {
            $this->abort(404, $this->translator->trans('products.images.error.not_found'));
        }

        $wasPrimary = $image->isPrimary();
        $this->em->remove($image);
        $this->em->flush();

        if ($wasPrimary) {
            $next = $this->em->getRepository(ProductImage::class)->findOneBy(
                ['productId' => $product->id()],
                ['sortOrder' => 'ASC', 'createdAt' => 'ASC'],
            );
            if ($next instanceof ProductImage) {
                $next->setPrimary(true);
                $this->em->flush();
            }
        }

        $this->auditor->log('product.image_removed', 'product', $product->id(), ['image_id' => $imageId], null);
        $this->flash('success', $this->translator->trans('products.flash.image_deleted'));

        return $this->redirect('admin.products.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $product = $this->find($id);
        if ($product === null || $this->freeze->isPendingDelete('products', $id)) {
            $this->abort(404, $this->translator->trans('products.error.not_found'));
        }

        $name = $product->name();
        if ($product->status() === 'active' || $this->freeze->hasFreeze('products', $id)) {
            $this->freeze->stageDelete(
                'products',
                'product',
                $id,
                fn (): array => ProductPublishAdapter::capture($product),
                $name,
                $this->auth->user()?->id(),
            );
            $this->auditor->log('product.delete_staged', 'product', $id);
            $this->flash('success', $this->translator->trans('publish.flash.staged'));
        } else {
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
            $this->auditor->log('product.deleted', 'product', $id);
            $this->flash('success', $this->translator->trans('products.flash.deleted', ['%name%' => $name]));
        }

        return $this->redirect('admin.products.index');
    }

    public function bulk(Request $request): Response
    {
        $data = $this->validate($request, 'products.bulk');
        $action = (string) $data['action'];
        $ids = $request->request->all('ids');
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $ids,
        ), static fn (string $id): bool => $id !== '')));

        if ($ids === []) {
            $this->flash('error', $this->translator->trans('products.bulk.error.empty'));

            return $this->redirect('admin.products.index');
        }
        if ($action === 'delete' && !$request->request->getBoolean('confirmed')) {
            $this->flash('error', $this->translator->trans('products.bulk.error.confirm'));

            return $this->redirect('admin.products.index');
        }

        $affected = 0;
        foreach ($ids as $id) {
            $product = $this->find($id);
            if ($product === null) {
                continue;
            }

            if ($action === 'activate') {
                $previous = $product->status();
                $product->setStatus('active');
                $payload = $this->publicProduct($product);
                $this->events->dispatch(ProductUpdated::make($product->id(), $payload));
                $this->emitStatusChange($previous, 'active', $product->id(), $payload);
                $this->auditor->log('product.status_changed', 'product', $product->id(), ['status' => $previous], ['status' => 'active']);
                $affected++;
            } elseif ($action === 'draft') {
                $previous = $product->status();
                $product->setStatus('draft');
                $payload = $this->publicProduct($product);
                $this->events->dispatch(ProductUpdated::make($product->id(), $payload));
                $this->emitStatusChange($previous, 'draft', $product->id(), $payload);
                $this->auditor->log('product.status_changed', 'product', $product->id(), ['status' => $previous], ['status' => 'draft']);
                $affected++;
            } elseif ($action === 'archive') {
                $previous = $product->status();
                $product->setStatus('archived');
                $payload = $this->publicProduct($product);
                $this->events->dispatch(ProductUpdated::make($product->id(), $payload));
                $this->emitStatusChange($previous, 'archived', $product->id(), $payload);
                $this->auditor->log('product.status_changed', 'product', $product->id(), ['status' => $previous], ['status' => 'archived']);
                $affected++;
            } elseif ($action === 'delete') {
                foreach ($this->em->getRepository(ProductPrice::class)->findBy(['productId' => $id]) as $price) {
                    $this->em->remove($price);
                }
                foreach ($this->em->getRepository(ProductImage::class)->findBy(['productId' => $id]) as $image) {
                    $this->em->remove($image);
                }
                $product->clearCategories();
                $this->em->remove($product);
                $this->auditor->log('product.deleted', 'product', $id);
                $affected++;
            }
        }

        $this->em->flush();
        $this->flash('success', $this->translator->trans('products.bulk.flash.' . $action, [
            '%count%' => (string) $affected,
        ]));

        return $this->redirect('admin.products.index');
    }

    /**
     * @param list<Product> $products
     * @return array<string, string>
     */
    private function primaryThumbs(array $products): array
    {
        if ($products === []) {
            return [];
        }
        $ids = array_map(static fn (Product $p): string => $p->id(), $products);
        /** @var list<ProductImage> $images */
        $images = $this->em->getRepository(ProductImage::class)->createQueryBuilder('i')
            ->where('i.productId IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('i.isPrimary', 'DESC')
            ->addOrderBy('i.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($images as $image) {
            if (!$image instanceof ProductImage || isset($map[$image->productId()])) {
                continue;
            }
            $map[$image->productId()] = '/media/' . $image->mediaAssetId() . '/file';
        }

        return $map;
    }

    private function mediaAvailable(): bool
    {
        return $this->capabilities->has('media') && class_exists(\Modules\Media\Domain\MediaAsset::class);
    }

    /**
     * @param list<ProductImage> $images
     * @return array<string, \Modules\Media\Domain\MediaAsset>
     */
    private function resolveImageAssets(array $images): array
    {
        if (!$this->mediaAvailable() || $images === []) {
            return [];
        }

        $map = [];
        foreach ($images as $image) {
            $asset = $this->em->find(\Modules\Media\Domain\MediaAsset::class, $image->mediaAssetId());
            if ($asset instanceof \Modules\Media\Domain\MediaAsset) {
                $map[$image->id()] = $asset;
            }
        }

        return $map;
    }

    /**
     * @param list<ProductImage> $attached
     * @return list<array{value: string, label: string}>
     */
    private function libraryImageOptions(array $attached): array
    {
        if (!$this->mediaAvailable()) {
            return [];
        }

        $attachedIds = [];
        foreach ($attached as $image) {
            $attachedIds[$image->mediaAssetId()] = true;
        }

        $provider = $this->capabilities->get('media');
        if (!method_exists($provider, 'images')) {
            return [];
        }

        /** @var list<\Modules\Media\Domain\MediaAsset> $assets */
        $assets = $provider->images(80);
        $options = [];
        foreach ($assets as $asset) {
            if (isset($attachedIds[$asset->id()])) {
                continue;
            }
            $options[] = [
                'value' => $asset->id(),
                'label' => $asset->title() . ' (' . $asset->mime() . ')',
            ];
        }

        return $options;
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function imageDimensions(UploadedFile $uploaded, \Gereq\File\StoredFile $stored): array
    {
        if (!str_starts_with($stored->mime(), 'image/')) {
            return [null, null];
        }

        $meta = $stored->metadata();
        if (is_array($meta) && isset($meta['width'], $meta['height'])) {
            return [(int) $meta['width'], (int) $meta['height']];
        }

        $info = @getimagesize($uploaded->getPathname());
        if ($info === false) {
            return [null, null];
        }

        return [(int) $info[0], (int) $info[1]];
    }

    private function find(string $id): ?Product
    {
        $product = $this->em->find(Product::class, $id);

        return $product instanceof Product ? $product : null;
    }

    /**
     * @return array{
     *   sku: string,
     *   name: string,
     *   description: ?string,
     *   status: string,
     *   unit: string,
     *   category_ids: list<string>,
     *   customer_note_mode: string,
     *   image_rule: array{enabled: bool, max_images: int, crop_required: bool}
     * }
     */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'products.product.save');
        $description = trim((string) ($data['description'] ?? ''));
        $status = (string) ($data['status'] ?? 'draft');
        if (!in_array($status, Product::STATUSES, true)) {
            $status = 'draft';
        }

        $categoryIds = $data['category_ids'] ?? [];
        if (!is_array($categoryIds)) {
            $categoryIds = $categoryIds !== '' && $categoryIds !== null ? [(string) $categoryIds] : [];
        }
        $categoryIds = array_values(array_filter(array_map('strval', $categoryIds)));

        $noteMode = strtolower(trim((string) ($data['customer_note_mode'] ?? 'off')));
        if (!in_array($noteMode, ['off', 'optional', 'required'], true)) {
            $noteMode = 'off';
        }

        $enabled = $request->request->get('user_images_enabled') === '1'
            || $request->request->get('user_images_enabled') === 'on'
            || (string) ($data['user_images_enabled'] ?? '') === '1';

        return [
            'sku' => strtoupper(trim((string) $data['sku'])),
            'name' => trim((string) $data['name']),
            'description' => $description !== '' ? $description : null,
            'status' => $status,
            'unit' => trim((string) ($data['unit'] ?? 'ks')) ?: 'ks',
            'category_ids' => $categoryIds,
            'customer_note_mode' => $noteMode,
            'image_rule' => [
                'enabled' => $enabled,
                'max_images' => max(0, min(20, (int) ($data['user_images_max'] ?? 1))),
                'crop_required' => $request->request->get('user_images_crop') === '1'
                    || $request->request->get('user_images_crop') === 'on'
                    || (string) ($data['user_images_crop'] ?? '') === '1',
            ],
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

    /** @param array<string, mixed> $payload */
    private function emitStatusChange(string $previous, string $current, string $id, array $payload): void
    {
        if ($previous === $current) {
            return;
        }
        if ($current === 'active') {
            $this->events->dispatch(ProductActivated::make($id, $payload));
        }
        if ($previous === 'active' && $current !== 'active') {
            $this->events->dispatch(ProductDeactivated::make($id, $payload));
        }
    }

    /** @return list<array{value: string, label: string}> */
    private function categoryOptions(): array
    {
        $items = $this->em->getRepository(ProductCategory::class)->findBy([], ['name' => 'ASC']);
        $options = [];
        foreach ($items as $item) {
            /** @var ProductCategory $item */
            $options[] = ['value' => $item->id(), 'label' => $item->name()];
        }

        return $options;
    }

    /** @return list<array{value: string, label: string}> */
    private function currencyOptions(): array
    {
        $items = $this->em->getRepository(Currency::class)->findBy(['isActive' => true], ['code' => 'ASC']);
        $options = [];
        foreach ($items as $item) {
            /** @var Currency $item */
            $options[] = ['value' => $item->code(), 'label' => $item->code() . ' — ' . $item->name()];
        }
        if ($options === []) {
            $code = $this->money->defaultCode();
            $options[] = ['value' => $code, 'label' => $code];
        }

        return $options;
    }

    /** @return array<string, mixed> */
    private function publicProduct(Product $product): array
    {
        return array_merge([
            'id' => $product->id(),
            'sku' => $product->sku(),
            'name' => $product->name(),
            'description' => $product->description(),
            'status' => $product->status(),
            'unit' => $product->unit(),
            'customer_note_mode' => $product->customerNoteMode(),
            'category_ids' => array_map(
                static fn (ProductCategory $c): string => $c->id(),
                $product->categories()->toArray(),
            ),
            'created_at' => $product->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $product->updatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->personalization->serializeForStore($product));
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }
}
