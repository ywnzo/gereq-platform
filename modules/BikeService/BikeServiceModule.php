<?php

declare(strict_types=1);

namespace Modules\BikeService;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventDefinition;
use Gereq\Hook\HookPriority;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Modules\BikeService\Application\BikeServiceProvider;
use Modules\BikeService\Application\PdfTemplateCatalog;
use Modules\BikeService\Application\Widgets\BikeServiceShortcutWidget;
use Modules\BikeService\Http\Api\BikeServiceApiController;
use Modules\BikeService\Http\BikeController;
use Modules\BikeService\Http\BikeOrderController;
use Modules\BikeService\Http\CatalogController;
use Modules\BikeService\Http\ExploreController;
use Modules\BikeService\Http\SettingsController;
use Modules\Customers\Domain\Customer;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class BikeServiceModule implements ModuleInterface
{
    public function id(): string
    {
        return 'bikeservice';
    }

    public function version(): string
    {
        return '1.11.0';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('bikeservice.view', 'perm.bikeservice.view', 'perm.group.bikeservice');
        $context->permissions()->register('bikeservice.manage', 'perm.bikeservice.manage', 'perm.group.bikeservice');

        $provider = new BikeServiceProvider(
            $context->get(EntityManagerInterface::class),
            $context->settings(),
        );
        $context->capabilities()->register('bikeservice', $this->id(), $provider);

        $context->settings()->define('bikeservice.default_team', SettingRecord::TYPE_STRING, 'bikeservice.settings.default_team', 'mechanics', $this->id(), [], false);
        $context->settings()->define('bikeservice.media_phases', SettingRecord::TYPE_STRING, 'bikeservice.settings.media_phases', 'before,progress,after', $this->id(), [], false);
        $context->settings()->define('bikeservice.default_city', SettingRecord::TYPE_STRING, 'bikeservice.settings.default_city', 'Praha', $this->id(), [], false);
        $context->settings()->define('bikeservice.default_citybikes_network', SettingRecord::TYPE_STRING, 'bikeservice.settings.default_network', 'nextbike-praha', $this->id(), [], false);
        $context->settings()->define('bikeservice.default_lat', SettingRecord::TYPE_STRING, 'bikeservice.settings.default_lat', '50.087', $this->id(), [], false);
        $context->settings()->define('bikeservice.default_lon', SettingRecord::TYPE_STRING, 'bikeservice.settings.default_lon', '14.421', $this->id(), [], false);
        $context->router()->group('/admin/bikeservice', function (Router $routes): void {
            $routes->get('', [BikeOrderController::class, 'index'])->name('admin.bikeservice.orders')->permission('bikeservice.view');
            $routes->get('/orders', [BikeOrderController::class, 'index'])->name('admin.bikeservice.orders.index')->permission('bikeservice.view');
            $routes->get('/orders/create', [BikeOrderController::class, 'create'])->name('admin.bikeservice.orders.create')->permission('bikeservice.manage');
            $routes->post('/orders', [BikeOrderController::class, 'store'])->name('admin.bikeservice.orders.store')->permission('bikeservice.manage');
            $routes->post('/orders/bulk', [BikeOrderController::class, 'bulk'])->name('admin.bikeservice.orders.bulk')->permission('bikeservice.manage');
            $routes->get('/orders/{id}', [BikeOrderController::class, 'show'])->name('admin.bikeservice.orders.show')->permission('bikeservice.view');
            $routes->get('/orders/{id}/edit', [BikeOrderController::class, 'edit'])->name('admin.bikeservice.orders.edit')->permission('bikeservice.manage');
            $routes->post('/orders/{id}', [BikeOrderController::class, 'update'])->name('admin.bikeservice.orders.update')->permission('bikeservice.manage');
            $routes->post('/orders/{id}/status', [BikeOrderController::class, 'updateStatus'])->name('admin.bikeservice.orders.status')->permission('bikeservice.manage');
            $routes->post('/orders/{id}/team', [BikeOrderController::class, 'switchTeam'])->name('admin.bikeservice.orders.team')->permission('bikeservice.manage');
            $routes->post('/orders/{id}/jobs', [BikeOrderController::class, 'storeJob'])->name('admin.bikeservice.orders.jobs.store')->permission('bikeservice.manage');
            $routes->post('/orders/{id}/jobs/{jobId}/complete', [BikeOrderController::class, 'completeJob'])->name('admin.bikeservice.orders.jobs.complete')->permission('bikeservice.manage');
            $routes->post('/orders/{id}/finish', [BikeOrderController::class, 'finish'])->name('admin.bikeservice.orders.finish')->permission('bikeservice.manage');
            $routes->post('/orders/{id}/media', [BikeOrderController::class, 'uploadMedia'])->name('admin.bikeservice.orders.media')->permission('bikeservice.manage');
            $routes->post('/orders/{id}/documents', [BikeOrderController::class, 'generateDocument'])->name('admin.bikeservice.orders.documents.generate')->permission('bikeservice.manage');
            $routes->post('/orders/{id}/delete', [BikeOrderController::class, 'destroy'])->name('admin.bikeservice.orders.destroy')->permission('bikeservice.manage');

            $routes->get('/bikes', [BikeController::class, 'index'])->name('admin.bikeservice.bikes')->permission('bikeservice.view');
            $routes->get('/bikes/create', [BikeController::class, 'create'])->name('admin.bikeservice.bikes.create')->permission('bikeservice.manage');
            $routes->post('/bikes', [BikeController::class, 'store'])->name('admin.bikeservice.bikes.store')->permission('bikeservice.manage');
            $routes->post('/bikes/lookup-serial', [BikeController::class, 'lookupSerial'])->name('admin.bikeservice.bikes.lookup_serial')->permission('bikeservice.view');
            $routes->get('/bikes/{id}', [BikeController::class, 'show'])->name('admin.bikeservice.bikes.show')->permission('bikeservice.view');
            $routes->get('/bikes/{id}/edit', [BikeController::class, 'edit'])->name('admin.bikeservice.bikes.edit')->permission('bikeservice.manage');
            $routes->post('/bikes/{id}', [BikeController::class, 'update'])->name('admin.bikeservice.bikes.update')->permission('bikeservice.manage');
            $routes->post('/bikes/{id}/delete', [BikeController::class, 'destroy'])->name('admin.bikeservice.bikes.destroy')->permission('bikeservice.manage');

            $routes->get('/manufacturers', [CatalogController::class, 'manufacturers'])->name('admin.bikeservice.manufacturers')->permission('bikeservice.view');
            $routes->get('/manufacturers/{id}', [CatalogController::class, 'show'])->name('admin.bikeservice.manufacturers.show')->permission('bikeservice.view');
            $routes->get('/catalog', [CatalogController::class, 'index'])->name('admin.bikeservice.catalog')->permission('bikeservice.view');
            $routes->get('/catalog/search', [CatalogController::class, 'search'])->name('admin.bikeservice.catalog.search')->permission('bikeservice.view');
            $routes->get('/catalog/market-source', [CatalogController::class, 'marketSource'])->name('admin.bikeservice.catalog.market_source')->permission('bikeservice.view');
            $routes->get('/catalog/{id}', [CatalogController::class, 'show'])->name('admin.bikeservice.catalog.show')->permission('bikeservice.view');

            $routes->get('/explore', [ExploreController::class, 'index'])->name('admin.bikeservice.explore')->permission('bikeservice.view');
            $routes->get('/settings', [SettingsController::class, 'edit'])->name('admin.bikeservice.settings')->permission('bikeservice.view');
            $routes->post('/settings', [SettingsController::class, 'update'])->name('admin.bikeservice.settings.update')->permission('bikeservice.manage');
        }, ['middleware' => ['auth']]);

        $context->api()->registerKnownScope('bikeservice.read');
        $context->api()->registerKnownScope('bikeservice.write');
        $context->api()->module('bikeservice', function (ApiModule $api): void {
            $api->get('/bikes', [BikeServiceApiController::class, 'bikes'])->scope('bikeservice.read');
            $api->post('/bikes', [BikeServiceApiController::class, 'storeBike'])->scope('bikeservice.write');
            $api->get('/bikes/{id}', [BikeServiceApiController::class, 'showBike'])->scope('bikeservice.read');
            $api->get('/orders', [BikeServiceApiController::class, 'orders'])->scope('bikeservice.read');
            $api->post('/orders', [BikeServiceApiController::class, 'storeOrder'])->scope('bikeservice.write');
            $api->get('/orders/{id}', [BikeServiceApiController::class, 'showOrder'])->scope('bikeservice.read');
            $api->post('/orders/{id}/finish', [BikeServiceApiController::class, 'finishOrder'])->scope('bikeservice.write');
            $api->post('/orders/{id}/jobs', [BikeServiceApiController::class, 'addJob'])->scope('bikeservice.write');
            $api->post('/orders/{id}/jobs/{jobId}/complete', [BikeServiceApiController::class, 'completeJob'])->scope('bikeservice.write');
            $api->get('/public/bikeindex', [BikeServiceApiController::class, 'bikeIndexSearch'])->scope('bikeservice.read');
            $api->get('/public/citybikes/{id}', [BikeServiceApiController::class, 'cityBikesNetwork'])->scope('bikeservice.read');
            $api->get('/public/osm-nearby', [BikeServiceApiController::class, 'osmNearby'])->scope('bikeservice.read');
        });

        foreach ([
            'bikeservice.bike_created' => 'Kolo vytvořeno',
            'bikeservice.bike_updated' => 'Kolo upraveno',
            'bikeservice.order_created' => 'Servisní zakázka kola vytvořena',
            'bikeservice.order_updated' => 'Servisní zakázka kola upravena',
            'bikeservice.team_switched' => 'Přepnut tým zakázky',
            'bikeservice.order_finished' => 'Servisní zakázka kola dokončena',
            'bikeservice.job_started' => 'Zahájena práce na zakázce',
            'bikeservice.job_finished' => 'Ukončena práce na zakázce',
        ] as $type => $desc) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))->description($desc)->webhookEnabled(true),
            );
        }

        $v = $context->validation();
        $v->ruleset('bikeservice.bike', [
            'brand' => 'required|max:120',
            'model' => 'nullable|max:120',
            'kind' => 'nullable|in:bike,ebike,cargo,scooter',
            'status' => 'nullable|in:active,in_service,sold,archived',
            'serial_number' => 'nullable|max:120',
            'color' => 'nullable|max:64',
            'frame_size' => 'nullable|max:32',
            'battery_wh' => 'nullable|integer',
            'motor_w' => 'nullable|integer',
            'odometer_km' => 'nullable|integer',
            'customer_id' => 'nullable|max:36',
            'bike_index_id' => 'nullable|integer',
            'note' => 'nullable|max:5000',
        ]);
        $v->ruleset('bikeservice.save', [
            'bike_id' => 'required|max:36',
            'customer_id' => 'nullable|max:36',
            'status' => 'nullable|in:intake,in_progress,waiting,ready,done,cancelled',
            'team' => 'nullable|in:mechanics,electric',
            'note' => 'nullable|max:5000',
            'service_info' => 'nullable|max:5000',
            'payment_type' => 'nullable|in:online,cash',
            'labor_cost' => 'nullable',
            'parts_cost' => 'nullable',
            'intake_odometer_km' => 'nullable|integer',
            'intake_battery_pct' => 'nullable|integer',
            'intake_at' => 'nullable',
            'handover_at' => 'nullable',
        ]);
        $v->ruleset('bikeservice.job', [
            'type' => 'nullable|max:64',
            'note' => 'nullable|max:2000',
            'employee_user_id' => 'nullable|max:36',
        ]);
        $v->ruleset('bikeservice.finish', [
            'payment_type' => 'nullable|in:online,cash',
            'labor_cost' => 'nullable',
            'parts_cost' => 'nullable',
            'service_info' => 'nullable|max:5000',
            'intake_odometer_km' => 'nullable|integer',
            'intake_battery_pct' => 'nullable|integer',
            'signature' => 'nullable|max:100',
            'sign_mode' => 'nullable|in:manual,digital',
            'signature_provider_png' => 'nullable|max:800000',
            'signature_customer_png' => 'nullable|max:800000',
            'esign_email' => 'nullable|email|max:190',
        ]);
        $v->ruleset('bikeservice.document', [
            'type' => 'required|in:' . implode(',', PdfTemplateCatalog::types()),
            'signature' => 'nullable|max:100',
        ]);
        $v->ruleset('bikeservice.bulk', [
            'action' => 'required|in:delete',
            'confirmed' => 'nullable',
        ]);
        $v->ruleset('bikeservice.settings', [
            'default_team' => 'required|in:mechanics,electric',
            'media_phases' => 'required|max:255',
            'default_city' => 'required|max:120',
            'default_citybikes_network' => 'required|max:120',
            'default_lat' => 'required|max:32',
            'default_lon' => 'required|max:32',
        ]);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new BikeServiceShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));

        $context->menu()->add(new MenuItem(
            id: 'bikeservice',
            label: 'nav.bikeservice',
            icon: 'bike',
            route: 'admin.bikeservice.orders',
            permission: 'bikeservice.view',
            order: 38,
            group: 'Provoz',
        ));
        $context->menu()->add(new MenuItem(
            id: 'bikeservice.bikes',
            label: 'nav.bikeservice.bikes',
            route: 'admin.bikeservice.bikes',
            permission: 'bikeservice.view',
            parent: 'bikeservice',
            order: 10,
        ));
        $context->menu()->add(new MenuItem(
            id: 'bikeservice.manufacturers',
            label: 'nav.bikeservice.manufacturers',
            route: 'admin.bikeservice.manufacturers',
            permission: 'bikeservice.view',
            parent: 'bikeservice',
            order: 12,
        ));
        $context->menu()->add(new MenuItem(
            id: 'bikeservice.catalog',
            label: 'nav.bikeservice.catalog',
            route: 'admin.bikeservice.catalog',
            permission: 'bikeservice.view',
            parent: 'bikeservice',
            order: 15,
        ));
        $context->menu()->add(new MenuItem(
            id: 'bikeservice.orders',
            label: 'nav.bikeservice.orders',
            route: 'admin.bikeservice.orders',
            permission: 'bikeservice.view',
            parent: 'bikeservice',
            order: 20,
        ));
        $context->menu()->add(new MenuItem(
            id: 'bikeservice.explore',
            label: 'nav.bikeservice.explore',
            route: 'admin.bikeservice.explore',
            permission: 'bikeservice.view',
            parent: 'bikeservice',
            order: 30,
        ));
        $context->menu()->add(new MenuItem(
            id: 'bikeservice.settings',
            label: 'nav.bikeservice.settings',
            route: 'admin.bikeservice.settings',
            permission: 'bikeservice.view',
            parent: 'settings',
            order: 21,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'bikeservice.orders',
            label: 'palette.bikeservice.orders',
            url: '/admin/bikeservice',
            icon: 'bike',
            permission: 'bikeservice.view',
            keywords: ['kolo', 'elektrokolo', 'servis', 'bikeservice', 'dílna'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'bikeservice.bikes',
            label: 'palette.bikeservice.bikes',
            url: '/admin/bikeservice/bikes',
            icon: 'bike',
            permission: 'bikeservice.view',
            keywords: ['kolo', 'ebike', 'serial', 'rám'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'bikeservice.manufacturers',
            label: 'palette.bikeservice.manufacturers',
            url: '/admin/bikeservice/manufacturers',
            icon: 'book',
            permission: 'bikeservice.view',
            keywords: ['katalog', 'výrobci', 'značky', 'rám', 'bike_data', 'author', 'specialized'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'bikeservice.catalog',
            label: 'palette.bikeservice.catalog',
            url: '/admin/bikeservice/catalog',
            icon: 'bike',
            permission: 'bikeservice.view',
            keywords: ['katalog', 'díly', 'shimano', 'sram', 'bosch', 'přehazovačka'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'bikeservice.explore',
            label: 'palette.bikeservice.explore',
            url: '/admin/bikeservice/explore',
            icon: 'search',
            permission: 'bikeservice.view',
            keywords: ['bike index', 'citybikes', 'nextbike', 'osm', 'ukradené'],
        ));

        $em = $context->get(EntityManagerInterface::class);
        $twig = $context->get(Environment::class);
        $auth = $context->get(Authenticator::class);
        $provider = new BikeServiceProvider($em, $context->settings());

        $context->adminSearch()->add(
            'bikeservice.orders',
            'order.id, order.note, order.service_info',
            static function (string $q, int $limit) use ($provider): array {
                $hits = [];
                foreach ($provider->searchOrders($q, $limit) as $order) {
                    $hits[] = new \Gereq\Admin\Search\AdminSearchHit(
                        id: 'bikeservice.order.' . $order->id(),
                        label: substr($order->id(), 0, 8),
                        url: '/admin/bikeservice/orders/' . $order->id(),
                        group: 'bikeservice.orders.title',
                        subtitle: $order->status() . ' · ' . $order->team(),
                        icon: 'bike',
                        keywords: array_values(array_filter([$order->id(), $order->note(), $order->serviceInfo()])),
                    );
                }

                return $hits;
            },
            permission: 'bikeservice.view',
            group: 'bikeservice.orders.title',
            icon: 'bike',
        );

        $context->adminSearch()->add(
            'bikeservice.bikes',
            'bike.brand, bike.model, bike.serial',
            static function (string $q, int $limit) use ($provider): array {
                $hits = [];
                foreach ($provider->searchBikes($q, $limit) as $bike) {
                    $hits[] = new \Gereq\Admin\Search\AdminSearchHit(
                        id: 'bikeservice.bike.' . $bike->id(),
                        label: $bike->label(),
                        url: '/admin/bikeservice/bikes/' . $bike->id(),
                        group: 'bikeservice.bikes.title',
                        subtitle: $bike->kind() . ' · ' . $bike->status(),
                        icon: 'bike',
                        keywords: array_values(array_filter([$bike->brand(), $bike->model(), $bike->serialNumber()])),
                    );
                }

                return $hits;
            },
            permission: 'bikeservice.view',
            group: 'bikeservice.bikes.title',
            icon: 'bike',
        );

        if (class_exists(Customer::class)) {
            $context->hooks()->addFilter('customers.show.sections', static function (array $sections, Customer $customer) use ($twig, $auth, $provider): array {
                if ($auth->user() === null || !$auth->user()->can('bikeservice.view')) {
                    return $sections;
                }
                $orders = $provider->listForCustomer($customer->id());
                $bikes = [];
                foreach ($orders as $order) {
                    $bid = $order->bikeId();
                    if (!isset($bikes[$bid])) {
                        $bike = $provider->getBike($bid);
                        if ($bike !== null) {
                            $bikes[$bid] = $bike;
                        }
                    }
                }
                $sections[] = [
                    'id' => 'bikeservice.orders',
                    'label' => 'bikeservice.partial.customer_title',
                    'order' => 47,
                    'html' => $twig->render('@bikeservice/partials/customer_orders.html.twig', [
                        'orders' => $orders,
                        'bikes' => $bikes,
                        'customer' => $customer,
                        'user' => $auth->user(),
                    ]),
                ];

                return $sections;
            }, HookPriority::NORMAL);
        }
    }
}
