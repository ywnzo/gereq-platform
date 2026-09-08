<?php

declare(strict_types=1);

namespace Modules\Cms\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\FrozenPublicReader;
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
        private readonly ContentReadContext $readContext,
        private readonly FrozenPublicReader $frozen,
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
        $slug = strtolower(trim($slug));

        if ($this->readContext->isProduction()) {
            $hit = $this->frozen->findSnapshotBy('cms.pages', 'slug', $slug);
            if ($hit !== null) {
                $snap = $hit['snapshot'];
                if (($snap['status'] ?? '') !== 'published') {
                    return null;
                }

                return $this->findPage($hit['resource_key']);
            }
        }

        $page = $this->findPageBySlug($slug);
        if ($page === null || !$page->isPublished()) {
            return null;
        }

        if ($this->readContext->isProduction()) {
            if ($this->frozen->isPendingCreate('cms.pages', $page->id())) {
                return null;
            }
            if ($this->frozen->isFrozen('cms.pages', $page->id())) {
                $snap = $this->frozen->snapshot('cms.pages', $page->id());
                if (is_array($snap) && (string) ($snap['slug'] ?? '') !== $slug) {
                    return null;
                }
            }
        }

        return $page;
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

        if (!$this->readContext->isProduction()) {
            // Admin: hide soft-deleted menus.
            $pendingDelete = array_flip($this->frozen->pendingDeleteKeys('cms.menus'));

            return array_values(array_filter(
                $menus,
                static fn (SiteMenu $m): bool => !isset($pendingDelete[$m->id()]),
            ));
        }

        // Public: hide pending creates; keep pending deletes (served via freeze snapshot).
        $pendingCreate = array_flip($this->frozen->pendingCreateKeys('cms.menus'));

        return array_values(array_filter(
            $menus,
            static fn (SiteMenu $m): bool => !isset($pendingCreate[$m->id()]),
        ));
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

        if (!$this->readContext->isProduction()) {
            $pendingDelete = array_flip($this->frozen->pendingDeleteKeys('cms.pages'));

            return array_values(array_filter(
                $pages,
                static fn (Page $p): bool => !isset($pendingDelete[$p->id()]),
            ));
        }

        $out = [];
        foreach ($pages as $page) {
            if ($this->frozen->isPendingCreate('cms.pages', $page->id())) {
                continue;
            }
            $snap = $this->frozen->snapshot('cms.pages', $page->id());
            if ($snap !== null) {
                if (($snap['status'] ?? '') !== 'published') {
                    continue;
                }
            }
            $out[] = $page;
        }

        // Include pages unpublished in working copy but still published in freeze.
        foreach ($this->frozen->allSnapshots('cms.pages') as $hit) {
            $snap = $hit['snapshot'];
            if (($snap['status'] ?? '') !== 'published') {
                continue;
            }
            $id = $hit['resource_key'];
            if (array_any($out, static fn (Page $p): bool => $p->id() === $id)) {
                continue;
            }
            $page = $this->findPage($id);
            if ($page instanceof Page) {
                $out[] = $page;
            }
        }

        return array_slice($out, 0, $limit);
    }

    /** @return array<string, mixed> */
    public function serializePage(Page $page, bool $withBlocks = false): array
    {
        if ($this->readContext->isProduction()) {
            $snap = $this->frozen->snapshot('cms.pages', $page->id());
            if (is_array($snap)) {
                return $this->serializePageSnapshot($snap, $withBlocks);
            }
        }

        $data = [
            'id' => $page->id(),
            'slug' => $page->slug(),
            'title' => $page->title(),
            'status' => $page->status(),
            'template' => $page->template(),
            'content_mode' => $page->contentMode(),
            'meta_description' => $page->metaDescription(),
            'published_at' => $page->publishedAt()?->format(\DateTimeInterface::ATOM),
            'updated_at' => $page->updatedAt()->format(\DateTimeInterface::ATOM),
        ];

        if ($withBlocks) {
            if ($page->isRaw()) {
                $data['body'] = $this->html->sanitizeNullable($page->body());
                $data['blocks'] = [];
            } else {
                $data['body'] = null;
                $data['blocks'] = array_map(
                    fn (PageBlock $block): array => $this->serializeBlock($block),
                    $this->blocksForPage($page->id()),
                );
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function serializeMenu(SiteMenu $menu, bool $withItems = true): array
    {
        if ($this->readContext->isProduction()) {
            $snap = $this->frozen->snapshot('cms.menus', $menu->id());
            if (is_array($snap)) {
                return $this->serializeMenuSnapshot($snap, $withItems);
            }
        }

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
    public function serializeBlock(PageBlock $block): array
    {
        return [
            'id' => $block->id(),
            'type' => $block->type(),
            'sort_order' => $block->sortOrder(),
            'payload' => $this->publicPayload($block->type(), $block->payload()),
        ];
    }

    /**
     * @param array<string, mixed> $snap
     * @return array<string, mixed>
     */
    private function serializePageSnapshot(array $snap, bool $withBlocks): array
    {
        $data = [
            'id' => (string) ($snap['id'] ?? ''),
            'slug' => (string) ($snap['slug'] ?? ''),
            'title' => (string) ($snap['title'] ?? ''),
            'status' => (string) ($snap['status'] ?? 'draft'),
            'template' => (string) ($snap['template'] ?? 'default'),
            'content_mode' => (string) ($snap['content_mode'] ?? 'blocks'),
            'meta_description' => $snap['meta_description'] ?? null,
            'published_at' => $snap['published_at'] ?? null,
            'updated_at' => $snap['published_at'] ?? null,
        ];

        if ($withBlocks) {
            if (($snap['content_mode'] ?? '') === 'raw') {
                $data['body'] = $this->html->sanitizeNullable(isset($snap['body']) ? (string) $snap['body'] : null);
                $data['blocks'] = [];
            } else {
                $data['body'] = null;
                $blocks = is_array($snap['blocks'] ?? null) ? $snap['blocks'] : [];
                $data['blocks'] = [];
                foreach ($blocks as $block) {
                    if (!is_array($block)) {
                        continue;
                    }
                    $type = (string) ($block['type'] ?? 'text');
                    $payload = is_array($block['payload'] ?? null) ? $block['payload'] : [];
                    $data['blocks'][] = [
                        'id' => (string) ($block['id'] ?? ''),
                        'type' => $type,
                        'sort_order' => (int) ($block['sort_order'] ?? 0),
                        'payload' => $this->publicPayload($type, $payload),
                    ];
                }
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $snap
     * @return array<string, mixed>
     */
    private function serializeMenuSnapshot(array $snap, bool $withItems): array
    {
        $data = [
            'id' => (string) ($snap['id'] ?? ''),
            'name' => (string) ($snap['name'] ?? ''),
            'location' => (string) ($snap['location'] ?? ''),
        ];
        if ($withItems) {
            $items = is_array($snap['items'] ?? null) ? $snap['items'] : [];
            $data['items'] = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $href = isset($item['url']) ? (string) $item['url'] : null;
                $pageSlug = null;
                $pageId = isset($item['page_id']) ? (string) $item['page_id'] : null;
                if ($pageId) {
                    $page = $this->findPage($pageId);
                    if ($page instanceof Page) {
                        $serialized = $this->serializePage($page, false);
                        if (($serialized['status'] ?? '') === 'published') {
                            $pageSlug = (string) $serialized['slug'];
                            $href = '/' . $pageSlug;
                        }
                    }
                }
                $data['items'][] = [
                    'id' => (string) ($item['id'] ?? ''),
                    'label' => (string) ($item['label'] ?? ''),
                    'url' => $href,
                    'page_id' => $pageId,
                    'page_slug' => $pageSlug,
                    'parent_id' => $item['parent_id'] ?? null,
                    'sort_order' => (int) ($item['sort_order'] ?? 0),
                ];
            }
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
