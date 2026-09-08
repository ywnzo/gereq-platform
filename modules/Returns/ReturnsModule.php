<?php

declare(strict_types=1);

namespace Modules\Returns;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Returns\Application\ReturnsProvider;
use Modules\Returns\Http\Api\ReturnApiController;
use Modules\Returns\Http\ReturnController;
use Modules\Returns\Application\Widgets\ReturnsShortcutWidget;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ReturnsModule implements ModuleInterface
{
    public function id(): string
    {
        return 'returns';
    }

    public function version(): string
    {
        return '1.0.2';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('returns.view', 'perm.returns.view', 'perm.group.returns');
        $context->permissions()->register('returns.manage', 'perm.returns.manage', 'perm.group.returns');
        $context->capabilities()->register('returns', $this->id(), new ReturnsProvider($context->get(EntityManagerInterface::class)));

        $context->router()->group('/admin/returns', function (Router $routes): void {
            $routes->get('', [ReturnController::class, 'index'])->name('admin.returns.index')->permission('returns.view');
            $routes->get('/create', [ReturnController::class, 'create'])->name('admin.returns.create')->permission('returns.manage');
            $routes->post('', [ReturnController::class, 'store'])->name('admin.returns.store')->permission('returns.manage');
            $routes->get('/{id}', [ReturnController::class, 'show'])->name('admin.returns.show')->permission('returns.view');
            $routes->post('/{id}/status', [ReturnController::class, 'updateStatus'])->name('admin.returns.status')->permission('returns.manage');
            $routes->post('/{id}/delete', [ReturnController::class, 'destroy'])->name('admin.returns.destroy')->permission('returns.manage');
        }, ['middleware' => ['auth']]);

        $context->api()->registerKnownScope('returns.read');
        $context->api()->registerKnownScope('returns.write');
        $context->api()->module('returns', function (ApiModule $api): void {
            $api->get('/', [ReturnApiController::class, 'index'])->scope('returns.read');
            $api->post('/', [ReturnApiController::class, 'store'])->scope('returns.write');
            $api->get('/{id}', [ReturnApiController::class, 'show'])->scope('returns.read');
            $api->patch('/{id}', [ReturnApiController::class, 'update'])->scope('returns.write');
            $api->delete('/{id}', [ReturnApiController::class, 'destroy'])->scope('returns.write');
            $api->post('/{id}/status', [ReturnApiController::class, 'updateStatus'])->scope('returns.write');
        });

        foreach ([
            'return.created' => 'Reklamace vytvořena',
            'return.updated' => 'Reklamace upravena',
            'return.status_changed' => 'Změněn stav reklamace',
            'return.deleted' => 'Reklamace smazána',
        ] as $type => $desc) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))->description($desc)->webhookEnabled(true)
            );
        }

        $v = $context->validation();
        $v->ruleset('returns.save', [
            'order_id' => 'required|max:36',
            'customer_id' => 'nullable|max:36',
            'reason' => 'required|min:2|max:20000',
            'resolution' => 'nullable|in:refund,exchange,store_credit,none',
            'refund_amount' => 'nullable',
            'currency' => 'nullable|max:3',
            'note' => 'nullable|max:20000',
        ]);
        $context->forms()->entity('returns.save', \Modules\Returns\Domain\ReturnRequest::class);
        $v->ruleset('returns.status', [
            'status' => 'required|in:requested,approved,rejected,received,refunded,closed',
        ]);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new ReturnsShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->menu()->add(new MenuItem(
            id: 'returns',
            label: 'nav.returns',
            icon: 'undo',
            route: 'admin.returns.index',
            permission: 'returns.view',
            order: 33,
            group: 'Obchod',
        ));
        $context->menu()->add(new MenuItem(
            id: 'returns.create',
            label: 'nav.returns.create',
            route: 'admin.returns.create',
            permission: 'returns.manage',
            parent: 'returns',
            order: 34,
            hidden: true,
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'returns.index',
            label: 'palette.returns.index',
            url: '/admin/returns',
            icon: 'undo',
            permission: 'returns.view',
            keywords: ['reklamace', 'return', 'rma', 'vrácení'],
        ));
    }
}
