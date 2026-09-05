<?php

declare(strict_types=1);

namespace Modules\Warehouse;

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
use Modules\Products\Domain\Product;
use Modules\Warehouse\Application\InventoryProvider;
use Modules\Warehouse\Http\Api\StockApiController;
use Modules\Warehouse\Http\Api\WarehouseApiController;
use Modules\Warehouse\Http\StockController;
use Modules\Warehouse\Http\WarehouseController;
use Twig\Environment;
use Modules\Warehouse\Application\Widgets\WarehouseShortcutWidget;
use Symfony\Contracts\Translation\TranslatorInterface;

final class WarehouseModule implements ModuleInterface
{
    public function id(): string
    {
        return 'warehouse';
    }

    public function version(): string
    {
        return '1.0.1';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('warehouse.view', 'perm.warehouse.view', 'perm.group.warehouse');
        $context->permissions()->register('warehouse.manage', 'perm.warehouse.manage', 'perm.group.warehouse');
        $context->permissions()->register('warehouse.stock.manage', 'perm.warehouse.stock.manage', 'perm.group.warehouse');

        $provider = new InventoryProvider($context->get(EntityManagerInterface::class));
        $context->capabilities()->register('inventory', $this->id(), $provider);

        $context->router()->group('/admin/warehouse', function (Router $routes): void {
            $routes->get('', [WarehouseController::class, 'index'])
                ->name('admin.warehouse.index')
                ->permission('warehouse.view');
            $routes->get('/create', [WarehouseController::class, 'create'])
                ->name('admin.warehouse.create')
                ->permission('warehouse.manage');
            $routes->post('', [WarehouseController::class, 'store'])
                ->name('admin.warehouse.store')
                ->permission('warehouse.manage');

            $routes->get('/stock', [StockController::class, 'index'])
                ->name('admin.warehouse.stock.index')
                ->permission('warehouse.view');
            $routes->get('/stock/movements/create', [StockController::class, 'createMovement'])
                ->name('admin.warehouse.stock.movement.create')
                ->permission('warehouse.stock.manage');
            $routes->post('/stock/movements', [StockController::class, 'storeMovement'])
                ->name('admin.warehouse.stock.movement.store')
                ->permission('warehouse.stock.manage');

            $routes->get('/{id}/edit', [WarehouseController::class, 'edit'])
                ->name('admin.warehouse.edit')
                ->permission('warehouse.manage');
            $routes->post('/{id}', [WarehouseController::class, 'update'])
                ->name('admin.warehouse.update')
                ->permission('warehouse.manage');
            $routes->post('/{id}/delete', [WarehouseController::class, 'destroy'])
                ->name('admin.warehouse.destroy')
                ->permission('warehouse.manage');
        }, ['middleware' => ['auth']]);

        $this->registerApi($context);
        $this->registerPlatformEvents($context);
        $this->registerValidation($context);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new WarehouseShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->menu()->add(new MenuItem(
            id: 'warehouse',
            label: 'nav.warehouse',
            icon: 'warehouse',
            route: 'admin.warehouse.index',
            permission: 'warehouse.view',
            order: 35,
            group: 'Obchod',
        ));
        $context->menu()->add(new MenuItem(
            id: 'warehouse.stock',
            label: 'nav.warehouse.stock',
            route: 'admin.warehouse.stock.index',
            permission: 'warehouse.view',
            parent: 'warehouse',
            order: 36,
        ));
        $context->menu()->add(new MenuItem(
            id: 'warehouse.create',
            label: 'nav.warehouse.create',
            route: 'admin.warehouse.create',
            permission: 'warehouse.manage',
            parent: 'warehouse',
            order: 37,
            hidden: true,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'warehouse.index',
            label: 'palette.warehouse.index',
            url: '/admin/warehouse',
            icon: 'warehouse',
            permission: 'warehouse.view',
            keywords: ['sklad', 'warehouse', 'stock', 'zásoby'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'warehouse.stock',
            label: 'palette.warehouse.stock',
            url: '/admin/warehouse/stock',
            icon: 'warehouse',
            permission: 'warehouse.view',
            keywords: ['stock', 'pohyb', 'zásoby'],
        ));

        $this->registerHooks($context);
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->registerKnownScope('warehouse.read');
        $context->api()->registerKnownScope('warehouse.write');

        $context->api()->module('warehouses', function (ApiModule $api): void {
            $api->get('/', [WarehouseApiController::class, 'index'])->scope('warehouse.read');
            $api->post('/', [WarehouseApiController::class, 'store'])->scope('warehouse.write');
            $api->get('/{id}', [WarehouseApiController::class, 'show'])->scope('warehouse.read');
            $api->patch('/{id}', [WarehouseApiController::class, 'update'])->scope('warehouse.write');
            $api->delete('/{id}', [WarehouseApiController::class, 'destroy'])->scope('warehouse.write');
        });

        $context->api()->module('stock', function (ApiModule $api): void {
            $api->get('/', [StockApiController::class, 'index'])->scope('warehouse.read');
        });

        $context->api()->module('movements', function (ApiModule $api): void {
            $api->get('/', [StockApiController::class, 'movements'])->scope('warehouse.read');
            $api->post('/', [StockApiController::class, 'storeMovement'])->scope('warehouse.write');
        });
    }

    private function registerPlatformEvents(ModuleContext $context): void
    {
        foreach ([
            'stock.movement_created' => 'Pohyb skladu vytvořen',
            'stock.low' => 'Nízký stav zásob',
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
        $validation = $context->validation();
        $validation->ruleset('warehouse.save', [
            'code' => 'required|min:1|max:64',
            'name' => 'required|min:2|max:190',
            'active' => 'nullable',
        ]);
        $validation->ruleset('warehouse.movement.save', [
            'warehouse_id' => 'required|max:36',
            'product_id' => 'required|max:36',
            'type' => 'required|in:in,out,transfer,adjust',
            'qty' => 'required',
            'target_warehouse_id' => 'nullable|max:36',
            'note' => 'nullable|max:20000',
        ]);
    }

    private function registerHooks(ModuleContext $context): void
    {
        $em = $context->get(EntityManagerInterface::class);
        $twig = $context->get(Environment::class);
        $auth = $context->get(Authenticator::class);
        $inventory = new InventoryProvider($em);

        $context->hooks()->addFilter(
            'products.show.sections',
            static function (array $sections, object $product) use ($em, $twig, $auth, $inventory): array {
                if (!($product instanceof Product)) {
                    return $sections;
                }
                if ($auth->user() === null || !$auth->user()->can('warehouse.view')) {
                    return $sections;
                }

                $items = $inventory->stockForProduct($product->id());
                $warehouses = [];
                foreach ($em->getRepository(\Modules\Warehouse\Domain\Warehouse::class)->findAll() as $w) {
                    if ($w instanceof \Modules\Warehouse\Domain\Warehouse) {
                        $warehouses[$w->id()] = $w;
                    }
                }

                $sections[] = [
                    'id' => 'warehouse.stock',
                    'label' => 'warehouse.stock.title',
                    'order' => 40,
                    'html' => $twig->render('@warehouse/partials/product_stock.html.twig', [
                        'product' => $product,
                        'items' => $items,
                        'warehouses' => $warehouses,
                        'user' => $auth->user(),
                    ]),
                ];

                return $sections;
            },
            HookPriority::NORMAL,
        );
    }
}
