<?php

declare(strict_types=1);

namespace Modules\Products;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Products\Application\ProductStatsWidget;
use Modules\Products\Application\ProductsProvider;
use Modules\Products\Http\Api\ProductApiController;
use Modules\Products\Http\Api\VariantApiController;
use Modules\Products\Http\CategoryController;
use Modules\Products\Http\ProductController;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ProductsModule implements ModuleInterface
{
    public function id(): string
    {
        return 'products';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('products.view', 'perm.products.view', 'perm.group.products');
        $context->permissions()->register('products.manage', 'perm.products.manage', 'perm.group.products');

        $provider = new ProductsProvider($context->get(EntityManagerInterface::class));
        $context->capabilities()->register('products', $this->id(), $provider);

        $context->router()->group('/admin/products', function (Router $routes): void {
            $routes->get('', [ProductController::class, 'index'])
                ->name('admin.products.index')
                ->permission('products.view');
            $routes->get('/create', [ProductController::class, 'create'])
                ->name('admin.products.create')
                ->permission('products.manage');
            $routes->post('', [ProductController::class, 'store'])
                ->name('admin.products.store')
                ->permission('products.manage');
            $routes->get('/{id}', [ProductController::class, 'show'])
                ->name('admin.products.show')
                ->permission('products.view');
            $routes->get('/{id}/edit', [ProductController::class, 'edit'])
                ->name('admin.products.edit')
                ->permission('products.manage');
            $routes->post('/{id}', [ProductController::class, 'update'])
                ->name('admin.products.update')
                ->permission('products.manage');
            $routes->post('/{id}/status', [ProductController::class, 'setStatus'])
                ->name('admin.products.status')
                ->permission('products.manage');
            $routes->post('/{id}/prices', [ProductController::class, 'storePrice'])
                ->name('admin.products.prices.store')
                ->permission('products.manage');
            $routes->post('/{id}/prices/{priceId}/delete', [ProductController::class, 'destroyPrice'])
                ->name('admin.products.prices.destroy')
                ->permission('products.manage');
            $routes->post('/{id}/variants', [ProductController::class, 'storeVariant'])
                ->name('admin.products.variants.store')
                ->permission('products.manage');
            $routes->post('/{id}/variants/{variantId}/delete', [ProductController::class, 'destroyVariant'])
                ->name('admin.products.variants.destroy')
                ->permission('products.manage');
            $routes->post('/{id}/images', [ProductController::class, 'storeImage'])
                ->name('admin.products.images.store')
                ->permission('products.manage');
            $routes->post('/{id}/images/attach', [ProductController::class, 'attachImage'])
                ->name('admin.products.images.attach')
                ->permission('products.manage');
            $routes->post('/{id}/images/{imageId}/primary', [ProductController::class, 'setPrimaryImage'])
                ->name('admin.products.images.primary')
                ->permission('products.manage');
            $routes->post('/{id}/images/{imageId}/delete', [ProductController::class, 'destroyImage'])
                ->name('admin.products.images.destroy')
                ->permission('products.manage');
            $routes->post('/{id}/delete', [ProductController::class, 'destroy'])
                ->name('admin.products.destroy')
                ->permission('products.manage');
        }, ['middleware' => ['auth']]);

        $context->router()->group('/admin/product-categories', function (Router $routes): void {
            $routes->get('', [CategoryController::class, 'index'])
                ->name('admin.products.categories.index')
                ->permission('products.view');
            $routes->get('/create', [CategoryController::class, 'create'])
                ->name('admin.products.categories.create')
                ->permission('products.manage');
            $routes->post('', [CategoryController::class, 'store'])
                ->name('admin.products.categories.store')
                ->permission('products.manage');
            $routes->get('/{id}/edit', [CategoryController::class, 'edit'])
                ->name('admin.products.categories.edit')
                ->permission('products.manage');
            $routes->post('/{id}', [CategoryController::class, 'update'])
                ->name('admin.products.categories.update')
                ->permission('products.manage');
            $routes->post('/{id}/delete', [CategoryController::class, 'destroy'])
                ->name('admin.products.categories.destroy')
                ->permission('products.manage');
        }, ['middleware' => ['auth']]);

        $this->registerApi($context);
        $this->registerPlatformEvents($context);
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'products',
            label: 'nav.products',
            icon: 'package',
            route: 'admin.products.index',
            permission: 'products.view',
            order: 30,
            group: 'Obchod',
        ));
        $context->menu()->add(new MenuItem(
            id: 'products.list',
            label: 'nav.products.list',
            route: 'admin.products.index',
            permission: 'products.view',
            parent: 'products',
            order: 31,
        ));
        $context->menu()->add(new MenuItem(
            id: 'products.categories',
            label: 'nav.products.categories',
            route: 'admin.products.categories.index',
            permission: 'products.view',
            parent: 'products',
            order: 32,
        ));
        $context->menu()->add(new MenuItem(
            id: 'products.create',
            label: 'nav.products.create',
            route: 'admin.products.create',
            permission: 'products.manage',
            parent: 'products',
            order: 33,
            hidden: true,
        ));

        $context->dashboard()->addWidget(new ProductStatsWidget(
            $context->get(EntityManagerInterface::class),
            $context->get(TranslatorInterface::class),
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'products.index',
            label: 'palette.products.index',
            url: '/admin/products',
            icon: 'package',
            permission: 'products.view',
            keywords: ['produkty', 'katalog', 'sku', 'products'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'products.categories',
            label: 'palette.products.categories',
            url: '/admin/product-categories',
            icon: 'package',
            permission: 'products.view',
            keywords: ['kategorie', 'categories'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'products.create',
            label: 'palette.products.create',
            url: '/admin/products/create',
            icon: 'plus',
            permission: 'products.manage',
            group: 'Akce',
        ));

        $this->registerValidation($context);
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->registerKnownScope('products.read');
        $context->api()->registerKnownScope('products.write');

        $context->api()->module('products', function (ApiModule $api): void {
            $api->get('/', [ProductApiController::class, 'index'])->scope('products.read');
            $api->post('/', [ProductApiController::class, 'store'])->scope('products.write');
            $api->get('/categories', [ProductApiController::class, 'categoriesIndex'])->scope('products.read');
            $api->get('/{id}', [ProductApiController::class, 'show'])->scope('products.read');
            $api->patch('/{id}', [ProductApiController::class, 'update'])->scope('products.write');
            $api->delete('/{id}', [ProductApiController::class, 'destroy'])->scope('products.write');
            $api->get('/{productId}/variants', [VariantApiController::class, 'index'])->scope('products.read');
            $api->post('/{productId}/variants', [VariantApiController::class, 'storeVariant'])->scope('products.write');
            $api->post('/{productId}/option-groups', [VariantApiController::class, 'storeOptionGroup'])->scope('products.write');
        });
    }

    private function registerPlatformEvents(ModuleContext $context): void
    {
        $events = [
            'product.created' => 'Produkt vytvořen',
            'product.updated' => 'Produkt upraven',
            'product.activated' => 'Produkt aktivován',
            'product.deactivated' => 'Produkt deaktivován',
            'product.price_changed' => 'Změna ceny produktu',
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

        $validation->ruleset('products.product.save', [
            'sku' => 'required|min:1|max:64',
            'name' => 'required|min:2|max:190',
            'description' => 'nullable|max:20000',
            'status' => 'required|in:draft,active,archived',
            'unit' => 'required|min:1|max:32',
            'category_ids' => 'nullable',
        ]);

        $validation->ruleset('products.category.save', [
            'name' => 'required|min:2|max:120',
            'slug' => 'nullable|max:120',
        ]);

        $validation->ruleset('products.price.save', [
            'currency_code' => 'required|min:3|max:3',
            'amount' => 'required|max:40',
            'valid_from' => 'nullable',
            'valid_to' => 'nullable',
        ]);

        $validation->ruleset('products.image.upload', [
            'alt' => 'nullable|max:255',
            'title' => 'nullable|max:190',
        ]);

        $validation->ruleset('products.image.attach', [
            'media_asset_id' => 'required|min:36|max:36',
            'alt' => 'nullable|max:255',
        ]);

        $validation->ruleset('products.variant.save', [
            'sku' => 'required|min:1|max:64',
            'name' => 'nullable|max:190',
            'attributes' => 'nullable',
            'price_amount' => 'nullable',
            'price_currency' => 'nullable|max:3',
            'stock_qty' => 'nullable',
            'ean' => 'nullable|max:64',
            'active' => 'nullable',
        ]);

        $validation->ruleset('products.option_group.save', [
            'code' => 'required|min:1|max:64',
            'name' => 'required|min:1|max:190',
            'sort_order' => 'nullable',
        ]);
    }
}
