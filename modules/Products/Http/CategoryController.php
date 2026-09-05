<?php

declare(strict_types=1);

namespace Modules\Products\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Modules\Products\Application\ProductVariantOptionsService;
use Modules\Products\Domain\ProductCategory;
use Modules\Products\Domain\ProductOptionGroup;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CategoryController extends Controller
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
        private readonly ProductVariantOptionsService $variantOptions,
        private readonly CapabilityRegistry $capabilities,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $hasProducts = (string) $request->query->get('has_products', '');

        $qb = $this->em->getRepository(ProductCategory::class)->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC');
        if ($search !== '') {
            self_repair_search($qb, 'c', ['name', 'slug'], $search);
        }
        if ($hasProducts === '1') {
            $qb->andWhere('SIZE(c.products) > 0');
        } elseif ($hasProducts === '0') {
            $qb->andWhere('SIZE(c.products) = 0');
        }

        /** @var list<ProductCategory> $items */
        $items = $qb->getQuery()->getResult();

        $productCounts = [];
        $optionCounts = [];
        foreach ($items as $item) {
            $productCounts[$item->id()] = $item->products()->count();
            $optionCounts[$item->id()] = count($this->variantOptions->groupsForScope(
                ProductOptionGroup::SCOPE_CATEGORY,
                $item->id(),
            ));
        }

        return $this->page('@products/categories/index.html.twig', [
            'pageTitle' => $this->translator->trans('products.categories.title'),
            'items' => $items,
            'search' => $search,
            'has_products' => $hasProducts,
            'productCounts' => $productCounts,
            'optionCounts' => $optionCounts,
        ]);
    }

    public function create(): Response
    {
        return $this->page('@products/categories/form.html.twig', [
            'pageTitle' => $this->translator->trans('products.categories.new'),
            'item' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $existing = $this->em->getRepository(ProductCategory::class)->findOneBy(['slug' => $data['slug']]);
        if ($existing instanceof ProductCategory) {
            $this->flash('error', $this->translator->trans('products.categories.error.slug_exists'));

            return $this->redirect('admin.products.categories.create');
        }

        $category = new ProductCategory($data['name'], $data['slug']);
        $this->em->persist($category);
        $this->em->flush();
        $this->auditor->log('product_category.created', 'product_category', $category->id(), null, [
            'name' => $category->name(),
            'slug' => $category->slug(),
        ]);
        $this->forgetForm('products.category.save');
        $this->flash('success', $this->translator->trans('products.categories.flash.created', ['%name%' => $category->name()]));

        return $this->redirect('admin.products.categories.edit', ['id' => $category->id()]);
    }

    public function edit(string $id): Response
    {
        $category = $this->find($id);
        if ($category === null) {
            $this->abort(404, $this->translator->trans('products.categories.error.not_found'));
        }

        return $this->page('@products/categories/form.html.twig', [
            'pageTitle' => $this->translator->trans('products.categories.edit'),
            'item' => $category,
            'optionGroups' => $this->variantOptions->groupsForScope(
                ProductOptionGroup::SCOPE_CATEGORY,
                $category->id(),
            ),
            'hasProductDiscounts' => $this->capabilities->has('product_discounts'),
            'categoryDiscounts' => $this->capabilities->has('product_discounts')
                && class_exists(\Modules\ProductDiscounts\Application\ProductDiscountsProvider::class)
                ? $this->capabilities->get('product_discounts')->listForCategory($category->id())
                : [],
            'discountConditions' => class_exists(\Modules\ProductDiscounts\Domain\ProductDiscount::class)
                ? \Modules\ProductDiscounts\Domain\ProductDiscount::CONDITIONS
                : ['none'],
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $category = $this->find($id);
        if ($category === null) {
            $this->abort(404, $this->translator->trans('products.categories.error.not_found'));
        }

        $data = $this->validated($request);
        $slugOwner = $this->em->getRepository(ProductCategory::class)->findOneBy(['slug' => $data['slug']]);
        if ($slugOwner instanceof ProductCategory && $slugOwner->id() !== $category->id()) {
            $this->flash('error', $this->translator->trans('products.categories.error.slug_exists'));

            return $this->redirect('admin.products.categories.edit', ['id' => $id]);
        }

        $old = ['name' => $category->name(), 'slug' => $category->slug()];
        $category->setName($data['name']);
        $category->setSlug($data['slug']);
        $this->em->flush();
        $this->auditor->log('product_category.updated', 'product_category', $category->id(), $old, [
            'name' => $category->name(),
            'slug' => $category->slug(),
        ]);
        $this->forgetForm('products.category.save');
        $this->flash('success', $this->translator->trans('products.categories.flash.saved'));

        return $this->redirect('admin.products.categories.edit', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $category = $this->find($id);
        if ($category === null) {
            $this->abort(404, $this->translator->trans('products.categories.error.not_found'));
        }

        $name = $category->name();
        foreach ($this->variantOptions->groupsForScope(ProductOptionGroup::SCOPE_CATEGORY, $category->id()) as $row) {
            $this->variantOptions->deleteGroup($row['group']);
        }
        $this->em->remove($category);
        $this->em->flush();
        $this->auditor->log('product_category.deleted', 'product_category', $id);
        $this->flash('success', $this->translator->trans('products.categories.flash.deleted', ['%name%' => $name]));

        return $this->redirect('admin.products.categories.index');
    }

    public function bulk(Request $request): Response
    {
        $data = $this->validate($request, 'products.categories.bulk');
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

            return $this->redirect('admin.products.categories.index');
        }
        if ($action === 'delete' && !$request->request->getBoolean('confirmed')) {
            $this->flash('error', $this->translator->trans('products.bulk.error.confirm'));

            return $this->redirect('admin.products.categories.index');
        }

        $affected = 0;
        if ($action === 'delete') {
            foreach ($ids as $id) {
                $category = $this->find($id);
                if ($category === null) {
                    continue;
                }
                foreach ($this->variantOptions->groupsForScope(ProductOptionGroup::SCOPE_CATEGORY, $category->id()) as $row) {
                    $this->variantOptions->deleteGroup($row['group']);
                }
                $this->em->remove($category);
                $this->auditor->log('product_category.deleted', 'product_category', $id);
                $affected++;
            }
            $this->em->flush();
        }

        $this->flash('success', $this->translator->trans('products.categories.bulk.flash.delete', [
            '%count%' => (string) $affected,
        ]));

        return $this->redirect('admin.products.categories.index');
    }

    public function storeOptionGroup(Request $request, string $id): Response
    {
        $category = $this->find($id);
        if ($category === null) {
            $this->abort(404, $this->translator->trans('products.categories.error.not_found'));
        }
        $data = $this->validate($request, 'products.option_group.save');
        try {
            $this->variantOptions->createGroup(
                ProductOptionGroup::SCOPE_CATEGORY,
                $category->id(),
                (string) $data['name'],
                (string) ($data['options_text'] ?? ''),
                $this->truthy($request, $data, 'required', true),
            );
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.products.categories.edit', ['id' => $id]);
        }
        $this->flash('success', 'Kolonka kategorie vytvořena.');

        return $this->redirect('admin.products.categories.edit', ['id' => $id]);
    }

    public function updateOptionGroup(Request $request, string $id, string $groupId): Response
    {
        $category = $this->find($id);
        if ($category === null) {
            $this->abort(404, $this->translator->trans('products.categories.error.not_found'));
        }
        $group = $this->em->find(ProductOptionGroup::class, $groupId);
        if (
            !$group instanceof ProductOptionGroup
            || $group->scopeType() !== ProductOptionGroup::SCOPE_CATEGORY
            || $group->scopeId() !== $category->id()
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

            return $this->redirect('admin.products.categories.edit', ['id' => $id]);
        }
        $this->flash('success', 'Kolonka kategorie uložena.');

        return $this->redirect('admin.products.categories.edit', ['id' => $id]);
    }

    public function destroyOptionGroup(string $id, string $groupId): Response
    {
        $category = $this->find($id);
        if ($category === null) {
            $this->abort(404, $this->translator->trans('products.categories.error.not_found'));
        }
        $group = $this->em->find(ProductOptionGroup::class, $groupId);
        if (
            !$group instanceof ProductOptionGroup
            || $group->scopeType() !== ProductOptionGroup::SCOPE_CATEGORY
            || $group->scopeId() !== $category->id()
        ) {
            $this->abort(404);
        }
        $this->variantOptions->deleteGroup($group);
        $this->flash('success', 'Kolonka kategorie smazána.');

        return $this->redirect('admin.products.categories.edit', ['id' => $id]);
    }

    private function find(string $id): ?ProductCategory
    {
        $category = $this->em->find(ProductCategory::class, $id);

        return $category instanceof ProductCategory ? $category : null;
    }

    /** @return array{name: string, slug: string} */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'products.category.save');
        $name = trim((string) $data['name']);
        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = $this->slugify($name);
        } else {
            $slug = $this->slugify($slug);
        }

        return ['name' => $name, 'slug' => $slug];
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

    private function slugify(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $map = ['á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z'];
        $value = strtr($value, $map);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? $value : 'category';
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
