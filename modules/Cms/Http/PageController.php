<?php

declare(strict_types=1);

namespace Modules\Cms\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Gereq\Publish\Adapter\CmsPagePublishAdapter;
use Gereq\Publish\ProductionFreeze;
use Gereq\Security\HtmlSanitizer;
use Gereq\Setting\Settings;
use Modules\Cms\Application\CmsProvider;
use Modules\Cms\Domain\Events\PagePublished;
use Modules\Cms\Domain\Events\PageUpdated;
use Modules\Cms\Domain\Page;
use Modules\Cms\Domain\PageBlock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class PageController extends Controller
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
        private readonly CapabilityRegistry $capabilities,
        private readonly CmsProvider $cms,
        private readonly HtmlSanitizer $html,
        private readonly ProductionFreeze $freeze,
        private readonly Settings $settings,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $status = (string) $request->query->get('status', '');
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 20)));

        $qb = $this->em->getRepository(Page::class)->createQueryBuilder('p')
            ->orderBy('p.updatedAt', 'DESC');

        self_repair_search($qb, 'p', ['title', 'slug'], $search);
        if (in_array($status, Page::STATUSES, true)) {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }

        $deleteKeys = $this->freeze->pendingDeleteKeys('cms.pages');
        if ($deleteKeys !== []) {
            $qb->andWhere('p.id NOT IN (:pendingDelete)')->setParameter('pendingDelete', $deleteKeys);
        }

        $total = (int) (clone $qb)->select('COUNT(p.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        return $this->page('@cms/pages/index.html.twig', [
            'pageTitle' => $this->translator->trans('cms.pages.title'),
            'items' => $items,
            'search' => $search,
            'status' => $status,
            'statuses' => Page::STATUSES,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages' => (int) ceil(max(1, $total) / $perPage),
        ]);
    }

    public function create(): Response
    {
        return $this->page('@cms/pages/form.html.twig', [
            'pageTitle' => $this->translator->trans('cms.pages.new'),
            'item' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, 'cms.page.save');
        $page = new Page((string) $data['slug'], trim((string) $data['title']));
        $page->setTemplate((string) $data['template']);
        $page->setContentMode((string) ($data['content_mode'] ?? 'blocks'));
        $page->setBody($this->sanitizeBody($data['body'] ?? null));
        $page->setMetaDescription(isset($data['meta_description']) ? (string) $data['meta_description'] : null);
        $wantPublished = (string) $data['status'] === 'published';
        // Never go public on create until publish badge apply.
        $page->setStatus($wantPublished ? 'published' : (string) $data['status']);
        $this->em->persist($page);
        $this->em->flush();

        if ($wantPublished) {
            $this->freeze->stageCreate(
                'cms.pages',
                'page',
                $page->id(),
                $page->title(),
                $this->auth->user()?->id(),
            );
        }

        $payload = $this->serialize($page);
        $this->events->dispatch(PageUpdated::make($page->id(), $payload));
        if ($page->isPublished()) {
            $this->events->dispatch(PagePublished::make($page->id(), $payload));
        }
        $this->auditor->log('page.created', 'cms_page', $page->id(), null, ['slug' => $page->slug()]);
        $this->forgetForm('cms.page.save');
        $this->flash(
            'success',
            $wantPublished
                ? $this->translator->trans('publish.flash.staged')
                : $this->translator->trans('cms.flash.page_created', ['%title%' => $page->title()]),
        );

        return $this->redirect('admin.cms.pages.show', ['id' => $page->id()]);
    }

    public function show(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            $this->abort(404, $this->translator->trans('cms.error.page_not_found'));
        }

        $blocks = $this->em->getRepository(PageBlock::class)->findBy(
            ['pageId' => $id],
            ['sortOrder' => 'ASC'],
        );

        $blockTypeOptions = [];
        foreach (PageBlock::TYPES as $type) {
            $blockTypeOptions[] = [
                'value' => $type,
                'label' => $this->translator->trans('cms.block.type.' . $type),
            ];
        }

        return $this->page('@cms/pages/show.html.twig', [
            'pageTitle' => $item->title(),
            'item' => $item,
            'blocks' => $blocks,
            'blockTypeOptions' => $blockTypeOptions,
            'mediaOptions' => $this->mediaOptions(),
            'hasMedia' => $this->capabilities->has('media'),
            'hasProducts' => $this->capabilities->has('products'),
            'staged' => $this->freeze->hasFreeze('cms.pages', $id),
            'stagedCreate' => $this->freeze->isPendingCreate('cms.pages', $id),
        ]);
    }

    public function edit(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            $this->abort(404, $this->translator->trans('cms.error.page_not_found'));
        }

        return $this->page('@cms/pages/form.html.twig', [
            'pageTitle' => $this->translator->trans('cms.pages.edit'),
            'item' => $item,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('cms.error.page_not_found'));
        }

        $data = $this->validate($request, 'cms.page.save', ['ignore_id' => $id]);
        $wasPublished = $item->isPublished();
        $wantPublished = (string) $data['status'] === 'published';
        // Already public → freeze production snapshot before mutating working copy.
        if ($wasPublished || $this->freeze->hasFreeze('cms.pages', $item->id())) {
            $this->freeze->freezeIfNeeded(
                'cms.pages',
                'page',
                $item->id(),
                fn (): array => CmsPagePublishAdapter::capture($this->em, $item),
                $item->title(),
                $this->auth->user()?->id(),
            );
        }
        $item->setSlug((string) $data['slug']);
        $item->setTitle(trim((string) $data['title']));
        $item->setTemplate((string) $data['template']);
        $item->setContentMode((string) ($data['content_mode'] ?? $item->contentMode()));
        $item->setBody($this->sanitizeBody($data['body'] ?? null));
        $item->setMetaDescription(isset($data['meta_description']) ? (string) $data['meta_description'] : null);
        $item->setStatus((string) $data['status']);
        $this->em->flush();

        // First publish never goes live — stage create until topbar Publish.
        if ($wantPublished && !$wasPublished && !$this->freeze->hasFreeze('cms.pages', $item->id())) {
            $this->freeze->stageCreate(
                'cms.pages',
                'page',
                $item->id(),
                $item->title(),
                $this->auth->user()?->id(),
            );
        }

        $payload = $this->serialize($item);
        $this->events->dispatch(PageUpdated::make($item->id(), $payload));
        if ($item->isPublished() && !$wasPublished) {
            $this->events->dispatch(PagePublished::make($item->id(), $payload));
        }
        $this->auditor->log('page.updated', 'cms_page', $item->id());
        $this->forgetForm('cms.page.save');
        $message = ($wasPublished || $wantPublished)
            ? $this->translator->trans('publish.flash.staged')
            : $this->translator->trans('cms.flash.page_updated', ['%title%' => $item->title()]);
        $this->flash('success', $message);

        return $this->redirect('admin.cms.pages.show', ['id' => $id]);
    }

    public function publish(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('cms.error.page_not_found'));
        }

        if ($item->isPublished()) {
            $this->freeze->freezeIfNeeded(
                'cms.pages',
                'page',
                $item->id(),
                fn (): array => CmsPagePublishAdapter::capture($this->em, $item),
                $item->title(),
                $this->auth->user()?->id(),
            );
            $item->setStatus('draft');
            $this->em->flush();
            $payload = $this->serialize($item);
            $this->events->dispatch(PageUpdated::make($item->id(), $payload));
            $this->auditor->log('page.unpublished', 'cms_page', $item->id());
            $message = $this->translator->trans('publish.flash.staged');
            if (!$this->wantsJson()) {
                $this->flash('success', $message);
            }

            return $this->toggleResponse(false, 'admin.cms.pages.index', [], $message);
        }

        // Mark published in working copy, but keep off /api/public until topbar Publish.
        $item->setStatus('published');
        $this->em->flush();
        if (!$this->freeze->hasFreeze('cms.pages', $item->id())) {
            $this->freeze->stageCreate(
                'cms.pages',
                'page',
                $item->id(),
                $item->title(),
                $this->auth->user()?->id(),
            );
        }
        $payload = $this->serialize($item);
        $this->events->dispatch(PagePublished::make($item->id(), $payload));
        $this->events->dispatch(PageUpdated::make($item->id(), $payload));
        $this->auditor->log('page.published', 'cms_page', $item->id());
        $message = $this->translator->trans('publish.flash.staged');
        if (!$this->wantsJson()) {
            $this->flash('success', $message);
        }

        return $this->toggleResponse(true, 'admin.cms.pages.index', [], $message);
    }

    public function preview(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            $this->abort(404, $this->translator->trans('cms.error.page_not_found'));
        }

        // Working-copy HTML preview (staging), WP-like banner — not production freeze.
        $pageData = $this->cms->serializePage($item, true, true);

        return $this->render('@cms/pages/preview.html.twig', [
            'pageTitle' => $item->title(),
            'item' => $item,
            'pageData' => $pageData,
            'staged' => $this->freeze->hasFreeze('cms.pages', $id),
            'user' => $this->auth->user(),
        ]);
    }

    public function previewJson(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            $this->abort(404, $this->translator->trans('cms.error.page_not_found'));
        }

        return new JsonResponse(['data' => $this->cms->serializePage($item, true, true)], 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    public function builder(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            $this->abort(404, $this->translator->trans('cms.error.page_not_found'));
        }

        if ($item->isRaw()) {
            return $this->redirect('admin.cms.pages.edit', ['id' => $id]);
        }

        $pageData = $this->cms->serializePage($item, true);
        $blockTypes = [];
        foreach (PageBlock::TYPES as $type) {
            $blockTypes[] = [
                'value' => $type,
                'label' => $this->translator->trans('cms.block.type.' . $type),
            ];
        }

        $variables = $this->builderVariables($item);

        $boot = [
            'pageId' => $item->id(),
            'title' => $item->title(),
            'slug' => $item->slug(),
            'template' => $item->template(),
            'saveUrl' => $this->router->url('admin.cms.pages.builder.save', ['id' => $id]),
            'previewUrl' => $this->router->url('admin.cms.pages.preview', ['id' => $id]),
            'exitUrl' => $this->router->url('admin.cms.pages.show', ['id' => $id]),
            'csrf' => '',
            'blockTypes' => $blockTypes,
            'blocks' => $pageData['blocks'] ?? [],
            'media' => $this->mediaLibrary(),
            'documents' => $this->documentLibrary(),
            'variables' => $variables['catalog'],
            'varValues' => $variables['values'],
            'labels' => [
                'saving' => $this->translator->trans('cms.builder.saving'),
                'saved' => $this->translator->trans('cms.builder.saved'),
                'pickField' => $this->translator->trans('cms.builder.vars.pick_field'),
            ],
        ];

        return $this->page('@cms/pages/builder.html.twig', [
            'pageTitle' => $this->translator->trans('cms.builder.title') . ' — ' . $item->title(),
            'item' => $item,
            'boot' => $boot,
            'staged' => $this->freeze->hasFreeze('cms.pages', $id),
        ]);
    }

    public function builderSave(Request $request, string $id): JsonResponse
    {
        $item = $this->find($id);
        if ($item === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            return new JsonResponse(['error' => ['message' => $this->translator->trans('cms.error.page_not_found')]], 404);
        }

        if ($item->isPublished() || $this->freeze->hasFreeze('cms.pages', $id)) {
            $this->freeze->freezeIfNeeded(
                'cms.pages',
                'page',
                $item->id(),
                fn (): array => CmsPagePublishAdapter::capture($this->em, $item),
                $item->title(),
                $this->auth->user()?->id(),
            );
        }

        $raw = $request->getContent();
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $incoming = is_array($decoded) && isset($decoded['blocks']) && is_array($decoded['blocks'])
            ? $decoded['blocks']
            : [];

        /** @var list<PageBlock> $existing */
        $existing = $this->em->getRepository(PageBlock::class)->findBy(['pageId' => $id]);
        $byId = [];
        foreach ($existing as $block) {
            $byId[$block->id()] = $block;
        }

        $keep = [];
        $idMap = [];
        $order = 0;
        foreach ($incoming as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = (string) ($row['type'] ?? '');
            if (!in_array($type, PageBlock::TYPES, true)) {
                continue;
            }
            $order++;
            $clientId = (string) ($row['client_id'] ?? '');
            $serverId = trim((string) ($row['id'] ?? ''));
            $payloadRaw = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            $payload = $this->normalizeBuilderPayload($type, $payloadRaw);

            if ($serverId !== '' && isset($byId[$serverId])) {
                $block = $byId[$serverId];
                $block->setType($type);
                $block->setSortOrder($order);
                $block->setPayload($payload);
                $keep[$serverId] = true;
                if ($clientId !== '') {
                    $idMap[$clientId] = $serverId;
                }
                continue;
            }

            $block = new PageBlock($id, $type, $order, $payload);
            $this->em->persist($block);
            $this->em->flush(); // need id for map
            $keep[$block->id()] = true;
            if ($clientId !== '') {
                $idMap[$clientId] = $block->id();
            }
        }

        foreach ($existing as $block) {
            if (!isset($keep[$block->id()])) {
                $this->em->remove($block);
            }
        }
        $this->em->flush();

        $this->events->dispatch(PageUpdated::make($item->id(), ['id' => $item->id(), 'touched' => 'builder.save']));
        $this->auditor->log('page.builder_saved', 'cms_page', $item->id());

        $serialized = $this->cms->serializePage($item, true);

        return new JsonResponse([
            'data' => [
                'blocks' => $serialized['blocks'] ?? [],
                'id_map' => $idMap,
                'staged' => $this->freeze->hasFreeze('cms.pages', $id),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeBuilderPayload(string $type, array $payload): array
    {
        return match ($type) {
            'heading' => [
                'text' => trim((string) ($payload['text'] ?? '')),
                'level' => max(1, min(3, (int) ($payload['level'] ?? 2))),
            ],
            'text' => [
                'body' => $this->html->sanitize((string) ($payload['body'] ?? '')),
            ],
            'html' => [
                'html' => $this->html->sanitize((string) ($payload['html'] ?? '')),
            ],
            'image' => [
                'media_id' => trim((string) ($payload['media_id'] ?? '')),
                'url' => trim((string) ($payload['url'] ?? '')),
                'alt' => trim((string) ($payload['alt'] ?? '')),
                'caption' => trim((string) ($payload['caption'] ?? '')),
            ],
            'cta' => [
                'label' => trim((string) ($payload['label'] ?? '')),
                'url' => trim((string) ($payload['url'] ?? '')),
                'style' => trim((string) ($payload['style'] ?? 'primary')),
            ],
            'columns' => [
                'left' => $this->html->sanitize((string) ($payload['left'] ?? '')),
                'right' => $this->html->sanitize((string) ($payload['right'] ?? '')),
            ],
            'divider' => [
                'style' => trim((string) ($payload['style'] ?? 'solid')),
            ],
            'products' => [
                'product_ids' => trim((string) ($payload['product_ids'] ?? '')),
            ],
            'gallery' => [
                'media_ids' => trim((string) ($payload['media_ids'] ?? '')),
            ],
            default => [],
        };
    }

    /** @return list<array{id: string, title: string, url: string, mime: string, alt: string}> */
    private function mediaLibrary(): array
    {
        if (!$this->capabilities->has('media')) {
            return [];
        }
        $provider = $this->capabilities->get('media');
        if (!method_exists($provider, 'images')) {
            return [];
        }
        $out = [];
        foreach ($provider->images(80) as $asset) {
            $out[] = [
                'id' => $asset->id(),
                'title' => $asset->title(),
                'url' => '/media/' . $asset->id() . '/file',
                'mime' => $asset->mime(),
                'alt' => (string) ($asset->alt() ?? ''),
            ];
        }

        return $out;
    }

    /** @return list<array{id: string, title: string, mime: string, url: string}> */
    private function documentLibrary(): array
    {
        if (!$this->capabilities->has('documents')) {
            return [];
        }
        $provider = $this->capabilities->get('documents');
        if (!method_exists($provider, 'search')) {
            return [];
        }
        $out = [];
        foreach ($provider->search('', 40) as $doc) {
            $out[] = [
                'id' => $doc->id(),
                'title' => $doc->title(),
                'mime' => $doc->mime(),
                'url' => $this->router->url('admin.documents.file', ['id' => $doc->id()]),
            ];
        }

        return $out;
    }

    public function destroy(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            $this->abort(404, $this->translator->trans('cms.error.page_not_found'));
        }

        $title = $item->title();
        if ($item->isPublished() || $this->freeze->hasFreeze('cms.pages', $id)) {
            $this->freeze->stageDelete(
                'cms.pages',
                'page',
                $id,
                fn (): array => CmsPagePublishAdapter::capture($this->em, $item),
                $title,
                $this->auth->user()?->id(),
            );
            $this->auditor->log('page.delete_staged', 'cms_page', $id);
            $this->flash('success', $this->translator->trans('publish.flash.staged'));
        } else {
            foreach ($this->em->getRepository(PageBlock::class)->findBy(['pageId' => $id]) as $block) {
                $this->em->remove($block);
            }
            $this->em->remove($item);
            $this->em->flush();
            $this->auditor->log('page.deleted', 'cms_page', $id);
            $this->flash('success', $this->translator->trans('cms.flash.page_deleted', ['%title%' => $title]));
        }

        return $this->redirect('admin.cms.pages.index');
    }

    private function find(string $id): ?Page
    {
        $page = $this->em->find(Page::class, $id);

        return $page instanceof Page ? $page : null;
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
                'label' => $asset->title() . ' (' . $asset->mime() . ')',
            ];
        }

        return $options;
    }

    /** @return array<string, mixed> */
    private function serialize(Page $page): array
    {
        return [
            'id' => $page->id(),
            'slug' => $page->slug(),
            'title' => $page->title(),
            'status' => $page->status(),
            'template' => $page->template(),
            'content_mode' => $page->contentMode(),
            'body' => $page->body(),
            'meta_description' => $page->metaDescription(),
            'published_at' => $page->publishedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $page->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $page->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array{catalog: list<array{path: string, label: string, description: string, group: string, kind: string, example: string, token: string}>, values: array<string, string>}
     */
    private function builderVariables(Page $page): array
    {
        $siteTitle = (string) $this->settings->get('cms.site_title', 'GEREQ Site');
        $brandName = (string) $this->settings->get('brand.name', '');
        $brandSignal = (string) $this->settings->get('brand.color_signal', '#d7ff45');
        $brandInk = (string) $this->settings->get('brand.color_ink', '#0a0b0a');
        $brandCream = (string) $this->settings->get('brand.color_cream', '#f5f5ef');
        $brandPrimary = (string) $this->settings->get('brand.site_primary', '#17375a');
        $brandLogo = (string) $this->settings->get('brand.logo_url', '');
        $pageUrl = '/' . ltrim($page->slug(), '/');

        $values = [
            'page.title' => $page->title(),
            'page.slug' => $page->slug(),
            'page.meta' => (string) ($page->metaDescription() ?? ''),
            'page.url' => $pageUrl,
            'site.title' => $siteTitle !== '' ? $siteTitle : ($brandName !== '' ? $brandName : 'Site'),
            'site.home' => '/',
            'brand.name' => $brandName !== '' ? $brandName : $siteTitle,
            'brand.logo' => $brandLogo,
            'brand.signal' => $brandSignal,
            'brand.ink' => $brandInk,
            'brand.cream' => $brandCream,
            'brand.primary' => $brandPrimary,
            'layout.gap' => '16',
            'layout.columns' => '12',
        ];

        $defs = [
            ['page.title', 'cms.builder.var.page_title', 'cms.builder.var.page_title_hint', 'cms.builder.var.group.page', 'text'],
            ['page.slug', 'cms.builder.var.page_slug', 'cms.builder.var.page_slug_hint', 'cms.builder.var.group.page', 'text'],
            ['page.meta', 'cms.builder.var.page_meta', 'cms.builder.var.page_meta_hint', 'cms.builder.var.group.page', 'text'],
            ['page.url', 'cms.builder.var.page_url', 'cms.builder.var.page_url_hint', 'cms.builder.var.group.links', 'link'],
            ['site.title', 'cms.builder.var.site_title', 'cms.builder.var.site_title_hint', 'cms.builder.var.group.text', 'text'],
            ['site.home', 'cms.builder.var.site_home', 'cms.builder.var.site_home_hint', 'cms.builder.var.group.links', 'link'],
            ['brand.name', 'cms.builder.var.brand_name', 'cms.builder.var.brand_name_hint', 'cms.builder.var.group.text', 'text'],
            ['brand.logo', 'cms.builder.var.brand_logo', 'cms.builder.var.brand_logo_hint', 'cms.builder.var.group.images', 'image'],
            ['brand.signal', 'cms.builder.var.brand_signal', 'cms.builder.var.brand_signal_hint', 'cms.builder.var.group.colors', 'color'],
            ['brand.ink', 'cms.builder.var.brand_ink', 'cms.builder.var.brand_ink_hint', 'cms.builder.var.group.colors', 'color'],
            ['brand.cream', 'cms.builder.var.brand_cream', 'cms.builder.var.brand_cream_hint', 'cms.builder.var.group.colors', 'color'],
            ['brand.primary', 'cms.builder.var.brand_primary', 'cms.builder.var.brand_primary_hint', 'cms.builder.var.group.colors', 'color'],
            ['layout.gap', 'cms.builder.var.layout_gap', 'cms.builder.var.layout_gap_hint', 'cms.builder.var.group.numbers', 'number'],
            ['layout.columns', 'cms.builder.var.layout_columns', 'cms.builder.var.layout_columns_hint', 'cms.builder.var.group.numbers', 'number'],
        ];

        $catalog = [];
        foreach ($defs as [$path, $labelKey, $hintKey, $groupKey, $kind]) {
            $example = $values[$path] ?? '';
            $catalog[] = [
                'path' => $path,
                'label' => $this->translator->trans($labelKey),
                'description' => $this->translator->trans($hintKey),
                'group' => $this->translator->trans($groupKey),
                'kind' => $kind,
                'example' => $example,
                'token' => '{{' . $path . '}}',
            ];
        }

        return ['catalog' => $catalog, 'values' => $values];
    }

    private function sanitizeBody(mixed $body): ?string
    {
        if ($body === null) {
            return null;
        }
        $clean = $this->html->sanitize((string) $body);

        return $clean !== '' ? $clean : null;
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
