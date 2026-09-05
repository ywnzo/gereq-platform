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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class CurrencyApiController extends Controller
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
            sortable: ['code', 'name', 'createdAt', 'updatedAt'],
            filterable: ['is_active', 'is_default'],
        );

        $qb = $this->em->getRepository(Currency::class)->createQueryBuilder('c');
        if ($query->search !== '') {
            $qb->andWhere('c.code LIKE :q OR c.name LIKE :q OR c.symbol LIKE :q')
                ->setParameter('q', '%' . $query->search . '%');
        }
        if (isset($query->filters['is_active']) && $query->filters['is_active'] !== '') {
            $qb->andWhere('c.isActive = :active')->setParameter('active', $this->boolValue($query->filters['is_active']));
        }
        if (isset($query->filters['is_default']) && $query->filters['is_default'] !== '') {
            $qb->andWhere('c.isDefault = :default')->setParameter('default', $this->boolValue($query->filters['is_default']));
        }

        $sort = $query->sort ?? 'code';
        $qb->orderBy('c.' . $sort, strtoupper($query->direction));

        $total = (int) (clone $qb)->select('COUNT(c.code)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        /** @var list<Currency> $items */
        $items = $qb->setFirstResult($query->offset())->setMaxResults($query->perPage)->getQuery()->getResult();

        return ApiResponse::collection(
            array_map(fn (Currency $c): array => $this->serialize($c), $items),
            $query->meta($total),
        );
    }

    public function show(string $code): JsonResponse
    {
        $currency = $this->find($code);
        if ($currency === null) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->serialize($currency));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateBody($request, 'currency.api.save');
        $code = strtoupper(trim((string) $data['code']));
        if ($this->find($code) !== null) {
            return ApiResponse::error('conflict', 'Měna s tímto kódem už existuje.', ['code' => 'exists'], 409);
        }

        try {
            $currency = new Currency(
                $code,
                trim((string) $data['name']),
                trim((string) ($data['symbol'] ?? $code)),
                (int) ($data['decimal_places'] ?? 2),
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        }

        $isActive = $this->boolValue($data['is_active'] ?? true, true);
        $isDefault = $this->boolValue($data['is_default'] ?? false);
        $currency->setActive($isActive);
        if ($isDefault) {
            $this->clearDefaults();
            $currency->markDefault(true);
        }

        $this->em->persist($currency);
        $this->em->flush();

        $payload = $this->serialize($currency);
        $this->events->emit('currency.created', ['type' => 'currency', 'id' => $currency->code()], $payload);
        if ($currency->isDefault()) {
            $this->events->emit('currency.default_changed', ['type' => 'currency', 'id' => $currency->code()], $payload);
        }

        return ApiResponse::data($payload, 201);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $currency = $this->find($code);
        if ($currency === null) {
            return ApiResponse::notFound();
        }

        $data = $this->validateBody($request, 'currency.api.update');
        $wasActive = $currency->isActive();
        $wasDefault = $currency->isDefault();

        if (array_key_exists('name', $data) && $data['name'] !== null) {
            $currency->setName(trim((string) $data['name']));
        }
        if (array_key_exists('symbol', $data) && $data['symbol'] !== null) {
            $currency->setSymbol(trim((string) $data['symbol']));
        }
        if (array_key_exists('decimal_places', $data) && $data['decimal_places'] !== null && $data['decimal_places'] !== '') {
            $currency->setDecimalPlaces((int) $data['decimal_places']);
        }

        if (array_key_exists('is_active', $data) && $data['is_active'] !== null && $data['is_active'] !== '') {
            try {
                $currency->setActive($this->boolValue($data['is_active']));
            } catch (\LogicException $e) {
                return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
            }
        }

        if (array_key_exists('is_default', $data) && $data['is_default'] !== null && $data['is_default'] !== '') {
            $makeDefault = $this->boolValue($data['is_default']);
            if ($makeDefault && !$wasDefault) {
                $this->clearDefaults();
                $currency->markDefault(true);
            } elseif (!$makeDefault && $wasDefault) {
                return ApiResponse::error('validation_error', 'Nejdřív nastavte jinou výchozí měnu.', [], 422);
            }
        }

        $this->em->flush();

        $payload = $this->serialize($currency);
        $this->events->emit('currency.updated', ['type' => 'currency', 'id' => $currency->code()], $payload);
        if ($wasActive !== $currency->isActive()) {
            $this->events->emit(
                $currency->isActive() ? 'currency.activated' : 'currency.deactivated',
                ['type' => 'currency', 'id' => $currency->code()],
                $payload,
            );
        }
        if ($currency->isDefault() && !$wasDefault) {
            $this->events->emit('currency.default_changed', ['type' => 'currency', 'id' => $currency->code()], $payload);
        }

        return ApiResponse::data($payload);
    }

    private function find(string $code): ?Currency
    {
        $currency = $this->em->find(Currency::class, strtoupper(trim($code)));

        return $currency instanceof Currency ? $currency : null;
    }

    private function clearDefaults(): void
    {
        /** @var list<Currency> $items */
        $items = $this->em->getRepository(Currency::class)->findBy(['isDefault' => true]);
        foreach ($items as $item) {
            $item->markDefault(false);
        }
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

    private function boolValue(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    /** @return array<string, mixed> */
    private function serialize(Currency $currency): array
    {
        return [
            'code' => $currency->code(),
            'name' => $currency->name(),
            'symbol' => $currency->symbol(),
            'decimal_places' => $currency->decimalPlaces(),
            'is_active' => $currency->isActive(),
            'is_default' => $currency->isDefault(),
            'created_at' => $currency->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $currency->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
