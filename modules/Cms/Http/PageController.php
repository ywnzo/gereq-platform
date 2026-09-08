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
        if ($wasPublished || ($wantPublished && !$wasPublished)) {
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

        $item->setStatus('published');
        $this->em->flush();
        $payload = $this->serialize($item);
        $this->events->dispatch(PagePublished::make($item->id(), $payload));
        $this->events->dispatch(PageUpdated::make($item->id(), $payload));
        $this->auditor->log('page.published', 'cms_page', $item->id());
        $message = $this->translator->trans('cms.flash.page_published', ['%title%' => $item->title()]);
        if (!$this->wantsJson()) {
            $this->flash('success', $message);
        }

        return $this->toggleResponse(true, 'admin.cms.pages.index', [], $message);
    }

    public function preview(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('cms.error.page_not_found'));
        }

        // Admin "náhled" = stejný JSON, jaký dostane FE (žádný HTML render).
        return new JsonResponse(['data' => $this->cms->serializePage($item, true)], 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
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
