<?php

declare(strict_types=1);

namespace Modules\BikeService\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\BikeService\Application\BikeIndexClient;
use Modules\BikeService\Application\BikeServiceProvider;
use Modules\BikeService\Application\CityBikesClient;
use Modules\BikeService\Application\OsmBikePoisClient;
use Modules\BikeService\Domain\Bike;
use Modules\BikeService\Domain\BikeJob;
use Modules\BikeService\Domain\BikeOrder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class BikeServiceApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly BikeServiceProvider $bikeservice,
        private readonly BikeIndexClient $bikeIndex,
        private readonly CityBikesClient $cityBikes,
        private readonly OsmBikePoisClient $osmPois,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function bikes(Request $request): Response
    {
        $qb = $this->em->getRepository(Bike::class)->createQueryBuilder('b')->orderBy('b.updatedAt', 'DESC')->setMaxResults(100);
        $kind = (string) $request->query->get('kind', '');
        if (in_array($kind, Bike::KINDS, true)) {
            $qb->andWhere('b.kind = :k')->setParameter('k', $kind);
        }

        return $this->json([
            'data' => array_map(
                fn (Bike $b): array => $this->bikeservice->serializeBike($b),
                $qb->getQuery()->getResult(),
            ),
        ]);
    }

    public function showBike(string $id): Response
    {
        $bike = $this->bikeservice->getBike($id);
        if ($bike === null) {
            return $this->json(['error' => 'not_found'], 404);
        }

        return $this->json(['data' => $this->bikeservice->serializeBike($bike)]);
    }

    public function storeBike(Request $request): Response
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent() ?: '{}', true) ?: [];
        try {
            $bike = $this->bikeservice->createBike($payload);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        return $this->json(['data' => $this->bikeservice->serializeBike($bike)], 201);
    }

    public function orders(Request $request): Response
    {
        $qb = $this->em->getRepository(BikeOrder::class)->createQueryBuilder('o')->orderBy('o.updatedAt', 'DESC')->setMaxResults(100);
        $status = (string) $request->query->get('status', '');
        if (in_array($status, BikeOrder::STATUSES, true)) {
            $qb->andWhere('o.status = :s')->setParameter('s', $status);
        }

        return $this->json([
            'data' => array_map(
                fn (BikeOrder $o): array => $this->bikeservice->serializeOrder($o),
                $qb->getQuery()->getResult(),
            ),
        ]);
    }

    public function showOrder(string $id): Response
    {
        $order = $this->bikeservice->getOrder($id);
        if ($order === null) {
            return $this->json(['error' => 'not_found'], 404);
        }

        return $this->json(['data' => $this->bikeservice->serializeOrder($order)]);
    }

    public function storeOrder(Request $request): Response
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent() ?: '{}', true) ?: [];
        $bikeId = (string) ($payload['bike_id'] ?? '');
        $customerId = isset($payload['customer_id']) ? (string) $payload['customer_id'] : null;
        try {
            $order = $this->bikeservice->createOrder($bikeId, $customerId, $payload);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        return $this->json(['data' => $this->bikeservice->serializeOrder($order)], 201);
    }

    public function finishOrder(string $id): Response
    {
        $order = $this->bikeservice->getOrder($id);
        if ($order === null) {
            return $this->json(['error' => 'not_found'], 404);
        }
        $this->bikeservice->finishOrder($order);

        return $this->json(['data' => $this->bikeservice->serializeOrder($order)]);
    }

    public function addJob(Request $request, string $id): Response
    {
        $order = $this->bikeservice->getOrder($id);
        if ($order === null) {
            return $this->json(['error' => 'not_found'], 404);
        }
        /** @var array<string, mixed> $payload */
        $payload = json_decode($request->getContent() ?: '{}', true) ?: [];
        $job = $this->bikeservice->startJob(
            $order,
            (string) ($payload['type'] ?? 'work'),
            isset($payload['employee_user_id']) ? (string) $payload['employee_user_id'] : null,
            isset($payload['note']) ? (string) $payload['note'] : null,
        );

        return $this->json([
            'data' => [
                'id' => $job->id(),
                'order_id' => $job->orderId(),
                'type' => $job->type(),
                'started_at' => $job->startedAt()->format(\DateTimeInterface::ATOM),
            ],
        ], 201);
    }

    public function completeJob(string $id, string $jobId): Response
    {
        $order = $this->bikeservice->getOrder($id);
        $job = $this->em->find(BikeJob::class, $jobId);
        if ($order === null || !$job instanceof BikeJob || $job->orderId() !== $order->id()) {
            return $this->json(['error' => 'not_found'], 404);
        }
        $this->bikeservice->completeJob($job);

        return $this->json([
            'data' => [
                'id' => $job->id(),
                'finished_at' => $job->finishedAt()?->format(\DateTimeInterface::ATOM),
                'duration_minutes' => $job->durationMinutes(),
            ],
        ]);
    }

    public function bikeIndexSearch(Request $request): Response
    {
        $serial = trim((string) $request->query->get('serial', ''));
        if ($serial === '') {
            return $this->json(['error' => 'serial_required'], 422);
        }

        return $this->json($this->bikeIndex->searchBySerial($serial, 15));
    }

    public function cityBikesNetwork(string $id): Response
    {
        return $this->json($this->cityBikes->network($id));
    }

    public function osmNearby(Request $request): Response
    {
        $lat = (float) $request->query->get('lat', '50.087');
        $lon = (float) $request->query->get('lon', '14.421');
        $radius = (int) $request->query->get('radius', '2500');

        return $this->json($this->osmPois->nearby($lat, $lon, $radius));
    }
}
