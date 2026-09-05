<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\CRM\Domain\Activity;
use Modules\CRM\Domain\Deal;
use Modules\Customers\Domain\Customer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class ActivityApiController extends Controller
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
        $query = ApiQuery::fromRequest(
            $request,
            sortable: ['subject', 'type', 'dueAt', 'createdAt', 'updatedAt', 'completedAt'],
            filterable: ['type', 'customer_id', 'deal_id', 'status'],
        );

        $qb = $this->em->getRepository(Activity::class)->createQueryBuilder('a');
        self_repair_search($qb, 'a', ['subject', 'body'], $query->search);
        if (isset($query->filters['type']) && $query->filters['type'] !== '') {
            $qb->andWhere('a.type = :type')->setParameter('type', $query->filters['type']);
        }
        if (isset($query->filters['customer_id']) && $query->filters['customer_id'] !== '') {
            $qb->andWhere('a.customerId = :customer')->setParameter('customer', $query->filters['customer_id']);
        }
        if (isset($query->filters['deal_id']) && $query->filters['deal_id'] !== '') {
            $qb->andWhere('a.dealId = :deal')->setParameter('deal', $query->filters['deal_id']);
        }
        if (($query->filters['status'] ?? '') === 'open') {
            $qb->andWhere('a.completedAt IS NULL');
        } elseif (($query->filters['status'] ?? '') === 'done') {
            $qb->andWhere('a.completedAt IS NOT NULL');
        }

        $sort = $query->sort ?? 'createdAt';
        $qb->orderBy('a.' . $sort, strtoupper($query->direction));

        $total = (int) (clone $qb)->select('COUNT(a.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<Activity> $items */
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (Activity $a): array => $this->serialize($a), $items),
            $query->meta($total),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateBody($request, 'crm.activity.save');
        $parsed = $this->parseActivityData($data);
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }

        $activity = new Activity($parsed['customer_id'], $parsed['subject'], $parsed['type']);
        $activity->setDealId($parsed['deal_id']);
        $activity->setBody($parsed['body']);
        $activity->setDueAt($parsed['due_at']);
        $this->em->persist($activity);
        $this->em->flush();

        $payload = $this->serialize($activity);
        $this->events->emit('activity.created', ['type' => 'activity', 'id' => $activity->id()], $payload);

        return ApiResponse::data($payload, 201);
    }

    public function show(string $id): JsonResponse
    {
        $activity = $this->find($id);
        if ($activity === null) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->serialize($activity));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $activity = $this->find($id);
        if ($activity === null) {
            return ApiResponse::notFound();
        }

        $data = $this->validateBody($request, 'crm.activity.save');
        $parsed = $this->parseActivityData($data);
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }

        $activity->setCustomerId($parsed['customer_id']);
        $activity->setDealId($parsed['deal_id']);
        $activity->setType($parsed['type']);
        $activity->setSubject($parsed['subject']);
        $activity->setBody($parsed['body']);
        $activity->setDueAt($parsed['due_at']);
        $this->em->flush();

        $payload = $this->serialize($activity);
        $this->events->emit('activity.updated', ['type' => 'activity', 'id' => $activity->id()], $payload);

        return ApiResponse::data($payload);
    }

    public function complete(string $id): JsonResponse
    {
        $activity = $this->find($id);
        if ($activity === null) {
            return ApiResponse::notFound();
        }

        if ($activity->isCompleted()) {
            $activity->reopen();
            $event = 'activity.reopened';
        } else {
            $activity->complete();
            $event = 'activity.completed';
        }
        $this->em->flush();

        $payload = $this->serialize($activity);
        $this->events->emit($event, ['type' => 'activity', 'id' => $activity->id()], $payload);
        $this->events->emit('activity.updated', ['type' => 'activity', 'id' => $activity->id()], $payload);

        return ApiResponse::data($payload);
    }

    public function destroy(string $id): JsonResponse
    {
        $activity = $this->find($id);
        if ($activity === null) {
            return ApiResponse::notFound();
        }

        $payload = $this->serialize($activity);
        $this->em->remove($activity);
        $this->em->flush();
        $this->events->emit('activity.deleted', ['type' => 'activity', 'id' => $id], $payload);

        return ApiResponse::data(['id' => $id, 'deleted' => true]);
    }

    private function find(string $id): ?Activity
    {
        $activity = $this->em->find(Activity::class, $id);

        return $activity instanceof Activity ? $activity : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{customer_id: string, deal_id: ?string, type: string, subject: string, body: ?string, due_at: ?\DateTimeImmutable}|JsonResponse
     */
    private function parseActivityData(array $data): array|JsonResponse
    {
        $customerId = trim((string) ($data['customer_id'] ?? ''));
        if (!$this->em->find(Customer::class, $customerId) instanceof Customer) {
            return ApiResponse::error('validation_error', 'Vyberte platného zákazníka.', ['customer_id' => 'not_found'], 422);
        }

        $dealId = trim((string) ($data['deal_id'] ?? ''));
        if ($dealId !== '') {
            $deal = $this->em->find(Deal::class, $dealId);
            if (!$deal instanceof Deal || $deal->customerId() !== $customerId) {
                return ApiResponse::error('validation_error', 'Obchod nepatří k vybranému zákazníkovi.', ['deal_id' => 'invalid'], 422);
            }
        } else {
            $dealId = '';
        }

        $type = (string) ($data['type'] ?? 'note');
        if (!in_array($type, Activity::TYPES, true)) {
            $type = 'note';
        }

        $dueRaw = trim((string) ($data['due_at'] ?? ''));
        $dueAt = null;
        if ($dueRaw !== '') {
            try {
                $dueAt = new \DateTimeImmutable($dueRaw);
            } catch (\Exception) {
                return ApiResponse::error('validation_error', 'Neplatné datum.', ['due_at' => 'invalid'], 422);
            }
        }

        $body = trim((string) ($data['body'] ?? ''));

        return [
            'customer_id' => $customerId,
            'deal_id' => $dealId !== '' ? $dealId : null,
            'type' => $type,
            'subject' => trim((string) $data['subject']),
            'body' => $body !== '' ? $body : null,
            'due_at' => $dueAt,
        ];
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
    private function serialize(Activity $activity): array
    {
        return [
            'id' => $activity->id(),
            'customer_id' => $activity->customerId(),
            'deal_id' => $activity->dealId(),
            'type' => $activity->type(),
            'subject' => $activity->subject(),
            'body' => $activity->body(),
            'due_at' => $activity->dueAt()?->format(\DateTimeInterface::ATOM),
            'completed_at' => $activity->completedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $activity->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $activity->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
