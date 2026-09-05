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
use Modules\CRM\Domain\Deal;
use Modules\Customers\Domain\Customer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class DealApiController extends Controller
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
            sortable: ['title', 'stage', 'amount', 'createdAt', 'updatedAt', 'expectedCloseAt'],
            filterable: ['stage', 'customer_id', 'currency'],
        );

        $qb = $this->em->getRepository(Deal::class)->createQueryBuilder('d');
        if ($query->search !== '') {
            $qb->andWhere('d.title LIKE :q OR d.note LIKE :q')
                ->setParameter('q', '%' . $query->search . '%');
        }
        if (isset($query->filters['stage']) && $query->filters['stage'] !== '') {
            $qb->andWhere('d.stage = :stage')->setParameter('stage', $query->filters['stage']);
        }
        if (isset($query->filters['customer_id']) && $query->filters['customer_id'] !== '') {
            $qb->andWhere('d.customerId = :customer')->setParameter('customer', $query->filters['customer_id']);
        }
        if (isset($query->filters['currency']) && $query->filters['currency'] !== '') {
            $qb->andWhere('d.currency = :currency')->setParameter('currency', strtoupper($query->filters['currency']));
        }

        $sort = $query->sort ?? 'updatedAt';
        $qb->orderBy('d.' . $sort, strtoupper($query->direction));

        $total = (int) (clone $qb)->select('COUNT(d.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<Deal> $items */
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (Deal $d): array => $this->serialize($d), $items),
            $query->meta($total),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateBody($request, 'crm.deal.save');
        $parsed = $this->parseDealData($data);
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }

        $deal = new Deal($parsed['customer_id'], $parsed['title']);
        $deal->setAmount($parsed['amount']);
        $deal->setCurrency($parsed['currency']);
        $deal->setStage($parsed['stage']);
        $deal->setExpectedCloseAt($parsed['expected_close_at']);
        $deal->setNote($parsed['note']);
        $this->em->persist($deal);
        $this->em->flush();

        $payload = $this->serialize($deal);
        $this->events->emit('deal.created', ['type' => 'deal', 'id' => $deal->id()], $payload);

        return ApiResponse::data($payload, 201);
    }

    public function show(string $id): JsonResponse
    {
        $deal = $this->find($id);
        if ($deal === null) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->serialize($deal));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $deal = $this->find($id);
        if ($deal === null) {
            return ApiResponse::notFound();
        }

        $data = $this->validateBody($request, 'crm.deal.save');
        $parsed = $this->parseDealData($data);
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }

        $previousStage = $deal->stage();
        $deal->setCustomerId($parsed['customer_id']);
        $deal->setTitle($parsed['title']);
        $deal->setAmount($parsed['amount']);
        $deal->setCurrency($parsed['currency']);
        $deal->setStage($parsed['stage']);
        $deal->setExpectedCloseAt($parsed['expected_close_at']);
        $deal->setNote($parsed['note']);
        $this->em->flush();

        $payload = $this->serialize($deal);
        $this->events->emit('deal.updated', ['type' => 'deal', 'id' => $deal->id()], $payload);
        if ($previousStage !== $deal->stage()) {
            $this->events->emit('deal.stage_changed', ['type' => 'deal', 'id' => $deal->id()], array_merge($payload, [
                'previous_stage' => $previousStage,
            ]));
        }

        return ApiResponse::data($payload);
    }

    public function destroy(string $id): JsonResponse
    {
        $deal = $this->find($id);
        if ($deal === null) {
            return ApiResponse::notFound();
        }

        $payload = $this->serialize($deal);
        $this->em->remove($deal);
        $this->em->flush();
        $this->events->emit('deal.deleted', ['type' => 'deal', 'id' => $id], $payload);

        return ApiResponse::data(['id' => $id, 'deleted' => true]);
    }

    public function updateStage(Request $request, string $id): JsonResponse
    {
        $deal = $this->find($id);
        if ($deal === null) {
            return ApiResponse::notFound();
        }

        $body = $this->body($request);
        $stage = (string) ($body['stage'] ?? '');
        if (!in_array($stage, Deal::STAGES, true)) {
            return ApiResponse::error('validation_error', 'Neplatná fáze.', ['stage' => 'invalid'], 422);
        }

        $previousStage = $deal->stage();
        $deal->setStage($stage);
        $this->em->flush();

        $payload = $this->serialize($deal);
        $this->events->emit('deal.stage_changed', ['type' => 'deal', 'id' => $deal->id()], array_merge($payload, [
            'previous_stage' => $previousStage,
        ]));
        $this->events->emit('deal.updated', ['type' => 'deal', 'id' => $deal->id()], $payload);

        return ApiResponse::data($payload);
    }

    private function find(string $id): ?Deal
    {
        $deal = $this->em->find(Deal::class, $id);

        return $deal instanceof Deal ? $deal : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{customer_id: string, title: string, amount: ?string, currency: string, stage: string, expected_close_at: ?\DateTimeImmutable, note: ?string}|JsonResponse
     */
    private function parseDealData(array $data): array|JsonResponse
    {
        $customerId = trim((string) ($data['customer_id'] ?? ''));
        if (!$this->em->find(Customer::class, $customerId) instanceof Customer) {
            return ApiResponse::error('validation_error', 'Vyberte platného zákazníka.', ['customer_id' => 'not_found'], 422);
        }

        $amountRaw = trim((string) ($data['amount'] ?? ''));
        $amount = null;
        if ($amountRaw !== '') {
            $normalized = str_replace([' ', ','], ['', '.'], $amountRaw);
            if (!is_numeric($normalized)) {
                return ApiResponse::error('validation_error', 'Neplatná částka.', ['amount' => 'invalid'], 422);
            }
            $amount = number_format((float) $normalized, 2, '.', '');
        }

        $closeRaw = trim((string) ($data['expected_close_at'] ?? ''));
        $closeAt = null;
        if ($closeRaw !== '') {
            try {
                $closeAt = new \DateTimeImmutable($closeRaw);
            } catch (\Exception) {
                return ApiResponse::error('validation_error', 'Neplatné datum.', ['expected_close_at' => 'invalid'], 422);
            }
        }

        $stage = (string) ($data['stage'] ?? 'lead');
        if (!in_array($stage, Deal::STAGES, true)) {
            $stage = 'lead';
        }

        $currency = strtoupper(trim((string) ($data['currency'] ?? 'CZK')));
        if ($currency === '') {
            $currency = 'CZK';
        }

        $note = trim((string) ($data['note'] ?? ''));

        return [
            'customer_id' => $customerId,
            'title' => trim((string) $data['title']),
            'amount' => $amount,
            'currency' => $currency,
            'stage' => $stage,
            'expected_close_at' => $closeAt,
            'note' => $note !== '' ? $note : null,
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
    private function serialize(Deal $deal): array
    {
        return [
            'id' => $deal->id(),
            'customer_id' => $deal->customerId(),
            'title' => $deal->title(),
            'amount' => $deal->amount(),
            'currency' => $deal->currency(),
            'stage' => $deal->stage(),
            'expected_close_at' => $deal->expectedCloseAt()?->format('Y-m-d'),
            'note' => $deal->note(),
            'created_at' => $deal->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $deal->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
