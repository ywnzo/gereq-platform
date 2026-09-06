<?php

declare(strict_types=1);

namespace Modules\Portals\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Listings\Domain\Listing;
use Modules\Portals\Application\PortalsProvider;
use Modules\Portals\Domain\PortalAccount;
use Modules\Portals\Domain\PortalPublication;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class PortalApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EventBus $events,
        private readonly PortalsProvider $provider,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function accounts(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest($request, sortable: ['name', 'providerId', 'createdAt'], filterable: ['provider_id', 'active']);
        $qb = $this->em->getRepository(PortalAccount::class)->createQueryBuilder('a');
        if ($query->search !== '') {
            $qb->andWhere('a.name LIKE :q OR a.code LIKE :q')->setParameter('q', '%' . $query->search . '%');
        }
        if (!empty($query->filters['provider_id'])) {
            $qb->andWhere('a.providerId = :p')->setParameter('p', $query->filters['provider_id']);
        }
        if (isset($query->filters['active']) && $query->filters['active'] !== '') {
            $qb->andWhere('a.active = :a')->setParameter('a', filter_var($query->filters['active'], FILTER_VALIDATE_BOOLEAN));
        }
        $sort = $query->sort ?? 'name';
        $qb->orderBy('a.' . $sort, strtoupper($query->direction));
        $total = (int) (clone $qb)->select('COUNT(a.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (PortalAccount $a): array => $this->provider->serializeAccount($a), $items),
            $query->meta($total),
        );
    }

    public function publications(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest($request, sortable: ['status', 'updatedAt'], filterable: ['status', 'listing_id', 'portal_account_id']);
        $qb = $this->em->getRepository(PortalPublication::class)->createQueryBuilder('p');
        if (!empty($query->filters['status'])) {
            $qb->andWhere('p.status = :s')->setParameter('s', $query->filters['status']);
        }
        if (!empty($query->filters['listing_id'])) {
            $qb->andWhere('p.listingId = :l')->setParameter('l', $query->filters['listing_id']);
        }
        if (!empty($query->filters['portal_account_id'])) {
            $qb->andWhere('p.portalAccountId = :a')->setParameter('a', $query->filters['portal_account_id']);
        }
        $sort = $query->sort ?? 'updatedAt';
        $qb->orderBy('p.' . $sort, strtoupper($query->direction));
        $total = (int) (clone $qb)->select('COUNT(p.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (PortalPublication $p): array => $this->provider->serializePublication($p), $items),
            $query->meta($total),
        );
    }

    public function queue(Request $request): JsonResponse
    {
        $raw = json_decode($request->getContent(), true);
        $body = is_array($raw) ? $raw : $request->request->all();
        $data = gereq()->make(ValidationRegistry::class)->validate('portals.queue', $body);

        $account = $this->provider->findAccount(trim((string) $data['portal_account_id']));
        if ($account === null || !$account->active()) {
            return ApiResponse::error('portal_inactive', 'Portal account not found or inactive', [], 404);
        }
        $listing = $this->em->find(Listing::class, trim((string) $data['listing_id']));
        if (!$listing instanceof Listing) {
            return ApiResponse::notFound();
        }

        $pub = $this->provider->queuePublication($account, $listing);
        $payload = $this->provider->serializePublication($pub, true);
        $this->events->emit('portal.queued', ['type' => 'portal_publication', 'id' => $pub->id()], $payload);

        return ApiResponse::data($payload, 201);
    }
}
