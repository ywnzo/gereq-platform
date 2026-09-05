<?php

declare(strict_types=1);

namespace Modules\ProductDiscounts\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\ProductDiscounts\Application\ProductDiscountsProvider;
use Modules\ProductDiscounts\Domain\ProductDiscount;
use Modules\Products\Domain\Product;
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
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Auditor $auditor,
        private readonly EventBus $events,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $qb = $this->em->getRepository(ProductDiscount::class)->createQueryBuilder('d')
            ->orderBy('d.updatedAt', 'DESC');

        if ($search !== '') {
            $matched = $this->em->getRepository(Product::class)->createQueryBuilder('p')
                ->where('LOWER(p.name) LIKE :q OR LOWER(p.sku) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($search) . '%')
                ->getQuery()
                ->getResult();
            $ids = [];
            foreach ($matched as $product) {
                if ($product instanceof Product) {
                    $ids[] = $product->id();
                }
            }
            if ($ids === []) {
                $qb->andWhere('1 = 0');
            } else {
                $qb->andWhere('d.productId IN (:pids)')->setParameter('pids', $ids);
            }
        }

        /** @var list<ProductDiscount> $items */
        $items = $qb->getQuery()->getResult();
        $productMap = $this->productMap(array_map(static fn (ProductDiscount $d): string => $d->productId(), $items));

        return $this->page('@productdiscounts/index.html.twig', [
            'pageTitle' => $this->translator->trans('product_discounts.title'),
            'items' => $items,
            'products' => $productMap,
            'search' => $search,
        ]);
    }

    public function create(): Response
    {
        return $this->page('@productdiscounts/form.html.twig', [
            'pageTitle' => $this->translator->trans('product_discounts.new'),
            'item' => null,
            'product' => null,
            'kinds' => ProductDiscount::KINDS,
            'productOptions' => $this->productOptions(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request, requireProduct: true);
        $product = $this->em->find(Product::class, $data['product_id']);
        if (!$product instanceof Product) {
            $this->abort(404, $this->translator->trans('product_discounts.error.product_not_found'));
        }
        if ($this->discounts->findForProduct($product->id()) !== null) {
            $this->flash('error', $this->translator->trans('product_discounts.error.exists'));

            return $this->redirect('admin.product_discounts.create');
        }

        $discount = $this->discounts->upsert($product->id(), $data);
        $this->events->emit('product_discount.saved', ['type' => 'product_discount', 'id' => $discount->id()], $this->discounts->serialize($discount) ?? []);
        $this->auditor->log('product_discounts.created', 'product_discount', $discount->id(), null, [
            'product_id' => $product->id(),
            'kind' => $discount->kind(),
            'value' => $discount->value(),
        ]);
        $this->forgetForm('product_discounts.save');
        $this->flash('success', $this->translator->trans('product_discounts.flash.created'));

        return $this->redirect('admin.product_discounts.index');
    }

    public function edit(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('product_discounts.error.not_found'));
        }
        $product = $this->em->find(Product::class, $item->productId());

        return $this->page('@productdiscounts/form.html.twig', [
            'pageTitle' => $this->translator->trans('product_discounts.edit'),
            'item' => $item,
            'product' => $product instanceof Product ? $product : null,
            'kinds' => ProductDiscount::KINDS,
            'productOptions' => [],
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('product_discounts.error.not_found'));
        }
        $data = $this->validated($request, requireProduct: false);
        $discount = $this->discounts->upsert($item->productId(), $data);
        $this->events->emit('product_discount.saved', ['type' => 'product_discount', 'id' => $discount->id()], $this->discounts->serialize($discount) ?? []);
        $this->auditor->log('product_discounts.updated', 'product_discount', $discount->id());
        $this->forgetForm('product_discounts.save');
        $this->flash('success', $this->translator->trans('product_discounts.flash.saved'));

        return $this->redirect('admin.product_discounts.index');
    }

    public function destroy(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('product_discounts.error.not_found'));
        }
        $payload = $this->discounts->serialize($item) ?? [];
        $this->discounts->deleteForProduct($item->productId());
        $this->events->emit('product_discount.deleted', ['type' => 'product_discount', 'id' => $id], $payload);
        $this->auditor->log('product_discounts.deleted', 'product_discount', $id);
        $this->flash('success', $this->translator->trans('product_discounts.flash.deleted'));

        return $this->redirect('admin.product_discounts.index');
    }

    public function upsertForProduct(Request $request, string $productId): Response
    {
        $product = $this->em->find(Product::class, $productId);
        if (!$product instanceof Product) {
            $this->abort(404, $this->translator->trans('product_discounts.error.product_not_found'));
        }
        $data = $this->validated($request, requireProduct: false);
        $discount = $this->discounts->upsert($product->id(), $data);
        $this->events->emit('product_discount.saved', ['type' => 'product_discount', 'id' => $discount->id()], $this->discounts->serialize($discount) ?? []);
        $this->auditor->log('product_discounts.upserted', 'product_discount', $discount->id(), null, [
            'product_id' => $product->id(),
        ]);
        $this->forgetForm('product_discounts.save');
        $this->flash('success', $this->translator->trans('product_discounts.flash.saved'));

        return $this->redirectTo($this->router->url('admin.products.show', ['id' => $productId], $this->request()) . '#prices');
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

        return $this->redirectTo($this->router->url('admin.products.show', ['id' => $productId], $this->request()) . '#prices');
    }

    private function find(string $id): ?ProductDiscount
    {
        $item = $this->em->find(ProductDiscount::class, $id);

        return $item instanceof ProductDiscount ? $item : null;
    }

    /**
     * @return array{
     *   product_id: ?string,
     *   kind: string,
     *   value: string,
     *   description: ?string,
     *   starts_at: ?\DateTimeImmutable,
     *   ends_at: ?\DateTimeImmutable,
     *   active: bool
     * }
     */
    private function validated(Request $request, bool $requireProduct): array
    {
        $data = $this->validate($request, 'product_discounts.save');
        $productId = trim((string) ($data['product_id'] ?? $request->request->get('product_id', '')));
        if ($requireProduct && $productId === '') {
            $this->abort(422, $this->translator->trans('product_discounts.error.product_required'));
        }
        $activeRaw = $data['active'] ?? $request->request->get('active');

        return [
            'product_id' => $productId !== '' ? $productId : null,
            'kind' => strtolower(trim((string) ($data['kind'] ?? 'percent'))),
            'value' => number_format((float) ($data['value'] ?? 0), 2, '.', ''),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
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

    /** @param list<string> $ids @return array<string, Product> */
    private function productMap(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        /** @var list<Product> $products */
        $products = $this->em->getRepository(Product::class)->createQueryBuilder('p')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
        $map = [];
        foreach ($products as $product) {
            $map[$product->id()] = $product;
        }

        return $map;
    }

    /** @return list<array{value: string, label: string}> */
    private function productOptions(): array
    {
        $products = $this->em->getRepository(Product::class)->findBy([], ['name' => 'ASC'], 500);
        $out = [];
        foreach ($products as $product) {
            if (!$product instanceof Product) {
                continue;
            }
            if ($this->discounts->findForProduct($product->id()) !== null) {
                continue;
            }
            $out[] = [
                'value' => $product->id(),
                'label' => $product->name() . ' (' . $product->sku() . ')',
            ];
        }

        return $out;
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
