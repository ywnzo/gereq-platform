<?php

declare(strict_types=1);

namespace Modules\Loyalty;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Loyalty\Application\LoyaltyProvider;
use Modules\Loyalty\Http\AccountController;
use Modules\Loyalty\Http\Api\LoyaltyApiController;
use Modules\Loyalty\Http\TierController;
use Modules\Loyalty\Application\Widgets\LoyaltyShortcutWidget;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class LoyaltyModule implements ModuleInterface
{
    public function id(): string
    {
        return 'loyalty';
    }

    public function version(): string
    {
        return '1.0.1';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('loyalty.view', 'perm.loyalty.view', 'perm.group.loyalty');
        $context->permissions()->register('loyalty.manage', 'perm.loyalty.manage', 'perm.group.loyalty');

        $context->capabilities()->register(
            'loyalty',
            $this->id(),
            new LoyaltyProvider($context->get(EntityManagerInterface::class)),
        );

        $context->router()->group('/admin/loyalty/tiers', function (Router $routes): void {
            $routes->get('', [TierController::class, 'index'])
                ->name('admin.loyalty.tiers.index')
                ->permission('loyalty.view');
            $routes->get('/create', [TierController::class, 'create'])
                ->name('admin.loyalty.tiers.create')
                ->permission('loyalty.manage');
            $routes->post('', [TierController::class, 'store'])
                ->name('admin.loyalty.tiers.store')
                ->permission('loyalty.manage');
            $routes->get('/{id}/edit', [TierController::class, 'edit'])
                ->name('admin.loyalty.tiers.edit')
                ->permission('loyalty.manage');
            $routes->post('/{id}', [TierController::class, 'update'])
                ->name('admin.loyalty.tiers.update')
                ->permission('loyalty.manage');
            $routes->post('/{id}/delete', [TierController::class, 'destroy'])
                ->name('admin.loyalty.tiers.destroy')
                ->permission('loyalty.manage');
        }, ['middleware' => ['auth']]);

        $context->router()->group('/admin/loyalty/accounts', function (Router $routes): void {
            $routes->get('', [AccountController::class, 'index'])
                ->name('admin.loyalty.accounts.index')
                ->permission('loyalty.view');
            $routes->post('/{id}/adjust', [AccountController::class, 'adjust'])
                ->name('admin.loyalty.accounts.adjust')
                ->permission('loyalty.manage');
        }, ['middleware' => ['auth']]);

        $this->registerApi($context);
        $this->registerPlatformEvents($context);
        $this->registerValidation($context);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new LoyaltyShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->menu()->add(new MenuItem(
            id: 'loyalty',
            label: 'nav.loyalty',
            icon: 'gift',
            route: 'admin.loyalty.tiers.index',
            permission: 'loyalty.view',
            order: 37,
            group: 'Obchod',
        ));
        $context->menu()->add(new MenuItem(
            id: 'loyalty.accounts',
            label: 'nav.loyalty.accounts',
            route: 'admin.loyalty.accounts.index',
            permission: 'loyalty.view',
            parent: 'loyalty',
            order: 38,
        ));
        $context->menu()->add(new MenuItem(
            id: 'loyalty.tiers.create',
            label: 'nav.loyalty.tiers.create',
            route: 'admin.loyalty.tiers.create',
            permission: 'loyalty.manage',
            parent: 'loyalty',
            order: 39,
            hidden: true,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'loyalty.tiers',
            label: 'palette.loyalty.tiers',
            url: '/admin/loyalty/tiers',
            icon: 'gift',
            permission: 'loyalty.view',
            keywords: ['věrnost', 'loyalty', 'tier', 'body', 'points'],
        ));
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->registerKnownScope('loyalty.read');
        $context->api()->registerKnownScope('loyalty.write');

        $context->api()->module('loyalty', function (ApiModule $api): void {
            $api->get('/tiers', [LoyaltyApiController::class, 'tiers'])->scope('loyalty.read');
            $api->get('/accounts/{customerId}', [LoyaltyApiController::class, 'account'])->scope('loyalty.read');
            $api->post('/accounts/adjust', [LoyaltyApiController::class, 'adjust'])->scope('loyalty.write');
        });
    }

    private function registerPlatformEvents(ModuleContext $context): void
    {
        foreach ([
            'loyalty.tier_created' => 'Věrnostní úroveň vytvořena',
            'loyalty.tier_updated' => 'Věrnostní úroveň upravena',
            'loyalty.tier_deleted' => 'Věrnostní úroveň smazána',
            'loyalty.points_adjusted' => 'Body věrnosti upraveny',
        ] as $type => $description) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))
                    ->description($description)
                    ->webhookEnabled(true)
            );
        }
    }

    private function registerValidation(ModuleContext $context): void
    {
        $context->validation()->ruleset('loyalty.tier.save', [
            'code' => 'required|min:1|max:64',
            'name' => 'required|min:2|max:190',
            'min_spend' => 'nullable',
            'discount_percent' => 'nullable',
            'sort_order' => 'nullable',
            'active' => 'nullable',
        ]);
        $context->validation()->ruleset('loyalty.account.adjust', [
            'customer_id' => 'nullable|max:36',
            'account_id' => 'nullable|max:36',
            'delta' => 'required',
            'note' => 'nullable|max:2000',
        ]);
    }
}
