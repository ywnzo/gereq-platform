<?php

declare(strict_types=1);

namespace Modules\Fleet;

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
use Modules\Customers\Domain\Customer;
use Modules\Fleet\Application\FleetProvider;
use Modules\Fleet\Application\Widgets\FleetShortcutWidget;
use Modules\Fleet\Http\Api\VehicleApiController;
use Modules\Fleet\Http\BrandController;
use Modules\Fleet\Http\SettingsController;
use Modules\Fleet\Http\VehicleController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class FleetModule implements ModuleInterface
{
    public function id(): string
    {
        return 'fleet';
    }

    public function version(): string
    {
        return '1.4.0';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('fleet.view', 'perm.fleet.view', 'perm.group.fleet');
        $context->permissions()->register('fleet.manage', 'perm.fleet.manage', 'perm.group.fleet');

        $context->capabilities()->register('fleet', $this->id(), new FleetProvider($context->get(EntityManagerInterface::class)));

        $context->settings()->define('fleet.orv_api_key', SettingRecord::TYPE_ENCRYPTED, 'fleet.settings.orv_api_key', '', $this->id());

        $context->router()->group('/admin/fleet', function (Router $routes): void {
            $routes->get('', [VehicleController::class, 'index'])->name('admin.fleet.index')->permission('fleet.view');
            $routes->get('/brands', [BrandController::class, 'index'])->name('admin.fleet.brands')->permission('fleet.view');
            $routes->get('/brands/catalog', [BrandController::class, 'catalogSearch'])->name('admin.fleet.brands.catalog')->permission('fleet.view');
            $routes->post('/brands', [BrandController::class, 'storeBrand'])->name('admin.fleet.brands.store')->permission('fleet.manage');
            $routes->get('/brands/{id}', [BrandController::class, 'show'])->name('admin.fleet.brands.show')->permission('fleet.view');
            $routes->post('/brands/{id}/import', [BrandController::class, 'importModels'])->name('admin.fleet.brands.import')->permission('fleet.manage');
            $routes->post('/brands/{id}/delete', [BrandController::class, 'destroyBrand'])->name('admin.fleet.brands.destroy')->permission('fleet.manage');
            $routes->post('/models', [BrandController::class, 'storeModel'])->name('admin.fleet.models.store')->permission('fleet.manage');
            $routes->post('/models/{id}/delete', [BrandController::class, 'destroyModel'])->name('admin.fleet.models.destroy')->permission('fleet.manage');
            $routes->get('/settings', [SettingsController::class, 'edit'])->name('admin.fleet.settings')->permission('fleet.view');
            $routes->post('/settings', [SettingsController::class, 'update'])->name('admin.fleet.settings.update')->permission('fleet.manage');
            $routes->post('/orv-lookup', [VehicleController::class, 'orvLookup'])->name('admin.fleet.orv_lookup')->permission('fleet.manage');
            $routes->get('/create', [VehicleController::class, 'create'])->name('admin.fleet.create')->permission('fleet.manage');
            $routes->post('', [VehicleController::class, 'store'])->name('admin.fleet.store')->permission('fleet.manage');
            $routes->get('/{id}', [VehicleController::class, 'show'])->name('admin.fleet.show')->permission('fleet.view');
            $routes->get('/{id}/edit', [VehicleController::class, 'edit'])->name('admin.fleet.edit')->permission('fleet.manage');
            $routes->post('/{id}', [VehicleController::class, 'update'])->name('admin.fleet.update')->permission('fleet.manage');
            $routes->post('/{id}/status', [VehicleController::class, 'updateStatus'])->name('admin.fleet.status')->permission('fleet.manage');
            $routes->post('/{id}/media', [VehicleController::class, 'uploadMedia'])->name('admin.fleet.media')->permission('fleet.manage');
            $routes->post('/{id}/delete', [VehicleController::class, 'destroy'])->name('admin.fleet.destroy')->permission('fleet.manage');
        }, ['middleware' => ['auth']]);

        $context->api()->registerKnownScope('fleet.read');
        $context->api()->registerKnownScope('fleet.write');
        $context->api()->module('fleet', function (ApiModule $api): void {
            $api->get('/', [VehicleApiController::class, 'index'])->scope('fleet.read');
            $api->post('/', [VehicleApiController::class, 'store'])->scope('fleet.write');
            $api->get('/{id}', [VehicleApiController::class, 'show'])->scope('fleet.read');
            $api->patch('/{id}', [VehicleApiController::class, 'update'])->scope('fleet.write');
            $api->post('/{id}/status', [VehicleApiController::class, 'updateStatus'])->scope('fleet.write');
            $api->delete('/{id}', [VehicleApiController::class, 'destroy'])->scope('fleet.write');
        });

        foreach ([
            'fleet.vehicle_created' => 'Vozidlo vytvořeno',
            'fleet.vehicle_updated' => 'Vozidlo upraveno',
            'fleet.vehicle_deleted' => 'Vozidlo smazáno',
            'fleet.vehicle_status_changed' => 'Změněn stav vozidla',
        ] as $type => $desc) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))->description($desc)->webhookEnabled(true),
            );
        }

        $v = $context->validation();
        $v->ruleset('fleet.save', [
            'plate' => 'required|max:32',
            'vin' => 'nullable|max:32',
            'orv_id' => 'nullable|max:64',
            'brand' => 'nullable|max:80',
            'model' => 'nullable|max:80',
            'model_year' => 'nullable|integer',
            'color' => 'nullable|max:64',
            'fuel' => 'nullable|max:32',
            'gearbox' => 'nullable|in:manual,automatic',
            'motorization' => 'nullable|max:160',
            'mileage' => 'nullable|integer',
            'fuel_level' => 'nullable|max:16',
            'status' => 'nullable|in:available,rented,in_service,repair_wait,out_of_service',
            'ownership' => 'nullable|in:owned,external',
            'customer_id' => 'nullable|max:36',
            'stk_at' => 'nullable',
            'insurance_until' => 'nullable',
            'vignette_until' => 'nullable',
            'tires_at' => 'nullable',
            'warranty_until' => 'nullable',
            'note' => 'nullable|max:5000',
            'condition_interior' => 'nullable|max:32',
            'condition_exterior' => 'nullable|max:32',
            'condition_damage' => 'nullable|max:2000',
            'vehicle_kind' => 'nullable|max:80',
            'body_type' => 'nullable|max:80',
            'category' => 'nullable|max:16',
            'emission' => 'nullable|max:32',
            'engine_code' => 'nullable|max:40',
            'power_kw' => 'nullable|integer',
            'displacement_cc' => 'nullable|integer',
            'weight_kg' => 'nullable|integer',
            'seats' => 'nullable|integer',
            'max_speed' => 'nullable|integer',
            'consumption' => 'nullable|max:64',
            'tire_size' => 'nullable|max:64',
            'tp_number' => 'nullable|max:64',
            'first_registered_at' => 'nullable',
            'homologation' => 'nullable|max:80',
            'version_code' => 'nullable|max:80',
        ]);
        $v->ruleset('fleet.settings', [
            'orv_api_key' => 'nullable|max:255',
        ]);
        $v->ruleset('fleet.orv', [
            'orv_id' => 'required|max:64',
        ]);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new FleetShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));

        $context->menu()->add(new MenuItem(
            id: 'fleet',
            label: 'nav.fleet',
            icon: 'car',
            route: 'admin.fleet.index',
            permission: 'fleet.view',
            order: 35,
            group: 'Provoz',
        ));
        $context->menu()->add(new MenuItem(
            id: 'fleet.create',
            label: 'nav.fleet.create',
            route: 'admin.fleet.create',
            permission: 'fleet.manage',
            parent: 'fleet',
            order: 36,
            hidden: true,
        ));
        $context->menu()->add(new MenuItem(
            id: 'fleet.brands',
            label: 'nav.fleet.brands',
            route: 'admin.fleet.brands',
            permission: 'fleet.view',
            parent: 'fleet',
            order: 37,
            hidden: true,
        ));
        $context->menu()->add(new MenuItem(
            id: 'fleet.settings',
            label: 'nav.fleet.settings',
            route: 'admin.fleet.settings',
            permission: 'fleet.view',
            parent: 'fleet',
            order: 38,
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'fleet.index',
            label: 'palette.fleet.index',
            url: '/admin/fleet',
            icon: 'car',
            permission: 'fleet.view',
            keywords: ['vozidlo', 'fleet', 'spz', 'auto', 'vehicle', 'orv'],
        ));

        $em = $context->get(EntityManagerInterface::class);
        $provider = new FleetProvider($em);
        $context->adminSearch()->add(
            'fleet.vehicles',
            'vehicle.plate, vehicle.vin, vehicle.brand, vehicle.model, vehicle.orv_id',
            static function (string $q, int $limit) use ($provider): array {
                $hits = [];
                foreach ($provider->list(['q' => $q], $limit) as $vehicle) {
                    $hits[] = new \Gereq\Admin\Search\AdminSearchHit(
                        id: 'fleet.vehicle.' . $vehicle->id(),
                        label: $vehicle->label(),
                        url: '/admin/fleet/' . $vehicle->id(),
                        group: 'fleet.title',
                        subtitle: $vehicle->vin() ?: $vehicle->orvId(),
                        icon: 'car',
                        keywords: array_values(array_filter([
                            $vehicle->plate(),
                            $vehicle->vin(),
                            $vehicle->brand(),
                            $vehicle->model(),
                            $vehicle->orvId(),
                        ])),
                    );
                }

                return $hits;
            },
            permission: 'fleet.view',
            group: 'fleet.title',
            icon: 'car',
        );

        if (!class_exists(Customer::class)) {
            return;
        }

        $twig = $context->get(Environment::class);
        $auth = $context->get(Authenticator::class);
        $context->hooks()->addFilter('customers.show.sections', static function (array $sections, Customer $customer) use ($twig, $auth, $provider): array {
            if ($auth->user() === null || !$auth->user()->can('fleet.view')) {
                return $sections;
            }
            $sections[] = [
                'id' => 'fleet.vehicles',
                'label' => 'fleet.partial.title',
                'order' => 42,
                'html' => $twig->render('@fleet/partials/customer_vehicles.html.twig', [
                    'customer' => $customer,
                    'vehicles' => $provider->forCustomer($customer->id()),
                    'user' => $auth->user(),
                ]),
            ];

            return $sections;
        }, HookPriority::NORMAL);
    }
}
