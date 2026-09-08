<?php

declare(strict_types=1);

namespace Modules\Coupons;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Coupons\Application\CouponsProvider;
use Modules\Coupons\Http\Api\CouponApiController;
use Modules\Coupons\Http\CouponController;
use Modules\Coupons\Application\Widgets\CouponsShortcutWidget;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CouponsModule implements ModuleInterface
{
    public function id(): string
    {
        return 'coupons';
    }

    public function version(): string
    {
        return '1.0.2';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('coupons.view', 'perm.coupons.view', 'perm.group.coupons');
        $context->permissions()->register('coupons.manage', 'perm.coupons.manage', 'perm.group.coupons');

        $context->capabilities()->register(
            'coupons',
            $this->id(),
            new CouponsProvider($context->get(EntityManagerInterface::class)),
        );

        $context->router()->group('/admin/coupons', function (Router $routes): void {
            $routes->get('', [CouponController::class, 'index'])
                ->name('admin.coupons.index')
                ->permission('coupons.view');
            $routes->get('/create', [CouponController::class, 'create'])
                ->name('admin.coupons.create')
                ->permission('coupons.manage');
            $routes->post('', [CouponController::class, 'store'])
                ->name('admin.coupons.store')
                ->permission('coupons.manage');
            $routes->get('/{id}/edit', [CouponController::class, 'edit'])
                ->name('admin.coupons.edit')
                ->permission('coupons.manage');
            $routes->post('/{id}', [CouponController::class, 'update'])
                ->name('admin.coupons.update')
                ->permission('coupons.manage');
            $routes->post('/{id}/delete', [CouponController::class, 'destroy'])
                ->name('admin.coupons.destroy')
                ->permission('coupons.manage');
        }, ['middleware' => ['auth']]);

        $this->registerApi($context);
        $this->registerPlatformEvents($context);
        $this->registerValidation($context);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new CouponsShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->menu()->add(new MenuItem(
            id: 'coupons',
            label: 'nav.coupons',
            icon: 'tag',
            route: 'admin.coupons.index',
            permission: 'coupons.view',
            order: 34,
            group: 'Obchod',
        ));
        $context->menu()->add(new MenuItem(
            id: 'coupons.create',
            label: 'nav.coupons.create',
            route: 'admin.coupons.create',
            permission: 'coupons.manage',
            parent: 'coupons',
            order: 35,
            hidden: true,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'coupons.index',
            label: 'palette.coupons.index',
            url: '/admin/coupons',
            icon: 'tag',
            permission: 'coupons.view',
            keywords: ['kupón', 'coupon', 'sleva', 'discount', 'promo'],
        ));
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->registerKnownScope('coupons.read');
        $context->api()->registerKnownScope('coupons.write');

        $context->api()->module('coupons', function (ApiModule $api): void {
            $api->get('/', [CouponApiController::class, 'index'])->scope('coupons.read');
            $api->post('/', [CouponApiController::class, 'store'])->scope('coupons.write');
            $api->post('/validate', [CouponApiController::class, 'validateCoupon'])->scope('coupons.read');
            $api->get('/{id}', [CouponApiController::class, 'show'])->scope('coupons.read');
            $api->patch('/{id}', [CouponApiController::class, 'update'])->scope('coupons.write');
            $api->delete('/{id}', [CouponApiController::class, 'destroy'])->scope('coupons.write');
        });
    }

    private function registerPlatformEvents(ModuleContext $context): void
    {
        foreach ([
            'coupon.created' => 'Kupón vytvořen',
            'coupon.updated' => 'Kupón upraven',
            'coupon.deleted' => 'Kupón smazán',
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
        $context->validation()->ruleset('coupons.save', [
            'code' => 'required|min:1|max:64',
            'name' => 'required|min:2|max:190',
            'type' => 'required|max:16',
            'value' => 'required',
            'currency' => 'nullable|max:3',
            'min_order' => 'nullable',
            'max_uses' => 'nullable',
            'starts_at' => 'nullable',
            'ends_at' => 'nullable',
            'active' => 'nullable',
        ]);
        $context->forms()->entity('coupons.save', \Modules\Coupons\Domain\Coupon::class);
    }
}
