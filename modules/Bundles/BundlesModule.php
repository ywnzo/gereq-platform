<?php

declare(strict_types=1);

namespace Modules\Bundles;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Bundles\Application\BundlesProvider;
use Modules\Bundles\Http\Api\BundleApiController;
use Modules\Bundles\Http\BundleController;
use Modules\Bundles\Application\Widgets\BundlesShortcutWidget;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class BundlesModule implements ModuleInterface
{
    public function id(): string
    {
        return 'bundles';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('bundles.view', 'perm.bundles.view', 'perm.group.bundles');
        $context->permissions()->register('bundles.manage', 'perm.bundles.manage', 'perm.group.bundles');

        $context->capabilities()->register(
            'bundles',
            $this->id(),
            new BundlesProvider($context->get(EntityManagerInterface::class)),
        );

        $context->router()->group('/admin/bundles', function (Router $routes): void {
            $routes->get('', [BundleController::class, 'index'])
                ->name('admin.bundles.index')
                ->permission('bundles.view');
            $routes->get('/create', [BundleController::class, 'create'])
                ->name('admin.bundles.create')
                ->permission('bundles.manage');
            $routes->post('', [BundleController::class, 'store'])
                ->name('admin.bundles.store')
                ->permission('bundles.manage');
            $routes->get('/{id}/edit', [BundleController::class, 'edit'])
                ->name('admin.bundles.edit')
                ->permission('bundles.manage');
            $routes->post('/{id}', [BundleController::class, 'update'])
                ->name('admin.bundles.update')
                ->permission('bundles.manage');
            $routes->post('/{id}/delete', [BundleController::class, 'destroy'])
                ->name('admin.bundles.destroy')
                ->permission('bundles.manage');
        }, ['middleware' => ['auth']]);

        $this->registerApi($context);
        $this->registerPlatformEvents($context);
        $this->registerValidation($context);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new BundlesShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->menu()->add(new MenuItem(
            id: 'bundles',
            label: 'nav.bundles',
            icon: 'layers',
            route: 'admin.bundles.index',
            permission: 'bundles.view',
            order: 36,
            group: 'Obchod',
        ));
        $context->menu()->add(new MenuItem(
            id: 'bundles.create',
            label: 'nav.bundles.create',
            route: 'admin.bundles.create',
            permission: 'bundles.manage',
            parent: 'bundles',
            order: 37,
            hidden: true,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'bundles.index',
            label: 'palette.bundles.index',
            url: '/admin/bundles',
            icon: 'layers',
            permission: 'bundles.view',
            keywords: ['sestava', 'bundle', 'balíček', 'set'],
        ));
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->registerKnownScope('bundles.read');
        $context->api()->registerKnownScope('bundles.write');

        $context->api()->module('bundles', function (ApiModule $api): void {
            $api->get('/', [BundleApiController::class, 'index'])->scope('bundles.read');
            $api->post('/', [BundleApiController::class, 'store'])->scope('bundles.write');
            $api->get('/{id}', [BundleApiController::class, 'show'])->scope('bundles.read');
            $api->patch('/{id}', [BundleApiController::class, 'update'])->scope('bundles.write');
            $api->delete('/{id}', [BundleApiController::class, 'destroy'])->scope('bundles.write');
            $api->get('/{id}/resolve', [BundleApiController::class, 'resolve'])->scope('bundles.read');
        });
    }

    private function registerPlatformEvents(ModuleContext $context): void
    {
        foreach ([
            'bundle.created' => 'Sestava vytvořena',
            'bundle.updated' => 'Sestava upravena',
            'bundle.deleted' => 'Sestava smazána',
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
        $context->validation()->ruleset('bundles.save', [
            'code' => 'required|min:1|max:64',
            'name' => 'required|min:2|max:190',
            'description' => 'nullable|max:20000',
            'discount_type' => 'required|max:16',
            'discount_value' => 'nullable',
            'currency' => 'nullable|max:3',
            'min_items' => 'nullable',
            'max_items' => 'nullable',
            'max_same_product' => 'nullable',
            'items_json' => 'nullable|max:100000',
            'active' => 'nullable',
        ]);
    }
}
