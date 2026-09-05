<?php

declare(strict_types=1);

namespace Modules\Cms\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
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
        if ($query->search !== '') {
            $qb->andWhere('p.title LIKE :q OR p.slug LIKE :q')
                ->setParameter('q', '%' . $query->search . '%');
        }
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
        $page->setStatus((string) $data['status']);
        $this->em->persist($page);
        $this->em->flush();

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
        if ($page === null) {
            return ApiResponse::notFound();
        }

        $data = $this->validateBody($request, 'cms.page.save', ['ignore_id' => $id]);
        $wasPublished = $page->isPublished();
        $page->setSlug((string) $data['slug']);
        $page->setTitle(trim((string) $data['title']));
        $page->setTemplate((string) $data['template']);
        $page->setContentMode((string) ($data['content_mode'] ?? $page->contentMode()));
        $page->setBody($this->sanitizeBody($data['body'] ?? null));
        $page->setMetaDescription(isset($data['meta_description']) ? (string) $data['meta_description'] : null);
        $page->setStatus((string) $data['status']);
        $this->em->flush();

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
        if ($page === null) {
            return ApiResponse::notFound();
        }

        if (!$page->isPublished()) {
            $page->setStatus('published');
            $this->em->flush();
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
        if ($page === null) {
            return ApiResponse::notFound();
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
}
