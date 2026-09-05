<?php

declare(strict_types=1);

namespace Modules\Currency;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Currency\Domain\Currency;
use Modules\Currency\Http\ConvertApiController;
use Modules\Currency\Http\CurrencyAdminController;
use Modules\Currency\Http\CurrencyApiController;
use Modules\Currency\Http\ExchangeRateApiController;

final class CurrencyModule implements ModuleInterface
{
    public function id(): string
    {
        return 'currency';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('currency.view', 'perm.currency.view', 'perm.group.currency');
        $context->permissions()->register('currency.manage', 'perm.currency.manage', 'perm.group.currency');

        $context->router()->group('/admin/currencies', function (Router $routes): void {
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

        $this->registerApi($context);
        $this->registerPlatformEvents($context);
        $this->registerValidation($context);
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'currencies',
            label: 'nav.currencies',
            icon: 'coins',
            route: 'admin.currencies.index',
            permission: 'currency.view',
            order: 40,
            group: 'Hlavní',
        ));
        $context->menu()->add(new MenuItem(
            id: 'currencies.create',
            label: 'nav.currencies.create',
            route: 'admin.currencies.create',
            permission: 'currency.manage',
            parent: 'currencies',
            order: 41,
            hidden: true,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'currencies.index',
            label: 'palette.currency.index',
            url: '/admin/currencies',
            icon: 'coins',
            permission: 'currency.view',
            keywords: ['currency', 'kurz', 'měna', 'czk'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'currencies.create',
            label: 'palette.currency.create',
            url: '/admin/currencies/create',
            icon: 'plus',
            permission: 'currency.manage',
            group: 'Akce',
        ));

        $this->seedDefaults($context);
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->registerKnownScope('currency.read');
        $context->api()->registerKnownScope('currency.write');

        $context->api()->module('currencies', function (ApiModule $api): void {
            $api->get('/', [CurrencyApiController::class, 'index'])->scope('currency.read');
            $api->post('/', [CurrencyApiController::class, 'store'])->scope('currency.write');
            $api->get('/{code}', [CurrencyApiController::class, 'show'])->scope('currency.read');
            $api->patch('/{code}', [CurrencyApiController::class, 'update'])->scope('currency.write');
        });

        $context->api()->module('exchange-rates', function (ApiModule $api): void {
            $api->get('/', [ExchangeRateApiController::class, 'index'])->scope('currency.read');
            $api->post('/', [ExchangeRateApiController::class, 'store'])->scope('currency.write');
        });

        $context->api()->module('currency', function (ApiModule $api): void {
            $api->post('/convert', [ConvertApiController::class, 'convert'])->scope('currency.read');
        });
    }

    private function registerPlatformEvents(ModuleContext $context): void
    {
        $events = [
            'currency.created' => 'Měna vytvořena',
            'currency.updated' => 'Měna upravena',
            'currency.activated' => 'Měna aktivována',
            'currency.deactivated' => 'Měna deaktivována',
            'currency.default_changed' => 'Změněna výchozí měna',
            'exchange_rate.created' => 'Směnný kurz vytvořen',
            'exchange_rate.updated' => 'Směnný kurz upraven',
        ];

        foreach ($events as $type => $description) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))
                    ->description($description)
                    ->webhookEnabled(true)
            );
        }
    }

    private function registerValidation(ModuleContext $context): void
    {
        $validation = $context->validation();

        $validation->ruleset('currency.save', [
            'code' => 'required|min:3|max:3',
            'name' => 'required|min:2|max:120',
            'symbol' => 'required|min:1|max:16',
            'decimal_places' => 'nullable',
            'is_active' => 'nullable',
            'is_default' => 'nullable',
        ]);

        $validation->ruleset('currency.api.save', [
            'code' => 'required|min:3|max:3',
            'name' => 'required|min:2|max:120',
            'symbol' => 'nullable|max:16',
            'decimal_places' => 'nullable',
            'is_active' => 'nullable',
            'is_default' => 'nullable',
        ]);

        $validation->ruleset('currency.api.update', [
            'name' => 'nullable|min:2|max:120',
            'symbol' => 'nullable|max:16',
            'decimal_places' => 'nullable',
            'is_active' => 'nullable',
            'is_default' => 'nullable',
        ]);

        $validation->ruleset('currency.exchange_rate.save', [
            'base_code' => 'required|min:3|max:3',
            'quote_code' => 'required|min:3|max:3',
            'rate' => 'required',
            'provider' => 'nullable|max:64',
            'valid_at' => 'nullable',
            'id' => 'nullable|max:36',
        ]);

        $validation->ruleset('currency.convert', [
            'amount' => 'required',
            'currency' => 'required|min:3|max:3',
            'target_currency' => 'required|min:3|max:3',
        ]);
    }

    private function seedDefaults(ModuleContext $context): void
    {
        $em = $context->get(EntityManagerInterface::class);
        $count = (int) $em->getRepository(Currency::class)->count([]);
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
            $em->persist($currency);
        }

        $em->flush();
    }
}
