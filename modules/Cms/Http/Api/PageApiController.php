<?php

declare(strict_types=1);

namespace Modules\Cms\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Cms\Publish\CmsPagePublishAdapter;
use Gereq\Publish\ProductionFreeze;
use Gereq\Security\HtmlSanitizer;
use Gereq\Validation\ValidationRegistry;
use Modules\Cms\Domain\Events\PagePublished;
use Modules\Cms\Domain\Events\PageUpdated;
use Modules\Cms\Domain\Page;
use Modules\Cms\Domain\PageBlock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class PageApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly HtmlSanitizer $html,
        private readonly ProductionFreeze $freeze,
        private readonly Authenticator $auth,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest(
            $request,
            sortable: ['title', 'slug', 'status', 'updatedAt', 'createdAt'],
            filterable: ['status', 'template'],
        );

        $qb = $this->em->getRepository(Page::class)->createQueryBuilder('p');
        self_repair_search($qb, 'p', ['title', 'slug'], $query->search);
        if (isset($query->filters['status']) && $query->filters['status'] !== '') {
            $qb->andWhere('p.status = :status')->setParameter('status', $query->filters['status']);
        }
        if (isset($query->filters['template']) && $query->filters['template'] !== '') {
            $qb->andWhere('p.template = :template')->setParameter('template', $query->filters['template']);
        }

        $sort = $query->sort ?? 'updatedAt';
        $qb->orderBy('p.' . $sort, strtoupper($query->direction));

        $total = (int) (clone $qb)->select('COUNT(p.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<Page> $items */
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (Page $p): array => $this->serialize($p), $items),
            $query->meta($total),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateBody($request, 'cms.page.save');
        $page = new Page((string) $data['slug'], trim((string) $data['title']));
        $page->setTemplate((string) $data['template']);
        $page->setContentMode((string) ($data['content_mode'] ?? 'blocks'));
        $page->setBody($this->sanitizeBody($data['body'] ?? null));
        $page->setMetaDescription(isset($data['meta_description']) ? (string) $data['meta_description'] : null);
        if (array_key_exists('ssg', $data)) {
            $page->setSsg(filter_var($data['ssg'], FILTER_VALIDATE_BOOLEAN));
        }
        $page->setMenuLocations($this->menuLocationsFromData($data));
        $wantPublished = (string) $data['status'] === 'published';
        $page->setStatus((string) $data['status']);
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

        return ApiResponse::data($payload, 201);
    }

    public function show(string $id): JsonResponse
    {
        $page = $this->find($id);
        if ($page === null) {
            return ApiResponse::notFound();
        }

        $payload = $this->serialize($page);
        if (!$page->isRaw()) {
            $blocks = $this->em->getRepository(PageBlock::class)->findBy(['pageId' => $id], ['sortOrder' => 'ASC']);
            $payload['blocks'] = array_map(static fn (PageBlock $b): array => [
                'id' => $b->id(),
                'type' => $b->type(),
                'sort_order' => $b->sortOrder(),
                'payload' => $b->payload(),
            ], $blocks);
        } else {
            $payload['blocks'] = [];
        }

        return ApiResponse::data($payload);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $page = $this->find($id);
        if ($page === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            return ApiResponse::notFound();
        }

        $data = $this->validateBody($request, 'cms.page.save', ['ignore_id' => $id]);
        $wasPublished = $page->isPublished();
        $wantPublished = (string) $data['status'] === 'published';
        if ($wasPublished || $this->freeze->hasFreeze('cms.pages', $page->id())) {
            $this->freeze->freezeIfNeeded(
                'cms.pages',
                'page',
                $page->id(),
                fn (): array => CmsPagePublishAdapter::capture($this->em, $page),
                $page->title(),
                $this->auth->user()?->id(),
            );
        }
        $page->setSlug((string) $data['slug']);
        $page->setTitle(trim((string) $data['title']));
        $page->setTemplate((string) $data['template']);
        $page->setContentMode((string) ($data['content_mode'] ?? $page->contentMode()));
        $page->setBody($this->sanitizeBody($data['body'] ?? null));
        $page->setMetaDescription(isset($data['meta_description']) ? (string) $data['meta_description'] : null);
        if (array_key_exists('ssg', $data)) {
            $page->setSsg(filter_var($data['ssg'], FILTER_VALIDATE_BOOLEAN));
        }
        $page->setMenuLocations($this->menuLocationsFromData($data));
        $page->setStatus((string) $data['status']);
        $this->em->flush();

        if ($wantPublished && !$wasPublished && !$this->freeze->hasFreeze('cms.pages', $page->id())) {
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
        if ($page->isPublished() && !$wasPublished) {
            $this->events->dispatch(PagePublished::make($page->id(), $payload));
        }

        return ApiResponse::data($payload);
    }

    public function publish(string $id): JsonResponse
    {
        $page = $this->find($id);
        if ($page === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            return ApiResponse::notFound();
        }

        if (!$page->isPublished()) {
            $page->setStatus('published');
            $this->em->flush();
            if (!$this->freeze->hasFreeze('cms.pages', $page->id())) {
                $this->freeze->stageCreate(
                    'cms.pages',
                    'page',
                    $page->id(),
                    $page->title(),
                    $this->auth->user()?->id(),
                );
            }
            $payload = $this->serialize($page);
            $this->events->dispatch(PagePublished::make($page->id(), $payload));
            $this->events->dispatch(PageUpdated::make($page->id(), $payload));
        } else {
            $payload = $this->serialize($page);
        }

        return ApiResponse::data($payload);
    }

    public function destroy(string $id): JsonResponse
    {
        $page = $this->find($id);
        if ($page === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            return ApiResponse::notFound();
        }

        if ($page->isPublished() || $this->freeze->hasFreeze('cms.pages', $id)) {
            $this->freeze->stageDelete(
                'cms.pages',
                'page',
                $id,
                fn (): array => CmsPagePublishAdapter::capture($this->em, $page),
                $page->title(),
                $this->auth->user()?->id(),
            );

            return ApiResponse::data(['id' => $id, 'deleted' => false, 'staged' => true]);
        }

        foreach ($this->em->getRepository(PageBlock::class)->findBy(['pageId' => $id]) as $block) {
            $this->em->remove($block);
        }
        $this->em->remove($page);
        $this->em->flush();

        return ApiResponse::data(['id' => $id, 'deleted' => true]);
    }

    private function find(string $id): ?Page
    {
        $page = $this->em->find(Page::class, $id);

        return $page instanceof Page ? $page : null;
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $data = $request->request->all();
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_merge($data, $decoded);
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function validateBody(Request $request, string $ruleset, array $context = []): array
    {
        return gereq()->make(ValidationRegistry::class)->validate($ruleset, $this->body($request), $context);
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
            'ssg' => $page->ssg(),
            'menu_locations' => $page->menuLocations(),
            'menu_location' => $page->menuLocations()[0] ?? null,
            'menu_sort_order' => $page->menuSortOrder(),
            'published_at' => $page->publishedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $page->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $page->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function menuLocationsFromData(array $data): array
    {
        $raw = $data['menu_locations'] ?? null;
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $value) {
                if (is_string($value) || is_int($value)) {
                    $out[] = (string) $value;
                }
            }

            return $out;
        }
        if (isset($data['menu_location']) && (is_string($data['menu_location']) || is_int($data['menu_location']))) {
            return [(string) $data['menu_location']];
        }

        return [];
    }

    private function sanitizeBody(mixed $body): ?string
    {
        if ($body === null) {
            return null;
        }
        $clean = $this->html->sanitize((string) $body);

        return $clean !== '' ? $clean : null;
    }
}
