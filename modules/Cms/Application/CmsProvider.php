<?php

declare(strict_types=1);

namespace Modules\Cms\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Cms\Domain\Page;
use Modules\Cms\Domain\PageBlock;
use Modules\Cms\Domain\SiteMenu;
use Modules\Cms\Domain\SiteMenuItem;

final class CmsProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findPage(string $id): ?Page
    {
        $page = $this->em->find(Page::class, $id);

        return $page instanceof Page ? $page : null;
    }

    public function findPageBySlug(string $slug): ?Page
    {
        $page = $this->em->getRepository(Page::class)->findOneBy(['slug' => strtolower(trim($slug))]);

        return $page instanceof Page ? $page : null;
    }

    /** @return list<PageBlock> */
    public function blocksForPage(string $pageId): array
    {
        return $this->em->getRepository(PageBlock::class)->findBy(
            ['pageId' => $pageId],
            ['sortOrder' => 'ASC'],
        );
    }

    public function findMenu(string $id): ?SiteMenu
    {
        $menu = $this->em->find(SiteMenu::class, $id);

        return $menu instanceof SiteMenu ? $menu : null;
    }

    /** @return list<SiteMenuItem> */
    public function menuItems(string $menuId): array
    {
        return $this->em->getRepository(SiteMenuItem::class)->findBy(
            ['menuId' => $menuId],
            ['sortOrder' => 'ASC'],
        );
    }

    /** @return list<Page> */
    public function publishedPages(int $limit = 50): array
    {
        return $this->em->getRepository(Page::class)->findBy(
            ['status' => 'published'],
            ['title' => 'ASC'],
            $limit,
        );
    }
}
