<?php

declare(strict_types=1);

namespace Modules\Cms\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Publish\Adapter\CmsPagePublishAdapter;
use Gereq\Publish\ProductionFreeze;
use Gereq\Validation\ValidationRegistry;
use Modules\Cms\Domain\Events\BlockCreated;
use Modules\Cms\Domain\Events\BlockDeleted;
use Modules\Cms\Domain\Events\BlockUpdated;
use Modules\Cms\Domain\Events\PageUpdated;
use Modules\Cms\Domain\Page;
use Modules\Cms\Domain\PageBlock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class BlockApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly ProductionFreeze $freeze,
        private readonly Authenticator $auth,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    private function freezePageIfNeeded(Page $page): void
    {
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

    public function index(string $id): JsonResponse
    {
        if ($this->findPage($id) === null) {
            return ApiResponse::notFound();
        }

        $blocks = $this->em->getRepository(PageBlock::class)->findBy(
            ['pageId' => $id],
            ['sortOrder' => 'ASC'],
        );

        return ApiResponse::data(array_map(fn (PageBlock $b): array => $this->serialize($b), $blocks));
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $page = $this->findPage($id);
        if ($page === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            return ApiResponse::notFound();
        }
        $this->freezePageIfNeeded($page);

        $data = $this->validateBody($request, 'cms.block.save');
        $type = (string) $data['type'];
        $max = (int) $this->em->getRepository(PageBlock::class)->createQueryBuilder('b')
            ->select('MAX(b.sortOrder)')
            ->where('b.pageId = :pid')
            ->setParameter('pid', $id)
            ->getQuery()
            ->getSingleScalarResult();

        $payload = is_array($data['payload'] ?? null)
            ? $data['payload']
            : $this->payloadFromData($type, $data);

        $block = new PageBlock($id, $type, $max + 1, $payload);
        $this->em->persist($block);
        $this->em->flush();

        $serialized = $this->serialize($block);
        $this->events->dispatch(BlockCreated::make($block->id(), $serialized));
        $this->events->dispatch(PageUpdated::make($page->id(), ['id' => $page->id(), 'touched' => 'block.created']));

        return ApiResponse::data($serialized, 201);
    }

    public function update(Request $request, string $id, string $blockId): JsonResponse
    {
        $page = $this->findPage($id);
        if ($page === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            return ApiResponse::notFound();
        }
        $block = $this->findBlock($id, $blockId);
        if ($block === null) {
            return ApiResponse::notFound();
        }
        $this->freezePageIfNeeded($page);

        $data = $this->validateBody($request, 'cms.block.save');
        $type = (string) ($data['type'] ?? $block->type());
        $block->setType($type);
        if (isset($data['sort_order'])) {
            $block->setSortOrder((int) $data['sort_order']);
        }
        $payload = is_array($data['payload'] ?? null)
            ? $data['payload']
            : $this->payloadFromData($type, $data);
        $block->setPayload($payload);
        $this->em->flush();

        $serialized = $this->serialize($block);
        $this->events->dispatch(BlockUpdated::make($block->id(), $serialized));
        $this->events->dispatch(PageUpdated::make($page->id(), ['id' => $page->id(), 'touched' => 'block.updated']));

        return ApiResponse::data($serialized);
    }

    public function destroy(string $id, string $blockId): JsonResponse
    {
        $page = $this->findPage($id);
        if ($page === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            return ApiResponse::notFound();
        }
        $block = $this->findBlock($id, $blockId);
        if ($block === null) {
            return ApiResponse::notFound();
        }
        $this->freezePageIfNeeded($page);

        $serialized = $this->serialize($block);
        $this->em->remove($block);
        $this->em->flush();
        $this->events->dispatch(BlockDeleted::make($blockId, $serialized));
        $this->events->dispatch(PageUpdated::make($page->id(), ['id' => $page->id(), 'touched' => 'block.deleted']));

        return ApiResponse::data(['id' => $blockId, 'deleted' => true]);
    }

    private function findPage(string $id): ?Page
    {
        $page = $this->em->find(Page::class, $id);

        return $page instanceof Page ? $page : null;
    }

    private function findBlock(string $pageId, string $blockId): ?PageBlock
    {
        $block = $this->em->find(PageBlock::class, $blockId);
        if (!$block instanceof PageBlock || $block->pageId() !== $pageId) {
            return null;
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
            'heading' => ['text' => trim((string) ($data['text'] ?? '')), 'level' => (int) ($data['level'] ?? 2)],
            'text' => ['body' => (string) ($data['body'] ?? '')],
            'image' => [
                'media_id' => trim((string) ($data['media_id'] ?? '')),
                'url' => trim((string) ($data['url'] ?? '')),
                'alt' => trim((string) ($data['alt'] ?? '')),
                'caption' => trim((string) ($data['caption'] ?? '')),
            ],
            'html' => ['html' => (string) ($data['html'] ?? '')],
            'cta' => [
                'label' => trim((string) ($data['label'] ?? '')),
                'url' => trim((string) ($data['url'] ?? '')),
                'style' => trim((string) ($data['style'] ?? 'primary')),
            ],
            'columns' => ['left' => (string) ($data['left'] ?? ''), 'right' => (string) ($data['right'] ?? '')],
            'divider' => ['style' => trim((string) ($data['style'] ?? 'solid'))],
            'products' => ['product_ids' => trim((string) ($data['product_ids'] ?? ''))],
            'gallery' => ['media_ids' => trim((string) ($data['media_ids'] ?? ''))],
            default => [],
        };
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
}
