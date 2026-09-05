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
use Modules\Products\Domain\ProductCategory;
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
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        $items = $this->em->getRepository(ProductCategory::class)->findBy([], ['name' => 'ASC']);

        return $this->page('@products/categories/index.html.twig', [
            'pageTitle' => $this->translator->trans('products.categories.title'),
            'items' => $items,
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

        return $this->redirect('admin.products.categories.index');
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

        return $this->redirect('admin.products.categories.index');
    }

    public function destroy(string $id): Response
    {
        $category = $this->find($id);
        if ($category === null) {
            $this->abort(404, $this->translator->trans('products.categories.error.not_found'));
        }

        $name = $category->name();
        $this->em->remove($category);
        $this->em->flush();
        $this->auditor->log('product_category.deleted', 'product_category', $id);
        $this->flash('success', $this->translator->trans('products.categories.flash.deleted', ['%name%' => $name]));

        return $this->redirect('admin.products.categories.index');
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
