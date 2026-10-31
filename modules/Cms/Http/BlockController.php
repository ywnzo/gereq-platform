<?php

declare(strict_types=1);

namespace Modules\Cms\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Modules\Cms\Publish\CmsPagePublishAdapter;
use Gereq\Publish\ProductionFreeze;
use Gereq\Security\HtmlSanitizer;
use Modules\Cms\Application\BlockDesignNormalizer;
use Modules\Cms\Domain\Events\BlockCreated;
use Modules\Cms\Domain\Events\BlockDeleted;
use Modules\Cms\Domain\Events\BlockUpdated;
use Modules\Cms\Domain\Events\PageUpdated;
use Modules\Cms\Domain\Page;
use Modules\Cms\Domain\PageBlock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class BlockController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly EventBus $events,
        private readonly TranslatorInterface $translator,
        private readonly CapabilityRegistry $capabilities,
        private readonly HtmlSanitizer $html,
        private readonly ProductionFreeze $freeze,
        private readonly \Modules\Cms\Application\FormSchema $formSchema,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    private function freezePageIfPublished(Page $page): void
    {
        // Any public-facing / already-staged page must stay behind ProductionFreeze.
        if (!$page->isPublished() && !$this->freeze->hasFreeze('cms.pages', $page->id())) {
            return;
        }
        $this->freeze->freezeIfNeeded(
            'cms.pages',
            'page',
            $page->id(),
            fn (): array => CmsPagePublishAdapter::capture($this->em, $page),
            $page->title(),
            $this->auth->user()?->id(),
        );
    }

    public function store(Request $request, string $id): Response
    {
        $page = $this->pageEntity($id);
        $this->freezePageIfPublished($page);
        $data = $this->validate($request, 'cms.block.save');
        $type = (string) $data['type'];

        $max = (int) $this->em->getRepository(PageBlock::class)->createQueryBuilder('b')
            ->select('MAX(b.sortOrder)')
            ->where('b.pageId = :pid')
            ->setParameter('pid', $id)
            ->getQuery()
            ->getSingleScalarResult();

        $block = new PageBlock($id, $type, $max + 1, $this->payloadFromData($type, $data));
        $this->em->persist($block);
        $this->em->flush();

        $payload = $this->serialize($block);
        $this->events->dispatch(BlockCreated::make($block->id(), $payload));
        $this->events->dispatch(PageUpdated::make($page->id(), ['id' => $page->id(), 'touched' => 'block.created']));
        $this->forgetForm('cms.block.save');
        $this->flash('success', $this->translator->trans('cms.flash.block_created'));

        return $this->redirect('admin.cms.pages.show', ['id' => $id]);
    }

    public function edit(string $id, string $blockId): Response
    {
        $page = $this->pageEntity($id);
        $block = $this->blockEntity($id, $blockId);
        if ($block->type() === 'form') {
            return $this->redirect('admin.cms.pages.builder', ['id' => $id]);
        }

        return $this->adminPage('@cms/blocks/edit.html.twig', [
            'pageTitle' => $this->translator->trans('cms.blocks.edit'),
            'item' => $page,
            'block' => $block,
            'mediaOptions' => $this->mediaOptions(),
            'hasMedia' => $this->capabilities->has('media'),
            'hasProducts' => $this->capabilities->has('products'),
        ]);
    }

    public function update(Request $request, string $id, string $blockId): Response
    {
        $page = $this->pageEntity($id);
        $this->freezePageIfPublished($page);
        $block = $this->blockEntity($id, $blockId);
        $data = $this->validate($request, 'cms.block.save');
        $type = (string) ($data['type'] ?? $block->type());
        $block->setType($type);
        if ($type === 'gallery' && !array_key_exists('gallery_items', $data)) {
            $data['gallery_items'] = $block->payload()['gallery_items'] ?? '{}';
        }
        if ($type === 'dynamic' && !array_key_exists('item_blocks', $data)) {
            $data['item_blocks'] = $block->payload()['item_blocks'] ?? [];
        }
        foreach (['margin', 'margin_top', 'margin_right', 'margin_bottom', 'margin_left', 'padding', 'padding_top', 'padding_right', 'padding_bottom', 'padding_left'] as $key) {
            if (!array_key_exists($key, $data) && array_key_exists($key, $block->payload())) {
                $data[$key] = $block->payload()[$key];
            }
        }
        $block->setPayload($this->payloadFromData($type, $data));
        $this->em->flush();

        $payload = $this->serialize($block);
        $this->events->dispatch(BlockUpdated::make($block->id(), $payload));
        $this->events->dispatch(PageUpdated::make($page->id(), ['id' => $page->id(), 'touched' => 'block.updated']));
        $this->forgetForm('cms.block.save');
        $this->flash('success', $page->isPublished()
            ? $this->translator->trans('publish.flash.staged')
            : $this->translator->trans('cms.flash.block_updated'));

        return $this->redirect('admin.cms.pages.show', ['id' => $id]);
    }

    public function moveUp(string $id, string $blockId): Response
    {
        $page = $this->pageEntity($id);
        $this->freezePageIfPublished($page);
        $block = $this->blockEntity($id, $blockId);
        $prev = $this->em->getRepository(PageBlock::class)->createQueryBuilder('b')
            ->where('b.pageId = :pid AND b.sortOrder < :ord')
            ->setParameter('pid', $id)
            ->setParameter('ord', $block->sortOrder())
            ->orderBy('b.sortOrder', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($prev instanceof PageBlock) {
            $this->swapOrder($block, $prev);
        }

        return $this->redirect('admin.cms.pages.show', ['id' => $id]);
    }

    public function moveDown(string $id, string $blockId): Response
    {
        $page = $this->pageEntity($id);
        $this->freezePageIfPublished($page);
        $block = $this->blockEntity($id, $blockId);
        $next = $this->em->getRepository(PageBlock::class)->createQueryBuilder('b')
            ->where('b.pageId = :pid AND b.sortOrder > :ord')
            ->setParameter('pid', $id)
            ->setParameter('ord', $block->sortOrder())
            ->orderBy('b.sortOrder', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($next instanceof PageBlock) {
            $this->swapOrder($block, $next);
        }

        return $this->redirect('admin.cms.pages.show', ['id' => $id]);
    }

    public function destroy(string $id, string $blockId): Response
    {
        $page = $this->pageEntity($id);
        $this->freezePageIfPublished($page);
        $block = $this->blockEntity($id, $blockId);
        $payload = $this->serialize($block);
        $this->em->remove($block);
        $this->em->flush();
        $this->events->dispatch(BlockDeleted::make($blockId, $payload));
        $this->events->dispatch(PageUpdated::make($page->id(), ['id' => $page->id(), 'touched' => 'block.deleted']));
        $this->flash('success', $this->translator->trans('cms.flash.block_deleted'));

        return $this->redirect('admin.cms.pages.show', ['id' => $id]);
    }

    private function swapOrder(PageBlock $a, PageBlock $b): void
    {
        $tmp = $a->sortOrder();
        $a->setSortOrder($b->sortOrder());
        $b->setSortOrder($tmp);
        $this->em->flush();
        $this->events->dispatch(BlockUpdated::make($a->id(), $this->serialize($a)));
        $this->events->dispatch(BlockUpdated::make($b->id(), $this->serialize($b)));
    }

    private function pageEntity(string $id): Page
    {
        $page = $this->em->find(Page::class, $id);
        if (!$page instanceof Page) {
            $this->abort(404, $this->translator->trans('cms.error.page_not_found'));
        }

        return $page;
    }

    private function blockEntity(string $pageId, string $blockId): PageBlock
    {
        $block = $this->em->find(PageBlock::class, $blockId);
        if (!$block instanceof PageBlock || $block->pageId() !== $pageId) {
            $this->abort(404, $this->translator->trans('cms.error.block_not_found'));
        }

        return $block;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function payloadFromData(string $type, array $data, int $depth = 0): array
    {
        $core = match ($type) {
            'form' => \Modules\Cms\Application\FormSchema::normalize($data, fn (mixed $children): array => \Modules\Cms\Application\DynamicElement::blocks($children, fn (string $childType, array $childPayload): array => $this->payloadFromData($childType, $childPayload, $depth + 1), $depth)),
            'form_field' => \Modules\Cms\Application\FormSchema::field($data),
            'heading' => [
                'text' => $this->html->sanitize((string) ($data['text'] ?? '')),
                'level' => (int) ($data['level'] ?? 2),
            ],
            'text' => [
                'body' => $this->html->sanitize((string) ($data['body'] ?? '')),
            ],
            'image' => [
                'media_id' => trim((string) ($data['media_id'] ?? '')),
                'url' => trim((string) ($data['url'] ?? '')),
                'alt' => trim((string) ($data['alt'] ?? '')),
                'caption' => trim((string) ($data['caption'] ?? '')),
            ],
            'html' => [
                'html' => $this->html->sanitize((string) ($data['html'] ?? '')),
            ],
            'cta' => [
                'label' => (new \Gereq\Security\HtmlSanitizer())->sanitize((string) ($data['label'] ?? '')),
                'url' => \Modules\Cms\Application\ContentSecurity::link((string) ($data['url'] ?? '')),
                'style' => trim((string) ($data['style'] ?? 'primary')),
            ],
            'divider' => [
                'style' => trim((string) ($data['style'] ?? 'solid')),
            ],
            'products' => [
                'product_ids' => trim((string) ($data['product_ids'] ?? '')),
            ],
            'dynamic' => array_merge(\Modules\Cms\Application\DynamicElement::normalize($data), [
                'item_blocks' => \Modules\Cms\Application\DynamicElement::templateBlocks($data['item_blocks'] ?? [], fn (string $childType, array $childPayload): array => $this->payloadFromData($childType, $childPayload, $depth + 1), $depth),
            ]),
            'layout' => BlockDesignNormalizer::layout($data, fn (mixed $children): array => \Modules\Cms\Application\DynamicElement::blocks($children, fn (string $childType, array $childPayload): array => $this->payloadFromData($childType, $childPayload, $depth + 1), $depth)),
            'widget' => \Modules\Cms\Application\IntegrationWidget::normalize($data),
            'avatar', 'badge' => \Modules\Cms\Application\VisualElement::normalize($type, $data),
            'rating' => ['value' => substr((string) ($data['value'] ?? '5'), 0, 100)],
            'gallery' => [
                'media_ids' => trim((string) ($data['media_ids'] ?? '')),
                'variant' => BlockDesignNormalizer::galleryVariant($data['variant'] ?? 'grid'),
            ],
            'quote' => [
                'quote' => trim((string) ($data['quote'] ?? '')),
                'author' => trim((string) ($data['author'] ?? '')),
                'role' => trim((string) ($data['role'] ?? '')),
            ],
            'video' => [
                'url' => trim((string) ($data['url'] ?? '')),
                'caption' => trim((string) ($data['caption'] ?? '')),
            ],
            'spacer' => [
                'size' => $this->normalizeSize((string) ($data['size'] ?? 'md')),
            ],
            'icon' => [
                'name' => preg_replace('~[^a-z0-9_\\-]+~i', '', trim((string) ($data['name'] ?? 'circle'))) ?: 'circle',
                'icon_style' => BlockDesignNormalizer::normalizeIconStyle($data['icon_style'] ?? 'outlined'),
                'size' => BlockDesignNormalizer::normalizeIconSize($data['size'] ?? '24'),
                'fill' => BlockDesignNormalizer::normalizeIconFill($data['fill'] ?? '0'),
                'weight' => BlockDesignNormalizer::normalizeIconWeight($data['weight'] ?? '400'),
                'grade' => BlockDesignNormalizer::normalizeIconGrade($data['grade'] ?? '0'),
                'opsz' => BlockDesignNormalizer::normalizeIconOpsz($data['opsz'] ?? ''),
                'title' => trim((string) ($data['title'] ?? '')),
                'hover_color' => BlockDesignNormalizer::normalizeColor(
                    (string) ($data['hover_color'] ?? 'inherit'),
                    BlockDesignNormalizer::textTokens(),
                    'inherit',
                ),
            ],
            'table' => [
                'html' => $this->html->sanitize((string) ($data['html'] ?? '')),
                'caption' => $this->html->sanitize((string) ($data['caption'] ?? '')),
                'caption_visible' => ((int) ($data['caption_visible'] ?? 1)) === 0 ? '0' : '1',
                'style_cell_padding' => max(0, min(20, (int) ($data['style_cell_padding'] ?? 0))),
                'style_border_inner' => ((int) ($data['style_border_inner'] ?? 1)) === 0 ? '0' : '1',
                'style_border_outer' => ((int) ($data['style_border_outer'] ?? 1)) === 0 ? '0' : '1',
            ],
            default => [],
        };

        if ($depth < 16 && (isset($data['gereq_tag']) || isset($data['gereq_component'])) && is_array($data['children'] ?? null)) {
            $core['children'] = [];
            foreach ($data['children'] as $child) {
                if (!is_array($child) || !in_array($child['type'] ?? '', PageBlock::TYPES, true)) {
                    continue;
                }
                $core['children'][] = [
                    'id' => (string) ($child['id'] ?? ''),
                    'type' => $child['type'],
                    'payload' => $this->payloadFromData($child['type'], is_array($child['payload'] ?? null) ? $child['payload'] : [], $depth + 1),
                ];
            }
        }

        if ($type === 'form') {
            $this->formSchema->assertEditable($core);
        }

        return BlockDesignNormalizer::merge($core, $data);
    }

    private function normalizeSize(string $size): string
    {
        $size = trim($size);

        return in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';
    }

    /** @return list<array{value: string, label: string}> */
    private function mediaOptions(): array
    {
        if (!$this->capabilities->has('media')) {
            return [];
        }
        $provider = $this->capabilities->get('media');
        if (!method_exists($provider, 'images')) {
            return [];
        }
        $options = [];
        foreach ($provider->images(100) as $asset) {
            $options[] = [
                'value' => $asset->id(),
                'label' => $asset->title(),
            ];
        }

        return $options;
    }

    /** @return array<string, mixed> */
    private function serialize(PageBlock $block): array
    {
        return [
            'id' => $block->id(),
            'page_id' => $block->pageId(),
            'type' => $block->type(),
            'sort_order' => $block->sortOrder(),
            'payload' => $block->payload(),
        ];
    }

    /** @param array<string, mixed> $data */
    private function adminPage(string $view, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }
}
