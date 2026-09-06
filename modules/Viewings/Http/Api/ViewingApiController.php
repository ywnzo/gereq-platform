<?php

declare(strict_types=1);

namespace Modules\Viewings\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Viewings\Application\ViewingsProvider;
use Modules\Viewings\Domain\Viewing;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class ViewingApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly ViewingsProvider $provider,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest($request, sortable: ['status', 'number', 'scheduledAt', 'createdAt'], filterable: ['status', 'listing_id']);
        $qb = $this->em->getRepository(Viewing::class)->createQueryBuilder('v');
        if ($query->search !== '') {
            $qb->andWhere('v.number LIKE :q OR v.contactName LIKE :q OR v.contactEmail LIKE :q OR v.listingId LIKE :q')
                ->setParameter('q', '%' . $query->search . '%');
        }
        if (!empty($query->filters['status'])) {
            $qb->andWhere('v.status = :s')->setParameter('s', $query->filters['status']);
        }
        if (!empty($query->filters['listing_id'])) {
            $qb->andWhere('v.listingId = :l')->setParameter('l', $query->filters['listing_id']);
        }
        $sort = $query->sort ?? 'scheduledAt';
        $qb->orderBy('v.' . $sort, strtoupper($query->direction));
        $total = (int) (clone $qb)->select('COUNT(v.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (Viewing $v): array => $this->provider->serialize($v), $items),
            $query->meta($total),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = gereq()->make(ValidationRegistry::class)->validate('viewings.save', $this->body($request));
        $item = new Viewing(
            $this->provider->nextNumber(),
            trim((string) $data['listing_id']),
            trim((string) $data['contact_name']),
        );
        $this->hydrate($item, $data);
        $this->em->persist($item);
        $this->em->flush();
        $payload = $this->provider->serialize($item, true);
        $this->events->emit('viewing.created', ['type' => 'viewing', 'id' => $item->id()], $payload);

        return ApiResponse::data($payload, 201);
    }

    public function show(string $id): JsonResponse
    {
        $item = $this->provider->find($id);
        if ($item === null) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->provider->serialize($item, true));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $item = $this->provider->find($id);
        if ($item === null) {
            return ApiResponse::notFound();
        }
        $data = gereq()->make(ValidationRegistry::class)->validate('viewings.save', $this->body($request));
        $this->hydrate($item, $data);
        $this->em->flush();
        $payload = $this->provider->serialize($item, true);
        $this->events->emit('viewing.updated', ['type' => 'viewing', 'id' => $id], $payload);

        return ApiResponse::data($payload);
    }

    public function destroy(string $id): JsonResponse
    {
        $item = $this->provider->find($id);
        if ($item === null) {
            return ApiResponse::notFound();
        }
        $number = $item->number();
        $this->em->remove($item);
        $this->em->flush();
        $this->events->emit('viewing.deleted', ['type' => 'viewing', 'id' => $id], ['id' => $id, 'number' => $number]);

        return ApiResponse::data(['id' => $id, 'deleted' => true]);
    }

    /** @param array<string, mixed> $data */
    private function hydrate(Viewing $item, array $data): void
    {
        if (isset($data['listing_id'])) {
            $item->setListingId(trim((string) $data['listing_id']));
        }
        if (array_key_exists('customer_id', $data)) {
            $item->setCustomerId(isset($data['customer_id']) ? (string) $data['customer_id'] : null);
        }
        if (array_key_exists('broker_id', $data)) {
            $item->setBrokerId(isset($data['broker_id']) ? (string) $data['broker_id'] : null);
        }
        if (!empty($data['status']) && in_array((string) $data['status'], Viewing::STATUSES, true)) {
            $item->setStatus((string) $data['status']);
        }
        if (array_key_exists('scheduled_at', $data)) {
            $raw = trim((string) ($data['scheduled_at'] ?? ''));
            try {
                $item->setScheduledAt($raw !== '' ? new \DateTimeImmutable($raw) : null);
            } catch (\Exception) {
                $item->setScheduledAt(null);
            }
        }
        if (isset($data['contact_name'])) {
            $item->setContactName(trim((string) $data['contact_name']));
        }
        if (array_key_exists('contact_email', $data)) {
            $item->setContactEmail(isset($data['contact_email']) ? (string) $data['contact_email'] : null);
        }
        if (array_key_exists('contact_phone', $data)) {
            $item->setContactPhone(isset($data['contact_phone']) ? (string) $data['contact_phone'] : null);
        }
        if (array_key_exists('note', $data)) {
            $item->setNote(isset($data['note']) ? (string) $data['note'] : null);
        }
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $json = json_decode($request->getContent(), true);

        return is_array($json) ? $json : $request->request->all();
    }
}
