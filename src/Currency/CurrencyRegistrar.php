<?php

declare(strict_types=1);

namespace Gereq\Currency;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Dashboard\Dashboard;
use Gereq\Admin\Form\Forms;
use Gereq\Admin\Menu\Menu;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Api\ApiRegistry;
use Gereq\Auth\Authenticator;
use Gereq\Currency\Application\CurrencyAdminTable;
use Gereq\Currency\Application\CurrencyBuilder;
use Gereq\Currency\Application\CurrencyConverter;
use Gereq\Currency\Application\UserCurrencyPreferenceStore;
use Gereq\Currency\Application\Widgets\CurrencyShortcutWidget;
use Gereq\Currency\Application\Widgets\CurrencyStatsWidget;
use Gereq\Currency\Application\Widgets\ExchangeRatesWidget;
use Gereq\Currency\Domain\Currency;
use Gereq\Currency\Domain\ExchangeRate;
use Gereq\Currency\Http\ConvertApiController;
use Gereq\Currency\Http\CurrencyAdminController;
use Gereq\Currency\Http\CurrencyApiController;
use Gereq\Currency\Http\ExchangeRateApiController;
use Gereq\Event\EventDefinition;
use Gereq\Event\EventRegistry;
use Gereq\Hook\HookManager;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\User\Entity\User;
use Gereq\User\PermissionRegistry;
use Gereq\Validation\ValidationRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Core registration for currencies / FX (formerly modules/Currency).
 */
final class CurrencyRegistrar
{
    public function __construct(
        private readonly Router $router,
        private readonly Menu $menu,
        private readonly PermissionRegistry $permissions,
        private readonly CapabilityRegistry $capabilities,
        private readonly ApiRegistry $api,
        private readonly EventRegistry $platformEvents,
        private readonly Dashboard $dashboard,
        private readonly CommandPalette $palette,
        private readonly ValidationRegistry $validation,
        private readonly Forms $forms,
        private readonly Settings $settings,
        private readonly HookManager $hooks,
        private readonly EntityManagerInterface $em,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly Authenticator $auth,
        private readonly CurrencyBuilder $money,
        private readonly CurrencyConverter $converter,
        private readonly UserCurrencyPreferenceStore $prefs,
        private readonly CurrencyAdminTable $currencyTable,
    ) {
    }

    public function register(): void
    {
        $this->permissions();
        $this->settingsDefs();
        $this->capabilities();
        $this->routes();
        $this->api();
        $this->platformEvents();
        $this->validation();
        $this->menu();
        $this->dashboard();
        $this->commands();
        $this->seedDefaults();
        $this->registerTwig();
        $this->registerProfileHooks();
        $this->registerSettingsPanel();
    }

    private function permissions(): void
    {
        $this->permissions->register('currency.view', 'perm.currency.view', 'perm.group.currency');
        $this->permissions->register('currency.manage', 'perm.currency.manage', 'perm.group.currency');
    }

    private function settingsDefs(): void
    {
        $this->settings->define(
            'currency.vat_payer',
            SettingRecord::TYPE_BOOLEAN,
            'currency.settings.vat_payer',
            false,
            'core',
        );
        $this->settings->define(
            'currency.vat_rate',
            SettingRecord::TYPE_STRING,
            'currency.settings.vat_rate',
            '21',
            'core',
        );
    }

    private function capabilities(): void
    {
        $this->capabilities->register('currency', 'core', $this->money);
    }

