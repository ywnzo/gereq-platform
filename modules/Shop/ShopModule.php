<?php

declare(strict_types=1);

namespace Modules\Shop;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Modules\Shop\Application\Carriers\ManualCarrier;
use Modules\Shop\Application\Carriers\PacketaCarrier;
use Modules\Shop\Application\Carriers\PplCarrier;
use Modules\Shop\Application\ShippingCarrierRegistry;
use Modules\Shop\Application\ShopProvider;
use Modules\Shop\Http\Api\CartApiController;
use Modules\Shop\Http\Api\CheckoutApiController;
use Modules\Shop\Http\Api\PacketaApiController;
use Modules\Shop\Http\Api\ShippingMethodApiController;
use Modules\Shop\Http\CartController;
use Modules\Shop\Http\Public\PublicStoreCartController;
use Modules\Shop\Http\Public\PublicStoreShippingController;
use Modules\Shop\Http\ShippingMethodController;
use Modules\Shop\Application\Widgets\ShopShortcutWidget;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ShopModule implements ModuleInterface
{
    public function id(): string
    {
        return 'shop';
    }

    public function version(): string
    {
        return '1.2.1';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('shop.view', 'perm.shop.view', 'perm.group.shop');
        $context->permissions()->register('shop.manage', 'perm.shop.manage', 'perm.group.shop');

        $context->capabilities()->register('shop', $this->id(), new ShopProvider($context->get(EntityManagerInterface::class)));

        $carriers = $context->get(ShippingCarrierRegistry::class);
        $carriers->register($context->get(ManualCarrier::class));
        $packeta = $context->get(PacketaCarrier::class);
        $carriers->register($packeta);
        $carriers->registerAlias('zasilkovna', $packeta);
        $carriers->register($context->get(PplCarrier::class));
        $context->capabilities()->register('shipping_carriers', $this->id(), $carriers);

        $context->settings()->define('shop.store_name', SettingRecord::TYPE_STRING, 'shop.settings.store_name', 'GEREQ Shop', $this->id());
        $context->settings()->define('shop.default_currency', SettingRecord::TYPE_STRING, 'shop.settings.default_currency', 'CZK', $this->id());
        $context->settings()->define('shop.checkout_enabled', SettingRecord::TYPE_BOOLEAN, 'shop.settings.checkout_enabled', true, $this->id());
        $context->settings()->define('shop.packeta_api_password', SettingRecord::TYPE_STRING, 'shop.settings.packeta_api_password', '', $this->id());
        $context->settings()->define('shop.packeta_api_key', SettingRecord::TYPE_STRING, 'shop.settings.packeta_api_key', '', $this->id());
        $context->settings()->define('shop.packeta_eshop', SettingRecord::TYPE_STRING, 'shop.settings.packeta_eshop', '', $this->id());
        $context->settings()->define('shop.packeta_address_carrier_id', SettingRecord::TYPE_STRING, 'shop.settings.packeta_address_carrier_id', '106', $this->id());
        $context->settings()->define('shop.ppl_api_token', SettingRecord::TYPE_STRING, 'shop.settings.ppl_api_token', '', $this->id());

        $context->router()->get('/api/v1/shipping/packeta/widget', [PacketaApiController::class, 'widget'])
            ->name('api.shipping.packeta.widget');

        $context->router()->group('/admin/shop/carts', function (Router $routes): void {
            $routes->get('', [CartController::class, 'index'])->name('admin.shop.carts.index')->permission('shop.view');
            $routes->get('/{id}', [CartController::class, 'show'])->name('admin.shop.carts.show')->permission('shop.view');
            $routes->post('/{id}/abandon', [CartController::class, 'abandon'])->name('admin.shop.carts.abandon')->permission('shop.manage');
        }, ['middleware' => ['auth']]);

        $context->router()->group('/admin/shop/shipping-methods', function (Router $routes): void {
            $routes->get('', [ShippingMethodController::class, 'index'])->name('admin.shop.shipping.index')->permission('shop.view');
            $routes->get('/create', [ShippingMethodController::class, 'create'])->name('admin.shop.shipping.create')->permission('shop.manage');
            $routes->post('', [ShippingMethodController::class, 'store'])->name('admin.shop.shipping.store')->permission('shop.manage');
            $routes->get('/{id}/edit', [ShippingMethodController::class, 'edit'])->name('admin.shop.shipping.edit')->permission('shop.manage');
            $routes->post('/{id}', [ShippingMethodController::class, 'update'])->name('admin.shop.shipping.update')->permission('shop.manage');
            $routes->post('/{id}/delete', [ShippingMethodController::class, 'destroy'])->name('admin.shop.shipping.destroy')->permission('shop.manage');
        }, ['middleware' => ['auth']]);

        $this->registerApi($context);
        $this->registerPlatformEvents($context);
        $this->registerValidation($context);

        $public = [PublicStoreCartController::class, 'options'];
        $context->router()->add('/api/public/store/cart', ['OPTIONS'], $public)->name('api.public.store.cart.options');
        $context->router()->add('/api/public/store/cart/items', ['OPTIONS'], $public)->name('api.public.store.cart.items.options');
        $context->router()->add('/api/public/store/cart/items/{id}', ['OPTIONS'], $public)->name('api.public.store.cart.items.id.options');
        $context->router()->add('/api/public/store/checkout', ['OPTIONS'], $public)->name('api.public.store.checkout.options');
        $context->router()->add('/api/public/store/shipping-methods', ['OPTIONS'], [PublicStoreShippingController::class, 'options'])->name('api.public.store.shipping.options');
        $context->router()->get('/api/public/store/cart', [PublicStoreCartController::class, 'show'])->name('api.public.store.cart');
        $context->router()->post('/api/public/store/cart', [PublicStoreCartController::class, 'store'])->name('api.public.store.cart.store');
        $context->router()->post('/api/public/store/cart/items', [PublicStoreCartController::class, 'addItem'])->name('api.public.store.cart.items');
        $context->router()->patch('/api/public/store/cart/items/{id}', [PublicStoreCartController::class, 'updateItem'])->name('api.public.store.cart.items.update');
        $context->router()->delete('/api/public/store/cart/items/{id}', [PublicStoreCartController::class, 'removeItem'])->name('api.public.store.cart.items.delete');
        $context->router()->post('/api/public/store/checkout', [PublicStoreCartController::class, 'checkout'])->name('api.public.store.checkout');
        $context->router()->get('/api/public/store/shipping-methods', [PublicStoreShippingController::class, 'index'])->name('api.public.store.shipping');
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new ShopShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->menu()->add(new MenuItem(
            id: 'shop',
            label: 'nav.shop',
            icon: 'bag',
            route: 'admin.shop.carts.index',
            permission: 'shop.view',
            order: 28,
            group: 'Obchod',
        ));
        $context->menu()->add(new MenuItem(
            id: 'shop.carts',
            label: 'nav.shop.carts',
            route: 'admin.shop.carts.index',
            permission: 'shop.view',
            parent: 'shop',
            order: 29,
        ));
        $context->menu()->add(new MenuItem(
            id: 'shop.shipping',
            label: 'nav.shop.shipping',
            route: 'admin.shop.shipping.index',
            permission: 'shop.view',
            parent: 'shop',
            order: 30,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'shop.carts',
            label: 'palette.shop.carts',
            url: '/admin/shop/carts',
            icon: 'cart',
            permission: 'shop.view',
            keywords: ['košík', 'cart', 'shop', 'obchod'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'shop.shipping',
            label: 'palette.shop.shipping',
            url: '/admin/shop/shipping-methods',
            icon: 'truck',
            permission: 'shop.view',
            keywords: ['doprava', 'shipping', 'doručení'],
        ));
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->registerKnownScope('shop.read');
        $context->api()->registerKnownScope('shop.write');

        $context->api()->module('shop', function (ApiModule $api): void {
            $api->get('/cart', [CartApiController::class, 'show'])->scope('shop.read');
            $api->post('/cart', [CartApiController::class, 'store'])->scope('shop.write');
            $api->post('/cart/items', [CartApiController::class, 'addItem'])->scope('shop.write');
            $api->patch('/cart/items/{id}', [CartApiController::class, 'updateItem'])->scope('shop.write');
            $api->delete('/cart/items/{id}', [CartApiController::class, 'removeItem'])->scope('shop.write');

            $api->get('/shipping-methods', [ShippingMethodApiController::class, 'index'])->scope('shop.read');
            $api->post('/shipping-methods', [ShippingMethodApiController::class, 'store'])->scope('shop.write');
            $api->get('/shipping-methods/{id}', [ShippingMethodApiController::class, 'show'])->scope('shop.read');
            $api->patch('/shipping-methods/{id}', [ShippingMethodApiController::class, 'update'])->scope('shop.write');
            $api->delete('/shipping-methods/{id}', [ShippingMethodApiController::class, 'destroy'])->scope('shop.write');

            $api->post('/checkout', [CheckoutApiController::class, 'store'])->scope('shop.write');
        });
    }

    private function registerPlatformEvents(ModuleContext $context): void
    {
        foreach ([
            'cart.updated' => 'Košík aktualizován',
            'cart.converted' => 'Košík převeden',
            'checkout.completed' => 'Checkout dokončen',
            'shipping.method_created' => 'Způsob dopravy vytvořen',
            'shipping.method_updated' => 'Způsob dopravy upraven',
            'shipping.method_deleted' => 'Způsob dopravy smazán',
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
        $v = $context->validation();
        $v->ruleset('shop.shipping.save', [
            'code' => 'required|min:1|max:64',
            'name' => 'required|min:2|max:190',
            'price' => 'required',
            'currency' => 'nullable|max:3',
            'carrier' => 'nullable|max:64',
            'type' => 'nullable|max:32',
            'description' => 'nullable|max:5000',
            'sort_order' => 'nullable',
            'active' => 'nullable',
            'estimated_days' => 'nullable',
        ]);
        $v->ruleset('shop.cart.item.add', [
            'product_id' => 'required|max:36',
            'qty' => 'nullable',
            'cart_id' => 'nullable|max:36',
            'session_key' => 'nullable|max:64',
            'customer_id' => 'nullable|max:36',
        ]);
        $v->ruleset('shop.cart.item.update', [
            'qty' => 'required',
        ]);
        $v->ruleset('shop.checkout', [
            'cart_id' => 'required|max:36',
            'shipping_method' => 'nullable|max:64',
            'payment_method' => 'nullable|max:64',
            'coupon_code' => 'nullable|max:64',
            'customer_id' => 'nullable|max:36',
            'customer_email' => 'nullable|email|max:190',
            'customer_name' => 'nullable|max:180',
            'note' => 'nullable|max:20000',
            'warehouse_id' => 'nullable|max:36',
            'parcel_point_id' => 'nullable|max:64',
            'parcel_point_label' => 'nullable|max:190',
            'billing_address' => 'nullable',
            'shipping_address' => 'nullable',
        ]);
    }
}
