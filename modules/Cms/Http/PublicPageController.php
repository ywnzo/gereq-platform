<?php

declare(strict_types=1);

namespace Modules\Cms\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Gereq\Setting\Settings;
use Modules\Cms\Domain\Page;
use Modules\Cms\Domain\PageBlock;
use Modules\Cms\Domain\SiteMenu;
use Modules\Cms\Domain\SiteMenuItem;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class PublicPageController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly Settings $settings,
        private readonly CapabilityRegistry $capabilities,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function show(string $slug): Response
    {
        $page = $this->em->getRepository(Page::class)->findOneBy([
            'slug' => strtolower(trim($slug)),
            'status' => 'published',
        ]);
        if (!$page instanceof Page) {
            $this->abort(404, $this->translator->trans('cms.error.page_not_found'));
        }

        $blocks = $this->em->getRepository(PageBlock::class)->findBy(
            ['pageId' => $page->id()],
            ['sortOrder' => 'ASC'],
        );

        return $this->render('@cms/public/page.html.twig', [
            'pageTitle' => $page->title(),
            'item' => $page,
            'blocks' => $blocks,
            'siteTitle' => (string) $this->settings->get('cms.site_title', 'GEREQ Site'),
            'menus' => $this->menusForRender(),
            'preview' => false,
            'products' => $this->resolveProducts($blocks),
            'hasMedia' => $this->capabilities->has('media'),
        ]);
    }

    /**
     * @param list<PageBlock> $blocks
     * @return array<string, object>
     */
    private function resolveProducts(array $blocks): array
    {
        if (!$this->capabilities->has('products')) {
            return [];
        }
        $provider = $this->capabilities->get('products');
        if (!method_exists($provider, 'find')) {
            return [];
        }

        $map = [];
        foreach ($blocks as $block) {
            if ($block->type() !== 'products') {
                continue;
            }
            $raw = $block->payload()['product_ids'] ?? [];
            if (is_string($raw)) {
                $parts = preg_split('~[\s,;]+~', $raw) ?: [];
            } elseif (is_array($raw)) {
                $parts = $raw;
            } else {
                $parts = [];
            }
            foreach ($parts as $pid) {
                $pid = trim((string) $pid);
                if ($pid === '' || isset($map[$pid])) {
                    continue;
                }
                $ref = $provider->find($pid);
                if ($ref !== null) {
                    $map[$pid] = $ref;
                }
            }
        }

        return $map;
    }

    /**
     * @return list<array{menu: SiteMenu, items: list<array{item: SiteMenuItem, href: string}>}>
     */
    private function menusForRender(): array
    {
        $menus = $this->em->getRepository(SiteMenu::class)->findBy([], ['name' => 'ASC']);
        $result = [];
        foreach ($menus as $menu) {
            $items = $this->em->getRepository(SiteMenuItem::class)->findBy(
                ['menuId' => $menu->id()],
                ['sortOrder' => 'ASC'],
            );
            $mapped = [];
            foreach ($items as $item) {
                $href = $item->url() ?? '#';
                if ($item->pageId() !== null) {
                    $linked = $this->em->find(Page::class, $item->pageId());
                    if ($linked instanceof Page && $linked->isPublished()) {
                        $href = '/p/' . $linked->slug();
                    }
                }
                $mapped[] = ['item' => $item, 'href' => $href];
            }
            $result[] = ['menu' => $menu, 'items' => $mapped];
        }

        return $result;
    }
}
