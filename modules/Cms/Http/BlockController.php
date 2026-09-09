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
use Gereq\Publish\Adapter\CmsPagePublishAdapter;
use Gereq\Publish\ProductionFreeze;
use Gereq\Security\HtmlSanitizer;
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
    private function payloadFromData(string $type, array $data): array
    {
        return match ($type) {
            'heading' => [
                'text' => trim((string) ($data['text'] ?? '')),
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
                'label' => trim((string) ($data['label'] ?? '')),
                'url' => trim((string) ($data['url'] ?? '')),
                'style' => trim((string) ($data['style'] ?? 'primary')),
            ],
            'columns' => [
                'left' => $this->html->sanitize((string) ($data['left'] ?? '')),
                'right' => $this->html->sanitize((string) ($data['right'] ?? '')),
            ],
            'divider' => [
                'style' => trim((string) ($data['style'] ?? 'solid')),
            ],
            'products' => [
                'product_ids' => trim((string) ($data['product_ids'] ?? '')),
            ],
            'gallery' => [
                'media_ids' => trim((string) ($data['media_ids'] ?? '')),
            ],
            default => [],
        };
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
