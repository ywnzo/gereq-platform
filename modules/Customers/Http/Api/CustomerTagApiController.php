<?php

declare(strict_types=1);

namespace Modules\Customers\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Router;
use Modules\Customers\Domain\Customer;
use Modules\Customers\Domain\CustomerTag;
use Modules\Customers\Domain\CustomerTagLink;
use Modules\Customers\Domain\Events\CustomerTagAttached;
use Modules\Customers\Domain\Events\CustomerTagDetached;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class CustomerTagApiController extends AbstractCustomerApiController
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest($request, sortable: ['name', 'slug', 'createdAt'], filterable: []);

        $qb = $this->em->getRepository(CustomerTag::class)->createQueryBuilder('t');
        self_repair_search($qb, 't', ['name', 'slug'], $query->search);
        $sort = $query->sort ?? 'name';
        $qb->orderBy('t.' . $sort, strtoupper($query->direction));

        $total = (int) (clone $qb)->select('COUNT(t.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<CustomerTag> $items */
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (CustomerTag $t): array => $this->serializeTag($t), $items),
            $query->meta($total),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateBody($request, 'customers.tag.save');
        $name = trim((string) $data['name']);
        $slug = isset($data['slug']) && (string) $data['slug'] !== ''
            ? CustomerTag::normalizeSlug((string) $data['slug'])
            : CustomerTag::slugify($name);

        $existing = $this->em->getRepository(CustomerTag::class)->findOneBy(['slug' => $slug]);
        if ($existing instanceof CustomerTag) {
            return ApiResponse::error('tag_exists', 'Štítek s tímto slugem už existuje.', ['slug' => 'unique'], 422);
        }

        $tag = new CustomerTag($name, $slug, isset($data['color']) ? (string) $data['color'] : null);
        $this->em->persist($tag);
        $this->em->flush();

        return ApiResponse::data($this->serializeTag($tag), 201);
    }

    public function attach(Request $request, string $id): JsonResponse
    {
        $customer = $this->em->find(Customer::class, $id);
        if (!$customer instanceof Customer) {
            return ApiResponse::notFound();
        }

        $data = $this->validateBody($request, 'customers.tag.attach');
        $tag = $this->resolveTag($data);
        if ($tag === null) {
            return ApiResponse::error('tag_not_found', 'Štítek neexistuje.', ['tag_id' => 'not_found'], 422);
        }

        $existing = $this->em->getRepository(CustomerTagLink::class)->findOneBy([
            'customerId' => $id,
            'tagId' => $tag->id(),
        ]);
        if ($existing instanceof CustomerTagLink) {
            return ApiResponse::data([
                'customer_id' => $id,
                'tag' => $this->serializeTag($tag),
                'attached' => true,
            ]);
        }

        $link = new CustomerTagLink($id, $tag->id());
        $this->em->persist($link);
        $this->em->flush();

        $payload = [
            'customer_id' => $id,
            'tag_id' => $tag->id(),
            'tag' => $this->serializeTag($tag),
        ];
        $this->events->dispatch(CustomerTagAttached::make($id, $payload));

        return ApiResponse::data($payload, 201);
    }

    public function detach(string $id, string $tagId): JsonResponse
    {
        $customer = $this->em->find(Customer::class, $id);
        if (!$customer instanceof Customer) {
            return ApiResponse::notFound();
        }

        $link = $this->em->getRepository(CustomerTagLink::class)->findOneBy([
            'customerId' => $id,
            'tagId' => $tagId,
        ]);
        if (!$link instanceof CustomerTagLink) {
            return ApiResponse::notFound();
        }

        $this->em->remove($link);
        $this->em->flush();

        $payload = ['customer_id' => $id, 'tag_id' => $tagId];
        $this->events->dispatch(CustomerTagDetached::make($id, $payload));

        return ApiResponse::data(['customer_id' => $id, 'tag_id' => $tagId, 'detached' => true]);
    }

    /** @param array<string, mixed> $data */
    private function resolveTag(array $data): ?CustomerTag
    {
        if (!empty($data['tag_id'])) {
            $tag = $this->em->find(CustomerTag::class, (string) $data['tag_id']);

            return $tag instanceof CustomerTag ? $tag : null;
        }
        if (!empty($data['slug'])) {
            $tag = $this->em->getRepository(CustomerTag::class)->findOneBy([
                'slug' => CustomerTag::normalizeSlug((string) $data['slug']),
            ]);

            return $tag instanceof CustomerTag ? $tag : null;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function serializeTag(CustomerTag $tag): array
    {
        return [
            'id' => $tag->id(),
            'name' => $tag->name(),
            'slug' => $tag->slug(),
            'color' => $tag->color(),
            'created_at' => $tag->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $tag->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