    private function routes(): void
    {
        $this->router->group('/admin/currencies', function (Router $routes): void {
            $routes->get('', [CurrencyAdminController::class, 'index'])
                ->name('admin.currencies.index')
                ->permission('currency.view');
            $routes->get('/create', [CurrencyAdminController::class, 'create'])
                ->name('admin.currencies.create')
                ->permission('currency.manage');
            $routes->post('', [CurrencyAdminController::class, 'store'])
                ->name('admin.currencies.store')
                ->permission('currency.manage');
            $routes->post('/rates/sync', [CurrencyAdminController::class, 'syncRates'])
                ->name('admin.currencies.rates.sync')
                ->permission('currency.manage');
            $routes->get('/{code}/edit', [CurrencyAdminController::class, 'edit'])
                ->name('admin.currencies.edit')
                ->permission('currency.manage');
            $routes->post('/{code}', [CurrencyAdminController::class, 'update'])
                ->name('admin.currencies.update')
                ->permission('currency.manage');
            $routes->post('/{code}/default', [CurrencyAdminController::class, 'setDefault'])
                ->name('admin.currencies.default')
                ->permission('currency.manage');
        }, ['middleware' => ['auth']]);
    }

    private function api(): void
    {
        $this->api->registerKnownScope('currency.read');
        $this->api->registerKnownScope('currency.write');

        $this->api->module('currencies', function (ApiModule $api): void {
            $api->get('/', [CurrencyApiController::class, 'index'])->scope('currency.read')
                ->summary('List currencies');
            $api->post('/', [CurrencyApiController::class, 'store'])->scope('currency.write')
                ->summary('Create currency')->ruleset('currency.api.save');
            $api->get('/{code}', [CurrencyApiController::class, 'show'])->scope('currency.read')
                ->summary('Get currency');
            $api->patch('/{code}', [CurrencyApiController::class, 'update'])->scope('currency.write')
                ->summary('Update currency')->ruleset('currency.api.update');
        });

        $this->api->module('exchange-rates', function (ApiModule $api): void {
            $api->get('/', [ExchangeRateApiController::class, 'index'])->scope('currency.read')
                ->summary('List exchange rates');
            $api->post('/', [ExchangeRateApiController::class, 'store'])->scope('currency.write')
                ->summary('Create exchange rate')->ruleset('currency.exchange_rate.save');
        });

        $this->api->module('currency', function (ApiModule $api): void {
            $api->post('/convert', [ConvertApiController::class, 'convert'])->scope('currency.read')
                ->summary('Convert amount')->ruleset('currency.convert');
        });
    }

    private function platformEvents(): void
    {
        foreach (
            [
                'currency.created' => 'Měna vytvořena',
                'currency.updated' => 'Měna upravena',
                'currency.activated' => 'Měna aktivována',
                'currency.deactivated' => 'Měna deaktivována',
                'currency.default_changed' => 'Změněna výchozí měna',
                'exchange_rate.created' => 'Směnný kurz vytvořen',
                'exchange_rate.updated' => 'Směnný kurz upraven',
            ] as $type => $description
        ) {
            $this->platformEvents->register(
                (new EventDefinition($type, 'core'))
                    ->description($description)
                    ->webhookEnabled(true)
            );
        }
    }

    private function validation(): void
    {
        $this->validation->ruleset('currency.save', [
            'code' => 'required|min:3|max:3',
            'name' => 'required|min:2|max:120',
            'symbol' => 'required|min:1|max:16',
            'decimal_places' => 'nullable',
            'is_active' => 'nullable',
            'is_default' => 'nullable',
        ]);
        $this->forms->entity('currency.save', Currency::class);

        $this->validation->ruleset('currency.api.save', [
            'code' => 'required|min:3|max:3',
            'name' => 'required|min:2|max:120',
            'symbol' => 'nullable|max:16',
            'decimal_places' => 'nullable',
            'is_active' => 'nullable',
            'is_default' => 'nullable',
        ]);

        $this->validation->ruleset('currency.api.update', [
            'name' => 'nullable|min:2|max:120',
            'symbol' => 'nullable|max:16',
            'decimal_places' => 'nullable',
            'is_active' => 'nullable',
            'is_default' => 'nullable',
        ]);

        $this->validation->ruleset('currency.exchange_rate.save', [
            'base_code' => 'required|min:3|max:3',
            'quote_code' => 'required|min:3|max:3',
            'rate' => 'required',
            'provider' => 'nullable|max:64',
            'valid_at' => 'nullable',
            'id' => 'nullable|max:36',
        ]);

        $this->validation->ruleset('currency.convert', [
            'amount' => 'required',
            'currency' => 'required|min:3|max:3',
            'target_currency' => 'required|min:3|max:3',
        ]);
    }

