<?php

declare(strict_types=1);

namespace Modules\Cms\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Hook\HookManager;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\FrozenPublicReader;
use Gereq\Security\HtmlSanitizer;
use Gereq\Setting\Settings;
use Modules\Cms\Domain\NavLink;
use Modules\Cms\Domain\Page;
use Modules\Cms\Domain\PageBlock;
use Modules\Cms\Domain\SiteMenu;
use Modules\Cms\Domain\SiteMenuItem;
use Gereq\Media\Application\MediaUrlBuilder;
use Gereq\Media\Domain\MediaAsset;

final class CmsProvider implements \Gereq\Cms\StorefrontSettingsProvider
{
    /** @var array<string, array<string, mixed>>|null media_id => responsive descriptor */
    private ?array $mediaUrlCache = null;

    /** @var array<string, Page|false> */
    private array $pageCache = [];

    /** @var array<string, Page|false> slug → page (request memo) */
    private array $pageBySlugCache = [];

    /** @var array<string, list<SiteMenuItem>> */
    private array $menuItemsCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HtmlSanitizer $html,
        private readonly ContentReadContext $readContext,
        private readonly FrozenPublicReader $frozen,
        private readonly Settings $settings,
        private readonly HookManager $hooks,
        private readonly DynamicElement $dynamicElements,
        private readonly MediaUrlBuilder $mediaUrls = new MediaUrlBuilder(),
    ) {
    }

    /** @return list<array{id: string, title: string}> */
    public function storefrontSettingsPages(): array
    {
        $pages = $this->em->getRepository(Page::class)->findBy([], ['title' => 'ASC']);

        return array_map(static fn (Page $page): array => ['id' => $page->id(), 'title' => $page->title()], $pages);
    }

    public function validateStorefrontHomePage(string $id): void
    {
        if ($id !== '' && ($this->findPage($id) === null || $this->frozen->isPendingDelete('cms.pages', $id))) {
            throw new \Gereq\Validation\ValidationException(['home_page_id' => 'cms.error.page_not_found']);
        }
    }

    public function saveStorefrontSettings(string $homePageId, string $publicCss): void
    {
        $this->settings->set('cms.home_page_id', $homePageId, \Gereq\Setting\SettingRecord::TYPE_STRING, 'cms');
        $this->settings->set('cms.custom_css_public', $publicCss, \Gereq\Setting\SettingRecord::TYPE_STRING, 'cms');
    }

    public function findPage(string $id): ?Page
    {
        if (array_key_exists($id, $this->pageCache)) {
            $hit = $this->pageCache[$id];

            return $hit instanceof Page ? $hit : null;
        }

        $page = $this->em->find(Page::class, $id);
        $this->pageCache[$id] = $page instanceof Page ? $page : false;
        if ($page instanceof Page) {
            $this->pageBySlugCache[strtolower($page->slug())] = $page;
        }

        return $page instanceof Page ? $page : null;
    }

    /**
     * @param list<string> $ids
     */
    public function warmPages(array $ids): void
    {
        $missing = [];
        foreach ($ids as $id) {
            $id = trim($id);
            if ($id !== '' && !array_key_exists($id, $this->pageCache)) {
                $missing[$id] = true;
            }
        }
        $missingIds = array_keys($missing);
        if ($missingIds === []) {
            return;
        }

        /** @var list<Page> $pages */
        $pages = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Page::class, 'p')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $missingIds)
            ->getQuery()
            ->getResult();

        foreach ($missingIds as $id) {
            $this->pageCache[$id] = false;
        }
        foreach ($pages as $page) {
            $this->pageCache[$page->id()] = $page;
            $this->pageBySlugCache[strtolower($page->slug())] = $page;
        }
    }

    /**
     * Batch-load menu items + linked pages (+ staging) before serializing menus.
     *
     * @param list<SiteMenu> $menus
     */
    public function warmMenusForSerialization(array $menus): void
    {
        $this->frozen->preloadAll();

        $menuIdsNeedingItems = [];
        $pageIds = [];
        foreach ($menus as $menu) {
            $snap = $this->frozen->snapshot('cms.menus', $menu->id());
            if (is_array($snap)) {
                if (is_array($snap['items'] ?? null)) {
                    foreach ($snap['items'] as $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        $pageId = trim((string) ($item['page_id'] ?? ''));
                        if ($pageId !== '') {
                            $pageIds[] = $pageId;
                        }
                    }
                }
                continue;
            }
            if (!array_key_exists($menu->id(), $this->menuItemsCache)) {
                $menuIdsNeedingItems[] = $menu->id();
            }
        }

        if ($menuIdsNeedingItems !== []) {
            /** @var list<SiteMenuItem> $items */
            $items = $this->em->createQueryBuilder()
                ->select('i')
                ->from(SiteMenuItem::class, 'i')
                ->where('i.menuId IN (:ids)')
                ->setParameter('ids', $menuIdsNeedingItems)
                ->orderBy('i.sortOrder', 'ASC')
                ->getQuery()
                ->getResult();

            $byMenu = array_fill_keys($menuIdsNeedingItems, []);
            foreach ($items as $item) {
                $byMenu[$item->menuId()][] = $item;
                $pageId = $item->pageId();
                if ($pageId !== null && $pageId !== '') {
                    $pageIds[] = $pageId;
                }
            }
            foreach ($byMenu as $menuId => $list) {
                $this->menuItemsCache[$menuId] = $list;
            }
        } else {
            foreach ($menus as $menu) {
                if (array_key_exists($menu->id(), $this->menuItemsCache)) {
                    foreach ($this->menuItemsCache[$menu->id()] as $item) {
                        $pageId = $item->pageId();
                        if ($pageId !== null && $pageId !== '') {
                            $pageIds[] = $pageId;
                        }
                    }
                }
            }
        }

        $this->warmPages($pageIds);
    }

    public function findPageBySlug(string $slug): ?Page
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return null;
        }

        if (array_key_exists($slug, $this->pageBySlugCache)) {
            $hit = $this->pageBySlugCache[$slug];

            return $hit instanceof Page ? $hit : null;
        }

        $page = $this->em->getRepository(Page::class)->findOneBy(['slug' => $slug]);
        $this->pageBySlugCache[$slug] = $page instanceof Page ? $page : false;
        if ($page instanceof Page) {
            $this->pageCache[$page->id()] = $page;
        }

        return $page instanceof Page ? $page : null;
    }

    public function findPublishedBySlug(string $slug): ?Page
    {
        $slug = strtolower(trim($slug));

        // The storefront requests the reserved home alias; resolve the configured page
        // using production settings and its frozen published state.
        if ($slug === 'home') {
            $homeId = $this->settings->get('cms.home_page_id', '');
            if (is_string($homeId) && $homeId !== '') {
                $page = $this->findPage($homeId);
                if ($page === null) {
                    return null;
                }
                if ($this->readContext->isProduction()) {
                    if ($this->frozen->isPendingCreate('cms.pages', $homeId)) {
                        return null;
                    }
                    $snapshot = $this->frozen->snapshot('cms.pages', $homeId);
                    if (is_array($snapshot)) {
                        return ($snapshot['status'] ?? '') === 'published' ? $page : null;
                    }
                }
                return $page->isPublished() ? $page : null;
            }
        }

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

    /**
     * Virtual menus built from pages + nav links (header / footer / custom).
     *
     * @return list<array<string, mixed>>
     */
    public function navMenus(?string $location = null): array
    {
        $locations = $location !== null && $location !== ''
            ? [$location]
            : Page::MENU_LOCATIONS;

        $out = [];
        foreach ($locations as $loc) {
            if (!in_array($loc, Page::MENU_LOCATIONS, true)) {
                continue;
            }
            $out[] = $this->navMenu($loc);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function navMenu(string $location): array
    {
        $items = [];

        foreach ($this->navPagesForLocation($location) as $row) {
            $items[] = $row;
        }
        foreach ($this->navLinksForLocation($location) as $row) {
            $items[] = $row;
        }

        usort(
            $items,
            static fn (array $a, array $b): int => ((int) $a['sort_order']) <=> ((int) $b['sort_order']),
        );

        return [
            'id' => 'nav-' . $location,
            'name' => $location,
            'location' => $location,
            'items' => array_values($items),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function navPagesForLocation(string $location): array
    {
        /** @var list<Page> $pages */
        $pages = $this->em->getRepository(Page::class)->findBy(
            [],
            ['menuSortOrder' => 'ASC', 'title' => 'ASC'],
        );

        $out = [];
        foreach ($pages as $page) {
            if ($this->frozen->isPendingDelete('cms.pages', $page->id())) {
                continue;
            }

            $title = $page->title();
            $slug = $page->slug();
            $sort = $page->menuSortOrder();
            $published = $page->isPublished();
            $inMenu = $page->inMenu($location);

            if ($this->readContext->isProduction()) {
                if ($this->frozen->isPendingCreate('cms.pages', $page->id())) {
                    continue;
                }
                $snap = $this->frozen->snapshot('cms.pages', $page->id());
                if (is_array($snap)) {
                    if (($snap['status'] ?? '') !== 'published') {
                        continue;
                    }
                    if (!$this->snapHasMenuLocation($snap, $location)) {
                        continue;
                    }
                    $title = (string) ($snap['title'] ?? $title);
                    $slug = (string) ($snap['slug'] ?? $slug);
                    $sort = (int) ($snap['menu_sort_order'] ?? $sort);
                    $published = true;
                    $inMenu = true;
                } elseif (!$published || !$inMenu) {
                    continue;
                }
            } elseif (!$published || !$inMenu) {
                continue;
            }

            $out[] = [
                'id' => $page->id(),
                'label' => $title,
                'url' => '/' . ltrim($slug, '/'),
                'page_id' => $page->id(),
                'page_slug' => $slug,
                'parent_id' => null,
                'sort_order' => $sort,
            ];
        }

        // Frozen pages that still belong to this location but were removed from working copy placement.
        if ($this->readContext->isProduction()) {
            foreach ($this->frozen->allSnapshots('cms.pages') as $hit) {
                $snap = $hit['snapshot'];
                if (($snap['status'] ?? '') !== 'published') {
                    continue;
                }
                if (!$this->snapHasMenuLocation($snap, $location)) {
                    continue;
                }
                $id = $hit['resource_key'];
                if (array_any($out, static fn (array $row): bool => $row['id'] === $id)) {
                    continue;
                }
                $slug = (string) ($snap['slug'] ?? '');
                $out[] = [
                    'id' => $id,
                    'label' => (string) ($snap['title'] ?? $slug),
                    'url' => '/' . ltrim($slug, '/'),
                    'page_id' => $id,
                    'page_slug' => $slug,
                    'parent_id' => null,
                    'sort_order' => (int) ($snap['menu_sort_order'] ?? 0),
                ];
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function navLinksForLocation(string $location): array
    {
        /** @var list<NavLink> $links */
        $links = $this->em->getRepository(NavLink::class)->findBy(
            [],
            ['menuSortOrder' => 'ASC', 'title' => 'ASC'],
        );

        $out = [];
        foreach ($links as $link) {
            if ($this->frozen->isPendingDelete('cms.links', $link->id())) {
                continue;
            }

            $title = $link->title();
            $url = $link->url();
            $sort = $link->menuSortOrder();
            $published = $link->isPublished();
            $inMenu = $link->inMenu($location);

            if ($this->readContext->isProduction()) {
                if ($this->frozen->isPendingCreate('cms.links', $link->id())) {
                    continue;
                }
                $snap = $this->frozen->snapshot('cms.links', $link->id());
                if (is_array($snap)) {
                    if (($snap['status'] ?? '') !== 'published') {
                        continue;
                    }
                    if (!$this->snapHasMenuLocation($snap, $location)) {
                        continue;
                    }
                    $title = (string) ($snap['title'] ?? $title);
                    $url = (string) ($snap['url'] ?? $url);
                    $sort = (int) ($snap['menu_sort_order'] ?? $sort);
                    $published = true;
                    $inMenu = true;
                } elseif (!$published || !$inMenu) {
                    continue;
                }
            } elseif (!$published || !$inMenu) {
                continue;
            }

            $out[] = [
                'id' => $link->id(),
                'label' => $title,
                'url' => $url,
                'page_id' => null,
                'page_slug' => null,
                'parent_id' => null,
                'sort_order' => $sort,
            ];
        }

        if ($this->readContext->isProduction()) {
            foreach ($this->frozen->allSnapshots('cms.links') as $hit) {
                $snap = $hit['snapshot'];
                if (($snap['status'] ?? '') !== 'published') {
                    continue;
                }
                if (!$this->snapHasMenuLocation($snap, $location)) {
                    continue;
                }
                $id = $hit['resource_key'];
                if (array_any($out, static fn (array $row): bool => $row['id'] === $id)) {
                    continue;
                }
                $out[] = [
                    'id' => $id,
                    'label' => (string) ($snap['title'] ?? ''),
                    'url' => (string) ($snap['url'] ?? '/'),
                    'page_id' => null,
                    'page_slug' => null,
                    'parent_id' => null,
                    'sort_order' => (int) ($snap['menu_sort_order'] ?? 0),
                ];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $snap */
    private function snapHasMenuLocation(array $snap, string $location): bool
    {
        $locations = $snap['menu_locations'] ?? null;
        if (is_array($locations)) {
            return in_array($location, $locations, true);
        }
        // Legacy single-location snapshots.
        return ($snap['menu_location'] ?? null) === $location;
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
        if (array_key_exists($menuId, $this->menuItemsCache)) {
            return $this->menuItemsCache[$menuId];
        }

        /** @var list<SiteMenuItem> $items */
        $items = $this->em->getRepository(SiteMenuItem::class)->findBy(
            ['menuId' => $menuId],
            ['sortOrder' => 'ASC'],
        );
        $this->menuItemsCache[$menuId] = $items;

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
    public function serializePage(Page $page, bool $withBlocks = false, bool $expandVariables = false): array
    {
        if ($this->readContext->isProduction()) {
            $snap = $this->frozen->snapshot('cms.pages', $page->id());
            if (is_array($snap)) {
                return $this->serializePageSnapshot($snap, $withBlocks, $expandVariables ? $page : null);
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
            'ssg' => $page->ssg(),
            'menu_locations' => $page->menuLocations(),
            'menu_location' => $page->menuLocations()[0] ?? null,
            'menu_sort_order' => $page->menuSortOrder(),
            'published_at' => $page->publishedAt()?->format(\DateTimeInterface::ATOM),
            'updated_at' => $page->updatedAt()->format(\DateTimeInterface::ATOM),
        ];

        if ($withBlocks) {
            if ($page->isRaw()) {
                $body = $this->html->sanitizeNullable($page->body());
                if ($expandVariables && is_string($body)) {
                    $body = ContentSecurity::expandHtml($body, $this->variableMap($page));
                }
                $data['body'] = $body;
                $data['blocks'] = [];
            } else {
                $data['body'] = null;
                $pageRef = $expandVariables ? $page : null;
                $blocks = $this->blocksForPage($page->id());
                $this->warmMediaUrls($this->collectMediaIdsFromBlocks($blocks));
                try {
                    $data['blocks'] = array_map(
                        fn (PageBlock $block): array => $this->serializeBlock($block, $pageRef),
                        $blocks,
                    );
                } finally {
                    $this->mediaUrlCache = null;
                }
            }
        }

        /** @var array<string, mixed> $filtered */
        $filtered = $this->hooks->filter('cms.serialize_page', $data, $page);

        return is_array($filtered) ? $filtered : $data;
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
    public function serializeBlock(PageBlock $block, ?Page $page = null): array
    {
        $variables = $page !== null ? $this->variableMap($page) : [];
        $payload = $this->publicPayload($block->type(), $block->payload(), $variables);
        if ($page !== null) {
            $payload = $this->expandPayloadVariables($payload, $variables);
        }

        return [
            'id' => $block->id(),
            'type' => $block->type(),
            'sort_order' => $block->sortOrder(),
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $snap
     * @return array<string, mixed>
     */
    private function serializePageSnapshot(array $snap, bool $withBlocks, ?Page $expandFor = null): array
    {
        $data = [
            'id' => (string) ($snap['id'] ?? ''),
            'slug' => (string) ($snap['slug'] ?? ''),
            'title' => (string) ($snap['title'] ?? ''),
            'status' => (string) ($snap['status'] ?? 'draft'),
            'template' => (string) ($snap['template'] ?? 'default'),
            'content_mode' => (string) ($snap['content_mode'] ?? 'blocks'),
            'meta_description' => $snap['meta_description'] ?? null,
            'ssg' => (bool) ($snap['ssg'] ?? false),
            'menu_locations' => is_array($snap['menu_locations'] ?? null)
                ? array_values(array_filter($snap['menu_locations'], static fn ($v): bool => is_string($v)))
                : (isset($snap['menu_location']) && is_string($snap['menu_location']) && $snap['menu_location'] !== ''
                    ? [$snap['menu_location']]
                    : []),
            'menu_location' => is_array($snap['menu_locations'] ?? null)
                ? (($snap['menu_locations'][0] ?? null) ?: null)
                : ($snap['menu_location'] ?? null),
            'menu_sort_order' => (int) ($snap['menu_sort_order'] ?? 0),
            'published_at' => $snap['published_at'] ?? null,
            'updated_at' => $snap['published_at'] ?? null,
        ];

        if ($withBlocks) {
            if (($snap['content_mode'] ?? '') === 'raw') {
                $body = $this->html->sanitizeNullable(isset($snap['body']) ? (string) $snap['body'] : null);
                if ($expandFor !== null && is_string($body)) {
                    $body = ContentSecurity::expandHtml($body, $this->variableMap($expandFor));
                }
                $data['body'] = $body;
                $data['blocks'] = [];
            } else {
                $data['body'] = null;
                $blocks = is_array($snap['blocks'] ?? null) ? $snap['blocks'] : [];
                $data['blocks'] = [];
                $vars = $expandFor !== null ? $this->variableMap($expandFor) : null;
                $this->warmMediaUrls($this->collectMediaIdsFromPayloadBlocks($blocks));
                try {
                    foreach ($blocks as $block) {
                        if (!is_array($block)) {
                            continue;
                        }
                        $type = (string) ($block['type'] ?? 'text');
                        $payload = is_array($block['payload'] ?? null) ? $block['payload'] : [];
                        $payload = $this->publicPayload($type, $payload, $vars ?? []);
                        if ($vars !== null) {
                            $payload = $this->expandPayloadVariables($payload, $vars);
                        }
                        $data['blocks'][] = [
                            'id' => (string) ($block['id'] ?? ''),
                            'type' => $type,
                            'sort_order' => (int) ($block['sort_order'] ?? 0),
                            'payload' => $payload,
                        ];
                    }
                } finally {
                    $this->mediaUrlCache = null;
                }
            }
        }

        /** @var array<string, mixed> $filtered */
        $filtered = $this->hooks->filter('cms.serialize_page', $data, $expandFor);

        return is_array($filtered) ? $filtered : $data;
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
     * @param array<string, string> $variables
     * @return array<string, mixed>
     */
    private function publicPayload(string $type, array $payload, array $variables = []): array
    {
        if ($type === 'dynamic') {
            $resolved = $this->dynamicElements->resolve($payload, $variables);
            foreach ($resolved['resolved_items'] as &$itemBlocks) {
                foreach ($itemBlocks as &$itemBlock) {
                    $itemBlock['payload'] = $this->publicPayload($itemBlock['type'], $itemBlock['payload']);
                }
                unset($itemBlock);
            }
            unset($itemBlocks);
            if ($resolved['resolved_items'] !== []) {
                $renderer = new CmsSsgHtmlRenderer();
                $resolved['rendered_items'] = array_map($renderer->renderFragment(...), $resolved['resolved_items']);
            }
            return $resolved;
        }
        if ($type === 'columns') {
            foreach (['left', 'right'] as $field) {
                if (isset($payload[$field])) {
                    $payload[$field] = $this->html->sanitize((string) $payload[$field]);
                }
            }
        }
        if ($type === 'video') {
            $payload['url'] = ContentSecurity::link((string) ($payload['url'] ?? $payload['src'] ?? ''));
        }
        if ($type === 'cta') {
            $payload['label'] = $this->html->sanitize((string) ($payload['label'] ?? ''));
            $payload['url'] = ContentSecurity::link((string) ($payload['url'] ?? ''));
        }
        if ($type === 'heading' && isset($payload['text'])) {
            $payload['text'] = $this->html->sanitize((string) $payload['text']);
        }
        if ($type === 'text' && isset($payload['body'])) {
            $payload['body'] = $this->html->sanitize((string) $payload['body']);
        }
        if ($type === 'html' && isset($payload['html'])) {
            $payload['html'] = $this->html->sanitize((string) $payload['html']);
        }

        if (is_array($payload['children'] ?? null)) {
            foreach ($payload['children'] as $index => $child) {
                if (is_array($child) && is_string($child['type'] ?? null) && is_array($child['payload'] ?? null)) {
                    $payload['children'][$index]['payload'] = $this->publicPayload($child['type'], $child['payload'], $variables);
                }
            }
        }

        if ($type === 'image') {
            $mediaId = trim((string) ($payload['media_id'] ?? ''));
            $url = trim((string) ($payload['url'] ?? ''));
            $descriptor = $mediaId !== '' ? $this->mediaDescriptor($mediaId) : null;
            if ($url === '' && $descriptor !== null) {
                $url = (string) $descriptor['url'];
            }
            $payload['url'] = $url !== '' ? $url : null;
            $payload['media_id'] = $mediaId !== '' ? $mediaId : null;
            if ($descriptor !== null) {
                $payload['src'] = $descriptor['src'];
                $payload['srcset'] = $descriptor['srcset'];
                $payload['sizes'] = $descriptor['sizes'];
                $payload['width'] = $descriptor['width'];
                $payload['height'] = $descriptor['height'];
                $payload['mime'] = $descriptor['mime'];
                if (!isset($payload['alt']) || trim((string) $payload['alt']) === '') {
                    $payload['alt'] = $descriptor['alt'];
                }
            }

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
                $descriptor = $this->mediaDescriptor($id);
                if ($descriptor === null) {
                    continue;
                }
                $images[] = [
                    'media_id' => $id,
                    'url' => $descriptor['url'],
                    'src' => $descriptor['src'],
                    'srcset' => $descriptor['srcset'],
                    'sizes' => $descriptor['sizes'],
                    'width' => $descriptor['width'],
                    'height' => $descriptor['height'],
                    'alt' => $descriptor['alt'],
                    'mime' => $descriptor['mime'],
                ];
            }
            $payload['media_ids'] = $ids;
            $payload['images'] = $images;
        }

        if ($type === 'table' && isset($payload['html'])) {
            $payload['html'] = $this->html->sanitize((string) $payload['html']);
            $payload['caption'] = $this->html->sanitize((string) ($payload['caption'] ?? ''));
        }

        return $payload;
    }

    /**
     * @return array{
     *     id: string,
     *     src: string,
     *     url: string,
     *     srcset: string,
     *     sizes: string,
     *     width: int|null,
     *     height: int|null,
     *     alt: string|null,
     *     mime: string
     * }|null
     */
    private function mediaDescriptor(string $assetId, string $layout = MediaUrlBuilder::LAYOUT_CONSTRAINED): ?array
    {
        if ($assetId === '') {
            return null;
        }
        if ($this->mediaUrlCache !== null) {
            $cached = $this->mediaUrlCache[$assetId] ?? null;

            return is_array($cached) ? $cached : null;
        }
        if (!class_exists(MediaAsset::class)) {
            return null;
        }

        $asset = $this->em->find(MediaAsset::class, $assetId);
        if (!$asset instanceof MediaAsset) {
            return null;
        }

        return $this->mediaUrls->forAsset($asset, $layout);
    }

    /**
     * @param list<PageBlock> $blocks
     * @return list<string>
     */
    private function collectMediaIdsFromBlocks(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            foreach ($this->mediaIdsFromPayload($block->type(), $block->payload()) as $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param list<mixed> $blocks
     * @return list<string>
     */
    private function collectMediaIdsFromPayloadBlocks(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            $payload = is_array($block['payload'] ?? null) ? $block['payload'] : [];
            foreach ($this->mediaIdsFromPayload($type, $payload) as $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function mediaIdsFromPayload(string $type, array $payload): array
    {
        if ($type === 'dynamic') {
            return is_array($payload['item_blocks'] ?? null) ? $this->collectMediaIdsFromPayloadBlocks($payload['item_blocks']) : [];
        }
        if ($type === 'image') {
            $id = trim((string) ($payload['media_id'] ?? ''));

            return $id !== '' ? [$id] : [];
        }
        if ($type !== 'gallery') {
            return is_array($payload['children'] ?? null)
                ? $this->collectMediaIdsFromPayloadBlocks($payload['children'])
                : [];
        }
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

        return $ids;
    }

    /** @param list<string> $ids */
    private function warmMediaUrls(array $ids): void
    {
        $unique = [];
        foreach ($ids as $id) {
            $id = trim($id);
            if ($id !== '') {
                $unique[$id] = true;
            }
        }
        $list = array_keys($unique);
        if ($list === [] || !class_exists(MediaAsset::class)) {
            $this->mediaUrlCache = [];

            return;
        }

        /** @var list<MediaAsset> $assets */
        $assets = $this->em->createQueryBuilder()
            ->select('a')
            ->from(MediaAsset::class, 'a')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', $list)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($assets as $asset) {
            $descriptor = $this->mediaUrls->forAsset($asset, MediaUrlBuilder::LAYOUT_CONSTRAINED);
            if ($descriptor !== null) {
                $map[$asset->id()] = $descriptor;
            }
        }
        $this->mediaUrlCache = $map;
    }

    /** @return array<string, string> */
    private function variableMap(Page $page): array
    {
        $siteTitle = $this->resolveSiteTitle();
        $brandName = (string) $this->settings->get('brand.name', '');

        return [
            'page.title' => $page->title(),
            'page.slug' => $page->slug(),
            'page.meta' => (string) ($page->metaDescription() ?? ''),
            'page.url' => '/' . ltrim($page->slug(), '/'),
            'site.title' => $siteTitle,
            'site.home' => '/',
            'brand.name' => $brandName !== '' ? $brandName : $siteTitle,
            'brand.logo' => (string) $this->settings->get('brand.logo_url', ''),
            'layout.gap' => '16',
            'layout.columns' => '12',
        ] + BrandColorVariables::resolve($this->settings);
    }

    /**
     * Recursively expand {{ path }} placeholders in string payload values.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $vars
     * @return array<string, mixed>
     */
    private function expandPayloadVariables(array $payload, array $vars): array
    {
        foreach ($payload as $key => $value) {
            // Entity templates and escaped entity output have their own interpolation boundary.
            if (in_array($key, ['item_template', 'item_blocks', 'rendered_items', 'resolved_items'], true)) {
                continue;
            }
            if (is_string($value)) {
                $expanded = in_array($key, ['html', 'body', 'text', 'label', 'caption', 'left', 'right'], true)
                    ? ContentSecurity::expandHtml($value, $vars)
                    : $this->expandVariables($value, $vars);
                $payload[$key] = in_array($key, ['url', 'href'], true) ? ContentSecurity::link($expanded) : $expanded;
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $nested */
                $nested = $value;
                $payload[$key] = $this->expandPayloadVariables($nested, $vars);
            }
        }

        return $payload;
    }

    /** @param array<string, string> $vars */
    private function expandVariables(string $text, array $vars): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            static function (array $match) use ($vars): string {
                $path = $match[1];

                return array_key_exists($path, $vars) ? $vars[$path] : $match[0];
            },
            $text,
        );
    }

    private function resolveSiteTitle(): string
    {
        foreach (['brand.site_name', 'brand.name', 'cms.site_title'] as $key) {
            $value = trim((string) $this->settings->get($key, ''));
            if ($value !== '' && !($key === 'cms.site_title' && $value === 'GEREQ Site')) {
                return $value;
            }
        }

        return 'Site';
    }
}
