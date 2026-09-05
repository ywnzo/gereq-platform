<?php

declare(strict_types=1);

namespace Modules\ProductDiscounts;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\ProductDiscounts\Application\ProductDiscountsProvider;
use Modules\ProductDiscounts\Application\Widgets\ProductDiscountsShortcutWidget;
use Modules\ProductDiscounts\Http\Api\ProductDiscountApiController;
use Modules\ProductDiscounts\Http\ProductDiscountController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ProductDiscountsModule implements ModuleInterface
{
    public function id(): string
    {
        return 'product_discounts';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('product_discounts.view', 'perm.product_discounts.view', 'perm.group.product_discounts');
        $context->permissions()->register('product_discounts.manage', 'perm.product_discounts.manage', 'perm.group.product_discounts');

        $context->capabilities()->register(
            'product_discounts',
            $this->id(),
            new ProductDiscountsProvider($context->get(EntityManagerInterface::class)),
        );

        $context->router()->group('/admin/product-discounts', function (Router $routes): void {
            $routes->get('', [ProductDiscountController::class, 'index'])
                ->name('admin.product_discounts.index')
                ->permission('product_discounts.view');
            $routes->get('/create', [ProductDiscountController::class, 'create'])
                ->name('admin.product_discounts.create')
                ->permission('product_discounts.manage');
            $routes->post('', [ProductDiscountController::class, 'store'])
                ->name('admin.product_discounts.store')
                ->permission('product_discounts.manage');
            $routes->get('/{id}/edit', [ProductDiscountController::class, 'edit'])
                ->name('admin.product_discounts.edit')
                ->permission('product_discounts.manage');
            $routes->post('/{id}', [ProductDiscountController::class, 'update'])
                ->name('admin.product_discounts.update')
                ->permission('product_discounts.manage');
            $routes->post('/{id}/delete', [ProductDiscountController::class, 'destroy'])
                ->name('admin.product_discounts.destroy')
                ->permission('product_discounts.manage');
        }, ['middleware' => ['auth']]);

        $context->router()->group('/admin/products/{productId}/discount', function (Router $routes): void {
            $routes->post('', [ProductDiscountController::class, 'upsertForProduct'])
                ->name('admin.products.discount.upsert')
                ->permission('product_discounts.manage');
            $routes->post('/delete', [ProductDiscountController::class, 'destroyForProduct'])
                ->name('admin.products.discount.destroy')
                ->permission('product_discounts.manage');
        }, ['middleware' => ['auth']]);

        $this->registerApi($context);
        $this->registerPlatformEvents($context);
        $this->registerValidation($context);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new ProductDiscountsShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->menu()->add(new MenuItem(
            id: 'product_discounts',
            label: 'nav.product_discounts',
            icon: 'tag',
            route: 'admin.product_discounts.index',
            permission: 'product_discounts.view',
            order: 33,
            group: 'Obchod',
        ));
        $context->menu()->add(new MenuItem(
            id: 'product_discounts.create',
            label: 'nav.product_discounts.create',
            route: 'admin.product_discounts.create',
            permission: 'product_discounts.manage',
            parent: 'product_discounts',
            order: 34,
            hidden: true,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'product_discounts.index',
            label: 'palette.product_discounts.index',
            url: '/admin/product-discounts',
            icon: 'tag',
            permission: 'product_discounts.view',
            keywords: ['sleva', 'discount', 'akce', 'sale', 'produkt'],
        ));
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->registerKnownScope('product_discounts.read');
        $context->api()->registerKnownScope('product_discounts.write');

        $context->api()->module('product-discounts', function (ApiModule $api): void {
            $api->get('/', [ProductDiscountApiController::class, 'index'])->scope('product_discounts.read');
            $api->get('/product/{productId}', [ProductDiscountApiController::class, 'showForProduct'])->scope('product_discounts.read');
            $api->put('/product/{productId}', [ProductDiscountApiController::class, 'upsertForProduct'])->scope('product_discounts.write');
            $api->delete('/product/{productId}', [ProductDiscountApiController::class, 'destroyForProduct'])->scope('product_discounts.write');
        });
    }

    private function registerPlatformEvents(ModuleContext $context): void
    {
        foreach ([
            'product_discount.saved' => 'Produktová sleva uložena',
            'product_discount.deleted' => 'Produktová sleva smazána',
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
        $context->validation()->ruleset('product_discounts.save', [
            'product_id' => 'nullable|max:36',
            'kind' => 'required|max:16',
            'value' => 'required',
            'description' => 'nullable|max:190',
            'starts_at' => 'nullable',
            'ends_at' => 'nullable',
            'active' => 'nullable',
        ]);
    }
}