    private function menu(): void
    {
        // Hidden — managed under Settings → Platform; kept for breadcrumbs / active state.
        $this->menu->add(new MenuItem(
            id: 'currencies',
            label: 'nav.currencies',
            icon: 'coins',
            route: 'admin.currencies.index',
            permission: 'currency.view',
            parent: 'settings',
            order: 40,
            hidden: true,
        ));
        $this->menu->add(new MenuItem(
            id: 'currencies.create',
            label: 'nav.currencies.create',
            route: 'admin.currencies.create',
            permission: 'currency.manage',
            parent: 'currencies',
            order: 41,
            hidden: true,
        ));
    }

    private function dashboard(): void
    {
        $this->dashboard->addWidget(new CurrencyShortcutWidget(
            $this->twig,
            $this->translator,
            $this->router,
        ));
        $this->dashboard->addWidget(new CurrencyStatsWidget(
            $this->twig,
            $this->em,
            $this->money,
            $this->translator,
            $this->router,
        ));
        $this->dashboard->addWidget(new ExchangeRatesWidget(
            $this->twig,
            $this->em,
            $this->money,
            $this->converter,
            $this->translator,
            $this->router,
        ));
    }

    private function commands(): void
    {
        $this->palette->add(new PaletteCommand(
            id: 'currencies.index',
            label: 'palette.currency.index',
            url: '/admin/settings?tab=core#settings-currencies',
            icon: 'coins',
            permission: 'settings.manage',
            keywords: ['currency', 'kurz', 'měna', 'czk', 'settings', 'nastavení', 'platforma'],
        ));
        $this->palette->add(new PaletteCommand(
            id: 'currencies.create',
            label: 'palette.currency.create',
            url: '/admin/currencies/create',
            icon: 'plus',
            permission: 'currency.manage',
            group: 'Akce',
        ));
    }

    private function registerSettingsPanel(): void
    {
        $em = $this->em;
        $twig = $this->twig;
        $auth = $this->auth;
        /** @var list<Currency>|null $cachedItems */
        $cachedItems = null;

        $loadItems = function () use ($em, &$cachedItems): array {
            if ($cachedItems !== null) {
                return $cachedItems;
            }
            /** @var list<Currency> $rows */
            $rows = $em->getRepository(Currency::class)->createQueryBuilder('c')
                ->orderBy('c.isDefault', 'DESC')
                ->addOrderBy('c.code', 'ASC')
                ->getQuery()
                ->getResult();
            $cachedItems = $rows;

            return $cachedItems;
        };

        $this->hooks->addFilter('admin.settings.panel.core', function (string $html) use ($twig, $auth, $loadItems): string {
            $user = $auth->user();
            if (!$user instanceof User || !$user->can('currency.view')) {
                return $html;
            }

            $items = $loadItems();

            return $html . $twig->render('currency/partials/manage.html.twig', [
                'items' => $items,
                'tableHtml' => $this->currencyTable->render($items, $user),
                'rates' => $this->latestCnbRatesForSettings(),
                'embedded' => true,
                'user' => $user,
            ]);
        });

        // Must render outside <form id="settings-form"> (no nested forms).
        $this->hooks->addFilter('admin.settings.extra_forms', function (string $html) use ($twig, $auth, $loadItems): string {
            $user = $auth->user();
            if (!$user instanceof User || !$user->can('currency.manage')) {
                return $html;
            }

            return $html . $twig->render('currency/partials/manage_forms.html.twig', [
                'items' => $loadItems(),
                'user' => $user,
            ]);
        });
    }

    /**
     * @return list<array{base: string, quote: string, rate: string, provider: string, valid_at: string}>
     */
    private function latestCnbRatesForSettings(): array
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
            if (!$row instanceof ExchangeRate) {
                continue;
            }
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

