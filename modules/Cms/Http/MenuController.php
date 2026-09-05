<?php

declare(strict_types=1);

namespace Modules\Cms\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Cms\Domain\Page;
use Modules\Cms\Domain\SiteMenu;
use Modules\Cms\Domain\SiteMenuItem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class MenuController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        $items = $this->em->getRepository(SiteMenu::class)->findBy([], ['name' => 'ASC']);

        return $this->page('@cms/menus/index.html.twig', [
            'pageTitle' => $this->translator->trans('cms.menus.title'),
            'items' => $items,
        ]);
    }

    public function create(): Response
    {
        return $this->page('@cms/menus/form.html.twig', [
            'pageTitle' => $this->translator->trans('cms.menus.new'),
            'item' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, 'cms.menu.save');
        $menu = new SiteMenu(trim((string) $data['name']), (string) $data['location']);
        $this->em->persist($menu);
        $this->em->flush();
        $this->forgetForm('cms.menu.save');
        $this->flash('success', $this->translator->trans('cms.flash.menu_created', ['%name%' => $menu->name()]));

        return $this->redirect('admin.cms.menus.show', ['id' => $menu->id()]);
    }

    public function show(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('cms.error.menu_not_found'));
        }

        $menuItems = $this->em->getRepository(SiteMenuItem::class)->findBy(
            ['menuId' => $id],
            ['sortOrder' => 'ASC'],
        );

        $pageOptions = [];
        foreach ($this->em->getRepository(Page::class)->findBy([], ['title' => 'ASC']) as $page) {
            $pageOptions[] = ['value' => $page->id(), 'label' => $page->title() . ' (/' . $page->slug() . ')'];
        }

        return $this->page('@cms/menus/show.html.twig', [
            'pageTitle' => $item->name(),
            'item' => $item,
            'menuItems' => $menuItems,
            'pageOptions' => $pageOptions,
        ]);
    }

    public function edit(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('cms.error.menu_not_found'));
        }

        return $this->page('@cms/menus/form.html.twig', [
            'pageTitle' => $this->translator->trans('cms.menus.edit'),
            'item' => $item,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('cms.error.menu_not_found'));
        }

        $data = $this->validate($request, 'cms.menu.save');
        $item->setName(trim((string) $data['name']));
        $item->setLocation((string) $data['location']);
        $this->em->flush();
        $this->forgetForm('cms.menu.save');
        $this->flash('success', $this->translator->trans('cms.flash.menu_updated', ['%name%' => $item->name()]));

        return $this->redirect('admin.cms.menus.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('cms.error.menu_not_found'));
        }

        $name = $item->name();
        foreach ($this->em->getRepository(SiteMenuItem::class)->findBy(['menuId' => $id]) as $row) {
            $this->em->remove($row);
        }
        $this->em->remove($item);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('cms.flash.menu_deleted', ['%name%' => $name]));

        return $this->redirect('admin.cms.menus.index');
    }

    public function storeItem(Request $request, string $id): Response
    {
        $menu = $this->find($id);
        if ($menu === null) {
            $this->abort(404, $this->translator->trans('cms.error.menu_not_found'));
        }

        $data = $this->validate($request, 'cms.menu_item.save');
        $max = (int) $this->em->getRepository(SiteMenuItem::class)->createQueryBuilder('i')
            ->select('MAX(i.sortOrder)')
            ->where('i.menuId = :mid')
            ->setParameter('mid', $id)
            ->getQuery()
            ->getSingleScalarResult();

        $row = new SiteMenuItem($id, trim((string) $data['label']), $max + 1);
        $row->setUrl(isset($data['url']) ? (string) $data['url'] : null);
        $row->setPageId(isset($data['page_id']) ? (string) $data['page_id'] : null);
        $row->setParentId(isset($data['parent_id']) ? (string) $data['parent_id'] : null);
        $this->em->persist($row);
        $this->em->flush();
        $this->forgetForm('cms.menu_item.save');
        $this->flash('success', $this->translator->trans('cms.flash.menu_item_created'));

        return $this->redirect('admin.cms.menus.show', ['id' => $id]);
    }

    public function updateItem(Request $request, string $id, string $itemId): Response
    {
        $this->findOrFail($id);
        $row = $this->em->find(SiteMenuItem::class, $itemId);
        if (!$row instanceof SiteMenuItem || $row->menuId() !== $id) {
            $this->abort(404, $this->translator->trans('cms.error.menu_item_not_found'));
        }

        $data = $this->validate($request, 'cms.menu_item.save');
        $row->setLabel(trim((string) $data['label']));
        $row->setUrl(isset($data['url']) ? (string) $data['url'] : null);
        $row->setPageId(isset($data['page_id']) ? (string) $data['page_id'] : null);
        $row->setParentId(isset($data['parent_id']) ? (string) $data['parent_id'] : null);
        if (isset($data['sort_order']) && $data['sort_order'] !== '') {
            $row->setSortOrder((int) $data['sort_order']);
        }
        $this->em->flush();
        $this->forgetForm('cms.menu_item.save');
        $this->flash('success', $this->translator->trans('cms.flash.menu_item_updated'));

        return $this->redirect('admin.cms.menus.show', ['id' => $id]);
    }

    public function destroyItem(string $id, string $itemId): Response
    {
        $this->findOrFail($id);
        $row = $this->em->find(SiteMenuItem::class, $itemId);
        if (!$row instanceof SiteMenuItem || $row->menuId() !== $id) {
            $this->abort(404, $this->translator->trans('cms.error.menu_item_not_found'));
        }
        $this->em->remove($row);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('cms.flash.menu_item_deleted'));

        return $this->redirect('admin.cms.menus.show', ['id' => $id]);
    }

    private function find(string $id): ?SiteMenu
    {
        $menu = $this->em->find(SiteMenu::class, $id);

        return $menu instanceof SiteMenu ? $menu : null;
    }

    private function findOrFail(string $id): SiteMenu
    {
        $menu = $this->find($id);
        if ($menu === null) {
            $this->abort(404, $this->translator->trans('cms.error.menu_not_found'));
        }

        return $menu;
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
