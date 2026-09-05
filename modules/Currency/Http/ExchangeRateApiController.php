<?php

declare(strict_types=1);

namespace Modules\Currency\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Currency\Domain\Currency;
use Modules\Currency\Domain\ExchangeRate;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class ExchangeRateApiController extends Controller
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
            sortable: ['validAt', 'createdAt', 'rate'],
            filterable: ['base_code', 'quote_code', 'provider'],
        );

        $qb = $this->em->getRepository(ExchangeRate::class)->createQueryBuilder('r');
        if (isset($query->filters['base_code']) && $query->filters['base_code'] !== '') {
            $qb->andWhere('r.baseCode = :base')->setParameter('base', strtoupper($query->filters['base_code']));
        }
        if (isset($query->filters['quote_code']) && $query->filters['quote_code'] !== '') {
            $qb->andWhere('r.quoteCode = :quote')->setParameter('quote', strtoupper($query->filters['quote_code']));
        }
        if (isset($query->filters['provider']) && $query->filters['provider'] !== '') {
            $qb->andWhere('r.provider = :provider')->setParameter('provider', $query->filters['provider']);
        }

        $sort = $query->sort ?? 'validAt';
        $qb->orderBy('r.' . $sort, strtoupper($query->direction));

        $total = (int) (clone $qb)->select('COUNT(r.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<ExchangeRate> $items */
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (ExchangeRate $r): array => $this->serialize($r), $items),
            $query->meta($total),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateBody($request, 'currency.exchange_rate.save');
        $base = strtoupper(trim((string) $data['base_code']));
        $quote = strtoupper(trim((string) $data['quote_code']));

        if ($this->em->find(Currency::class, $base) === null || $this->em->find(Currency::class, $quote) === null) {
            return ApiResponse::error('validation_error', 'Základní nebo kotovaná měna neexistuje.', [], 422);
        }

        $validAt = null;
        if (!empty($data['valid_at'])) {
            try {
                $validAt = new \DateTimeImmutable((string) $data['valid_at']);
            } catch (\Exception) {
                return ApiResponse::error('validation_error', 'Neplatné valid_at.', ['valid_at' => 'invalid'], 422);
            }
        }

        $id = isset($data['id']) ? trim((string) $data['id']) : '';
        if ($id !== '') {
            $existing = $this->em->find(ExchangeRate::class, $id);
            if ($existing instanceof ExchangeRate) {
                try {
                    $existing->setRate((string) $data['rate']);
                    $existing->setProvider((string) ($data['provider'] ?? $existing->provider()));
                    if ($validAt !== null) {
                        $existing->setValidAt($validAt);
                    }
                } catch (\InvalidArgumentException $e) {
                    return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
                }
                $this->em->flush();
                $payload = $this->serialize($existing);
                $this->events->emit('exchange_rate.updated', ['type' => 'exchange_rate', 'id' => $existing->id()], $payload);

                return ApiResponse::data($payload);
            }
        }

        try {
            $rate = new ExchangeRate(
                $base,
                $quote,
                (string) $data['rate'],
                $validAt,
                (string) ($data['provider'] ?? 'manual'),
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }

        $this->em->persist($rate);
        $this->em->flush();

        $payload = $this->serialize($rate);
        $this->events->emit('exchange_rate.created', ['type' => 'exchange_rate', 'id' => $rate->id()], $payload);

        return ApiResponse::data($payload, 201);
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
    private function serialize(ExchangeRate $rate): array
    {
        return [
            'id' => $rate->id(),
            'base_code' => $rate->baseCode(),
            'quote_code' => $rate->quoteCode(),
            'rate' => $rate->rate(),
            'provider' => $rate->provider(),
            'valid_at' => $rate->validAt()->format(\DateTimeInterface::ATOM),
            'created_at' => $rate->createdAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
