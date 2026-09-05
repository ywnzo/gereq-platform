<?php

declare(strict_types=1);

namespace Modules\Feeds\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Feeds\Application\FeedsProvider;
use Modules\Feeds\Domain\FeedDefinition;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class FeedApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly FeedsProvider $feeds,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest(
            $request,
            sortable: ['code', 'name', 'createdAt'],
            filterable: ['driver', 'active'],
        );

        $qb = $this->em->getRepository(FeedDefinition::class)->createQueryBuilder('f');
        self_repair_search($qb, 'f', ['code', 'name'], $query->search);
        if (isset($query->filters['driver']) && $query->filters['driver'] !== '') {
            $qb->andWhere('f.driver = :driver')->setParameter('driver', $query->filters['driver']);
        }
        if (isset($query->filters['active']) && $query->filters['active'] !== '') {
            $qb->andWhere('f.active = :active')
                ->setParameter('active', filter_var($query->filters['active'], FILTER_VALIDATE_BOOLEAN));
        }

        $sort = $query->sort ?? 'code';
        $qb->orderBy('f.' . $sort, strtoupper($query->direction));

        $total = (int) (clone $qb)->select('COUNT(f.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<FeedDefinition> $items */
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (FeedDefinition $f): array => $this->serialize($f), $items),
            $query->meta($total),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        if ($this->em->getRepository(FeedDefinition::class)->findOneBy(['code' => $data['code']]) instanceof FeedDefinition) {
            return ApiResponse::error('validation_error', 'Code taken', ['code' => 'taken'], 422);
        }

        $feed = new FeedDefinition($data['code'], $data['name'], $data['driver']);
        $this->applyData($feed, $data);
        $this->em->persist($feed);
        $this->em->flush();

        $payload = $this->serialize($feed);
        $this->events->emit('feed.created', ['type' => 'feed_definition', 'id' => $feed->id()], $payload);

        return ApiResponse::data($payload, 201);
    }

    public function show(string $id): JsonResponse
    {
        $feed = $this->find($id);
        if ($feed === null) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->serialize($feed));
    }

    public function generate(string $code): Response
    {
        $generated = $this->feeds->generate($code);
        if ($generated === null) {
            return ApiResponse::notFound();
        }

        return new Response($generated['body'], 200, [
            'Content-Type' => $generated['content_type'],
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $feed = $this->find($id);
        if ($feed === null) {
            return ApiResponse::notFound();
        }

        $data = $this->validated($request);
        $dup = $this->em->getRepository(FeedDefinition::class)->findOneBy(['code' => $data['code']]);
        if ($dup instanceof FeedDefinition && $dup->id() !== $feed->id()) {
            return ApiResponse::error('validation_error', 'Code taken', ['code' => 'taken'], 422);
        }

        $feed->setCode($data['code']);
        $feed->setName($data['name']);
        $this->applyData($feed, $data);
        $this->em->flush();

        $payload = $this->serialize($feed);
        $this->events->emit('feed.updated', ['type' => 'feed_definition', 'id' => $feed->id()], $payload);

        return ApiResponse::data($payload);
    }

    public function destroy(string $id): JsonResponse
    {
        $feed = $this->find($id);
        if ($feed === null) {
            return ApiResponse::notFound();
        }

        $payload = $this->serialize($feed);
        $this->em->remove($feed);
        $this->em->flush();
        $this->events->emit('feed.deleted', ['type' => 'feed_definition', 'id' => $id], $payload);

        return ApiResponse::data(['id' => $id, 'deleted' => true]);
    }

    private function find(string $id): ?FeedDefinition
    {
        $feed = $this->em->find(FeedDefinition::class, $id);

        return $feed instanceof FeedDefinition ? $feed : null;
    }

    /**
     * @return array{
     *     code: string,
     *     name: string,
     *     driver: string,
     *     format: string,
     *     config: ?array<string, mixed>,
     *     active: bool
     * }
     */
    private function validated(Request $request): array
    {
        $data = $this->validateBody($request, 'feeds.save');
        $activeRaw = $data['active'] ?? true;
        $config = $data['config'] ?? null;
        if (!is_array($config)) {
            $config = $this->parseConfig((string) ($data['config_json'] ?? '{}'));
        }

        return [
            'code' => strtolower(trim((string) $data['code'])),
            'name' => trim((string) $data['name']),
            'driver' => strtolower(trim((string) ($data['driver'] ?? 'google_merchant'))),
            'format' => strtolower(trim((string) ($data['format'] ?? 'xml'))),
            'config' => is_array($config) ? $config : null,
            'active' => $activeRaw === true || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'on',
        ];
    }

    /** @return array<string, mixed>|null */
    private function parseConfig(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array{
     *     driver: string,
     *     format: string,
     *     config: ?array<string, mixed>,
     *     active: bool
     * } $data
     */
    private function applyData(FeedDefinition $feed, array $data): void
    {
        $feed->setDriver($data['driver']);
        $feed->setFormat($data['format']);
        $feed->setConfig($data['config']);
        $feed->setActive($data['active']);
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function validateBody(Request $request, string $ruleset, array $context = []): array
    {
        return gereq()->make(ValidationRegistry::class)->validate($ruleset, $this->body($request), $context);
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

    /** @return array<string, mixed> */
    private function serialize(FeedDefinition $feed): array
    {
        return [
            'id' => $feed->id(),
            'code' => $feed->code(),
            'name' => $feed->name(),
            'driver' => $feed->driver(),
            'format' => $feed->format(),
            'active' => $feed->isActive(),
            'config' => $feed->config(),
            'created_at' => $feed->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $feed->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