    private function registerTwig(): void
    {
        $money = $this->money;
        $this->twig->addFilter(new TwigFilter('money', [$money, 'format']));
        $this->twig->addFilter(new TwigFilter('money_code', [$money, 'formatWithCode']));
        $this->twig->addFilter(new TwigFilter('money_value', [$money, 'make']));
        $this->twig->addFunction(new TwigFunction('money', [$money, 'format']));
        $this->twig->addFunction(new TwigFunction('money_code', [$money, 'formatWithCode']));
        $this->twig->addFunction(new TwigFunction('money_value', [$money, 'make']));
        $this->twig->addFunction(new TwigFunction('currency_code', [$money, 'displayCode']));
        $this->twig->addFunction(new TwigFunction('currency_symbol', [$money, 'symbol']));
        $this->twig->addFunction(new TwigFunction('currency_shop_code', [$money, 'defaultCode']));
        $this->twig->addFunction(new TwigFunction('currencies', [$money, 'activeCodes']));
    }

    private function registerProfileHooks(): void
    {
        $money = $this->money;
        $prefs = $this->prefs;
        $twig = $this->twig;
        $auth = $this->auth;
        $settings = $this->settings;

        $this->hooks->addFilter('admin.profile.form_fields', function (string $html) use ($twig, $money, $prefs, $auth): string {
            $user = $auth->user();
            $selected = $user instanceof User ? $prefs->get($user->id()) : null;
            $canManageVat = $user instanceof User && $user->can('currency.manage');
            $field = $twig->render('currency/partials/profile_currency.html.twig', [
                'currencies' => $money->activeCodes(),
                'selected' => $selected,
                'shop_default' => $money->defaultCode(),
                'can_manage_vat' => $canManageVat,
                'vat_payer' => $money->isVatPayer(),
                'vat_rate' => rtrim(rtrim(number_format($money->vatRate(), 2, '.', ''), '0'), '.') ?: '0',
            ]);

            return $html . $field;
        });

        $this->hooks->addAction(
            'admin.profile.saving',
            function (User $user, Request $request) use ($money, $prefs, $settings): void {
                $raw = trim((string) $request->request->get('preferred_currency', ''));
                if ($raw === '' || $raw === '_default') {
                    $prefs->set($user->id(), null);
                } else {
                    $code = strtoupper($raw);
                    if (in_array($code, $money->activeCodes(), true)) {
                        $prefs->set($user->id(), $code);
                        $money->rememberDisplayCode($code);
                    }
                }

                if ($user->can('currency.manage')) {
                    $vatPayer = $request->request->getBoolean('currency_vat_payer');
                    $settings->set(
                        'currency.vat_payer',
                        $vatPayer,
                        SettingRecord::TYPE_BOOLEAN,
                        'core',
                    );
                    $rateRaw = trim((string) $request->request->get('currency_vat_rate', '21'));
                    $rate = is_numeric($rateRaw) ? (float) $rateRaw : 21.0;
                    $rate = max(0.0, min(100.0, $rate));
                    $settings->set(
                        'currency.vat_rate',
                        rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') ?: '0',
                        SettingRecord::TYPE_STRING,
                        'core',
                    );
                }

                $money->clearCache();
            },
        );
    }

    private function seedDefaults(): void
    {
        try {
            $count = (int) $this->em->getRepository(Currency::class)->count([]);
        } catch (\Throwable) {
            // DB may be unavailable during early bootstrap / tooling without drivers.
            return;
        }
        if ($count > 0) {
            return;
        }

        $seeds = [
            ['CZK', 'Česká koruna', 'Kč', 2, true],
            ['EUR', 'Euro', '€', 2, false],
            ['USD', 'Americký dolar', '$', 2, false],
        ];

        foreach ($seeds as [$code, $name, $symbol, $decimals, $isDefault]) {
            $currency = new Currency($code, $name, $symbol, $decimals);
            if ($isDefault) {
                $currency->markDefault(true);
            }
            $this->em->persist($currency);
        }

        try {
            $this->em->flush();
        } catch (\Throwable) {
            // Ignore when schema is not ready yet.
        }
    }
}
