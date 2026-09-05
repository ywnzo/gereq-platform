<?php

declare(strict_types=1);

namespace Modules\Currency\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Currency\Domain\Currency;
use Modules\Currency\Domain\ExchangeRate;
use Modules\Currency\Application\SyncExchangeRates;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CurrencyAdminController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Auditor $auditor,
        private readonly EventBus $events,
        private readonly SyncExchangeRates $rateSync,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $qb = $this->em->getRepository(Currency::class)->createQueryBuilder('c')
            ->orderBy('c.isDefault', 'DESC')
            ->addOrderBy('c.code', 'ASC');

        if ($search !== '') {
            $qb->andWhere('c.code LIKE :q OR c.name LIKE :q OR c.symbol LIKE :q')
                ->setParameter('q', '%' . $search . '%');
        }

        /** @var list<Currency> $items */
        $items = $qb->getQuery()->getResult();

        return $this->page('@currency/index.html.twig', [
            'pageTitle' => $this->translator->trans('currency.title'),
            'items' => $items,
            'search' => $search,
            'rates' => $this->latestCnbRates(),
        ]);
    }

    public function syncRates(): Response
    {
        try {
            $result = $this->rateSync->sync();
            $this->auditor->log('currency.rates.synced', 'currency', 'cnb', null, [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'skipped' => $result['skipped'],
                'valid_at' => $result['valid_at'],
            ]);
            $this->flash(
                'success',
                $this->translator->trans('currency.flash.rates_synced', [
                    '%created%' => (string) $result['created'],
                    '%updated%' => (string) $result['updated'],
                    '%skipped%' => (string) $result['skipped'],
                    '%valid_at%' => (string) ($result['valid_at'] ?? '—'),
                ]),
            );
        } catch (\Throwable $e) {
            $this->flash('error', $this->translator->trans('currency.flash.rates_failed', ['%error%' => $e->getMessage()]));
        }

        return $this->redirect('admin.currencies.index');
    }

    /**
     * @return list<array{base: string, quote: string, rate: string, provider: string, valid_at: string}>
     */
    private function latestCnbRates(): array
    {
        /** @var list<ExchangeRate> $rows */
        $rows = $this->em->getRepository(ExchangeRate::class)->createQueryBuilder('r')
            ->where('r.provider = :provider')
            ->setParameter('provider', 'cnb')
            ->orderBy('r.validAt', 'DESC')
            ->addOrderBy('r.baseCode', 'ASC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();

        $latestByPair = [];
        foreach ($rows as $row) {
            $key = $row->baseCode() . '/' . $row->quoteCode();
            if (isset($latestByPair[$key])) {
                continue;
            }
            $latestByPair[$key] = [
                'base' => $row->baseCode(),
                'quote' => $row->quoteCode(),
                'rate' => rtrim(rtrim($row->rate(), '0'), '.') ?: '0',
                'provider' => $row->provider(),
                'valid_at' => $row->validAt()->format('d.m.Y H:i'),
            ];
        }

        return array_values($latestByPair);
    }

    public function create(): Response
    {
        return $this->page('@currency/form.html.twig', [
            'pageTitle' => $this->translator->trans('currency.new'),
            'item' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request, true);
        if ($this->em->find(Currency::class, $data['code']) instanceof Currency) {
            $this->abort(422, $this->translator->trans('currency.error.exists'));
        }

        $currency = new Currency($data['code'], $data['name'], $data['symbol'], $data['decimal_places']);
        $currency->setActive($data['is_active']);
        if ($data['is_default']) {
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
        $this->auditor->log('currency.created', 'currency', $currency->code(), null, $payload);
        $this->forgetForm('currency.save');
        $this->flash('success', $this->translator->trans('currency.flash.created', ['%code%' => $currency->code()]));

        return $this->redirect('admin.currencies.index');
    }

    public function edit(string $code): Response
    {
        $currency = $this->find($code);
        if ($currency === null) {
            $this->abort(404, $this->translator->trans('currency.error.not_found'));
        }

        return $this->page('@currency/form.html.twig', [
            'pageTitle' => $this->translator->trans('currency.edit'),
            'item' => $currency,
        ]);
    }

    public function update(Request $request, string $code): Response
    {
        $currency = $this->find($code);
        if ($currency === null) {
            $this->abort(404, $this->translator->trans('currency.error.not_found'));
        }

        $data = $this->validated($request, false);
        $wasActive = $currency->isActive();
        $wasDefault = $currency->isDefault();

        $currency->setName($data['name']);
        $currency->setSymbol($data['symbol']);
        $currency->setDecimalPlaces($data['decimal_places']);

        try {
            $currency->setActive($data['is_active']);
        } catch (\LogicException $e) {
            $this->abort(422, $e->getMessage());
        }

        if ($data['is_default'] && !$wasDefault) {
            $this->clearDefaults();
            $currency->markDefault(true);
        } elseif (!$data['is_default'] && $wasDefault) {
            $this->abort(422, $this->translator->trans('currency.error.keep_default'));
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

        $this->auditor->log('currency.updated', 'currency', $currency->code());
        $this->forgetForm('currency.save');
        $this->flash('success', $this->translator->trans('currency.flash.saved'));

        return $this->redirect('admin.currencies.index');
    }

    public function setDefault(string $code): Response
    {
        $currency = $this->find($code);
        if ($currency === null) {
            $this->abort(404, $this->translator->trans('currency.error.not_found'));
        }

        $this->clearDefaults();
        $currency->markDefault(true);
        $this->em->flush();

        $payload = $this->serialize($currency);
        $this->events->emit('currency.default_changed', ['type' => 'currency', 'id' => $currency->code()], $payload);
        $this->events->emit('currency.updated', ['type' => 'currency', 'id' => $currency->code()], $payload);
        $this->auditor->log('currency.default_changed', 'currency', $currency->code());
        $this->flash('success', $this->translator->trans('currency.flash.default_set', ['%code%' => $currency->code()]));

        return $this->redirect('admin.currencies.index');
    }

    private function find(string $code): ?Currency
    {
        $currency = $this->em->find(Currency::class, strtoupper(trim($code)));

        return $currency instanceof Currency ? $currency : null;
    }

    /**
     * @return array{code: string, name: string, symbol: string, decimal_places: int, is_active: bool, is_default: bool}
     */
    private function validated(Request $request, bool $requireCode): array
    {
        $data = $this->validate($request, 'currency.save');

        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($requireCode && !preg_match('/^[A-Z]{3}$/', $code)) {
            $this->abort(422, $this->translator->trans('currency.error.code_format'));
        }

        return [
            'code' => $code,
            'name' => trim((string) $data['name']),
            'symbol' => trim((string) ($data['symbol'] ?? '')),
            'decimal_places' => max(0, min(6, (int) ($data['decimal_places'] ?? 2))),
            'is_active' => $this->boolValue($data['is_active'] ?? true),
            'is_default' => $this->boolValue($data['is_default'] ?? false),
        ];
    }

    private function clearDefaults(): void
    {
        /** @var list<Currency> $items */
        $items = $this->em->getRepository(Currency::class)->findBy(['isDefault' => true]);
        foreach ($items as $item) {
            $item->markDefault(false);
        }
    }

    private function boolValue(mixed $value): bool
    {
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

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }
}
