<?php

declare(strict_types=1);

namespace Modules\Cms\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Security\HtmlSanitizer;
use Modules\Cms\Domain\Page;
use Modules\Cms\Domain\PageBlock;
use Modules\Cms\Domain\SiteMenu;
use Modules\Cms\Domain\SiteMenuItem;
use Modules\Media\Domain\MediaAsset;

final class CmsProvider
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HtmlSanitizer $html,
    ) {
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

    public function findPublishedBySlug(string $slug): ?Page
    {
        $page = $this->findPageBySlug($slug);

        return $page !== null && $page->isPublished() ? $page : null;
    }

    /** @return list<PageBlock> */
    public function blocksForPage(string $pageId): array
    {
        /** @var list<PageBlock> $blocks */
        $blocks = $this->em->getRepository(PageBlock::class)->findBy(
            ['pageId' => $pageId],
            ['sortOrder' => 'ASC'],
        );

        return $blocks;
    }

    public function findMenu(string $id): ?SiteMenu
    {
        $menu = $this->em->find(SiteMenu::class, $id);

        return $menu instanceof SiteMenu ? $menu : null;
    }

    /** @return list<SiteMenu> */
    public function menusByLocation(?string $location = null): array
    {
        $criteria = [];
        if ($location !== null && $location !== '') {
            $criteria['location'] = $location;
        }

        /** @var list<SiteMenu> $menus */
        $menus = $this->em->getRepository(SiteMenu::class)->findBy($criteria, ['name' => 'ASC']);

        return $menus;
    }

    /** @return list<SiteMenuItem> */
    public function menuItems(string $menuId): array
    {
        /** @var list<SiteMenuItem> $items */
        $items = $this->em->getRepository(SiteMenuItem::class)->findBy(
            ['menuId' => $menuId],
            ['sortOrder' => 'ASC'],
        );

        return $items;
    }

    /** @return list<Page> */
    public function publishedPages(int $limit = 50): array
    {
        /** @var list<Page> $pages */
        $pages = $this->em->getRepository(Page::class)->findBy(
            ['status' => 'published'],
            ['title' => 'ASC'],
            $limit,
        );

        return $pages;
    }

    /** @return array<string, mixed> */
    public function serializePage(Page $page, bool $withBlocks = false): array
    {
        $data = [
            'id' => $page->id(),
            'slug' => $page->slug(),
            'title' => $page->title(),
            'status' => $page->status(),
            'template' => $page->template(),
            'meta_description' => $page->metaDescription(),
            'published_at' => $page->publishedAt()?->format(\DateTimeInterface::ATOM),
            'updated_at' => $page->updatedAt()->format(\DateTimeInterface::ATOM),
        ];

        if ($withBlocks) {
            $data['blocks'] = array_map(
                fn (PageBlock $block): array => $this->serializeBlock($block),
                $this->blocksForPage($page->id()),
            );
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function serializeBlock(PageBlock $block): array
    {
        return [
            'id' => $block->id(),
            'type' => $block->type(),
            'sort_order' => $block->sortOrder(),
            'payload' => $this->publicPayload($block->type(), $block->payload()),
        ];
    }

    /** @return array<string, mixed> */
    public function serializeMenu(SiteMenu $menu, bool $withItems = true): array
    {
        $data = [
            'id' => $menu->id(),
            'name' => $menu->name(),
            'location' => $menu->location(),
        ];

        if ($withItems) {
            $data['items'] = array_map(
                fn (SiteMenuItem $item): array => $this->serializeMenuItem($item),
                $this->menuItems($menu->id()),
            );
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function serializeMenuItem(SiteMenuItem $item): array
    {
        $href = $item->url();
        $pageSlug = null;
        if ($item->pageId() !== null) {
            $page = $this->findPage($item->pageId());
            if ($page instanceof Page && $page->isPublished()) {
                $pageSlug = $page->slug();
                $href = '/' . $page->slug();
            }
        }

        return [
            'id' => $item->id(),
            'label' => $item->label(),
            'url' => $href,
            'page_id' => $item->pageId(),
            'page_slug' => $pageSlug,
            'parent_id' => $item->parentId(),
            'sort_order' => $item->sortOrder(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function publicPayload(string $type, array $payload): array
    {
        if ($type === 'text' && isset($payload['body'])) {
            $payload['body'] = $this->html->sanitize((string) $payload['body']);
        }
        if ($type === 'html' && isset($payload['html'])) {
            $payload['html'] = $this->html->sanitize((string) $payload['html']);
        }
        if ($type === 'columns') {
            if (isset($payload['left'])) {
                $payload['left'] = $this->html->sanitize((string) $payload['left']);
            }
            if (isset($payload['right'])) {
                $payload['right'] = $this->html->sanitize((string) $payload['right']);
            }
        }

        if ($type === 'image') {
            $mediaId = trim((string) ($payload['media_id'] ?? ''));
            $url = trim((string) ($payload['url'] ?? ''));
            if ($url === '' && $mediaId !== '') {
                $url = $this->mediaPublicUrl($mediaId) ?? '';
            }
            $payload['url'] = $url !== '' ? $url : null;
            $payload['media_id'] = $mediaId !== '' ? $mediaId : null;

            return $payload;
        }

        if ($type === 'gallery') {
            $raw = $payload['media_ids'] ?? '';
            $ids = [];
            if (is_string($raw)) {
                $parts = preg_split('~[\s,;]+~', $raw) ?: [];
                foreach ($parts as $part) {
                    $id = trim((string) $part);
                    if ($id !== '') {
                        $ids[] = $id;
                    }
                }
            } elseif (is_array($raw)) {
                foreach ($raw as $part) {
                    $id = trim((string) $part);
                    if ($id !== '') {
                        $ids[] = $id;
                    }
                }
            }

            $images = [];
            foreach ($ids as $id) {
                $url = $this->mediaPublicUrl($id);
                if ($url !== null) {
                    $images[] = ['media_id' => $id, 'url' => $url];
                }
            }
            $payload['media_ids'] = $ids;
            $payload['images'] = $images;
        }

        return $payload;
    }

    private function mediaPublicUrl(string $assetId): ?string
    {
        if (!class_exists(MediaAsset::class)) {
            return null;
        }

        $asset = $this->em->find(MediaAsset::class, $assetId);
        if (!$asset instanceof MediaAsset || !$asset->isImage()) {
            return null;
        }

        return '/media/' . $asset->id() . '/file';
    }
}
