<?php

declare(strict_types=1);

namespace Modules\Reviews;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Reviews\Application\ReviewsProvider;
use Modules\Reviews\Http\Api\ReviewApiController;
use Modules\Reviews\Http\ReviewController;

final class ReviewsModule implements ModuleInterface
{
    public function id(): string
    {
        return 'reviews';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('reviews.view', 'perm.reviews.view', 'perm.group.reviews');
        $context->permissions()->register('reviews.manage', 'perm.reviews.manage', 'perm.group.reviews');
        $context->capabilities()->register('reviews', $this->id(), new ReviewsProvider($context->get(EntityManagerInterface::class)));

        $context->router()->group('/admin/reviews', function (Router $routes): void {
            $routes->get('', [ReviewController::class, 'index'])->name('admin.reviews.index')->permission('reviews.view');
            $routes->get('/{id}', [ReviewController::class, 'show'])->name('admin.reviews.show')->permission('reviews.view');
            $routes->post('/{id}/approve', [ReviewController::class, 'approve'])->name('admin.reviews.approve')->permission('reviews.manage');
            $routes->post('/{id}/reject', [ReviewController::class, 'reject'])->name('admin.reviews.reject')->permission('reviews.manage');
            $routes->post('/{id}/delete', [ReviewController::class, 'destroy'])->name('admin.reviews.destroy')->permission('reviews.manage');
        }, ['middleware' => ['auth']]);

        $context->api()->registerKnownScope('reviews.read');
        $context->api()->registerKnownScope('reviews.write');
        $context->api()->module('reviews', function (ApiModule $api): void {
            $api->get('/product/{productId}', [ReviewApiController::class, 'forProduct'])->scope('reviews.read');
            $api->post('/', [ReviewApiController::class, 'store'])->scope('reviews.write');
            $api->patch('/{id}', [ReviewApiController::class, 'moderate'])->scope('reviews.write');
        });

        foreach ([
            'review.created' => 'Recenze vytvořena',
            'review.approved' => 'Recenze schválena',
            'review.rejected' => 'Recenze zamítnuta',
            'review.deleted' => 'Recenze smazána',
        ] as $type => $desc) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))->description($desc)->webhookEnabled(true)
            );
        }

        $v = $context->validation();
        $v->ruleset('reviews.create', [
            'product_id' => 'required|max:36',
            'customer_id' => 'nullable|max:36',
            'author_name' => 'required|min:1|max:190',
            'author_email' => 'nullable|email|max:190',
            'rating' => 'required',
            'title' => 'nullable|max:190',
            'body' => 'required|min:2|max:20000',
        ]);
        $v->ruleset('reviews.moderate', [
            'status' => 'required|in:pending,approved,rejected',
        ]);
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'reviews',
            label: 'nav.reviews',
            icon: 'star',
            route: 'admin.reviews.index',
            permission: 'reviews.view',
            order: 52,
            group: 'Obsah',
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'reviews.index',
            label: 'palette.reviews.index',
            url: '/admin/reviews',
            icon: 'star',
            permission: 'reviews.view',
            keywords: ['recenze', 'review', 'rating', 'hodnocení'],
        ));
    }
}
