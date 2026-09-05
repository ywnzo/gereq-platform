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
use Gereq\Setting\SettingRecord;
use Modules\Reviews\Application\ReviewsProvider;
use Modules\Reviews\Http\Api\ReviewApiController;
use Modules\Reviews\Http\ReviewController;
use Modules\Reviews\Http\ReviewSettingsController;
use Modules\Reviews\Application\Widgets\ReviewsShortcutWidget;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ReviewsModule implements ModuleInterface
{
    public function id(): string
    {
        return 'reviews';
    }

    public function version(): string
    {
        return '1.3.0';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('reviews.view', 'perm.reviews.view', 'perm.group.reviews');
        $context->permissions()->register('reviews.manage', 'perm.reviews.manage', 'perm.group.reviews');
        $context->capabilities()->register('reviews', $this->id(), new ReviewsProvider($context->get(EntityManagerInterface::class)));

        $context->settings()->define('reviews.google_enabled', SettingRecord::TYPE_BOOLEAN, 'reviews.settings.google_enabled', false, $this->id());
        $context->settings()->define('reviews.google_place_id', SettingRecord::TYPE_STRING, 'reviews.settings.google_place_id', '', $this->id());
        $context->settings()->define('reviews.google_api_key', SettingRecord::TYPE_ENCRYPTED, 'reviews.settings.google_api_key', '', $this->id());
        $context->settings()->define('reviews.facebook_enabled', SettingRecord::TYPE_BOOLEAN, 'reviews.settings.facebook_enabled', false, $this->id());
        $context->settings()->define('reviews.facebook_page_id', SettingRecord::TYPE_STRING, 'reviews.settings.facebook_page_id', '', $this->id());
        $context->settings()->define('reviews.facebook_access_token', SettingRecord::TYPE_ENCRYPTED, 'reviews.settings.facebook_access_token', '', $this->id());
        $context->settings()->define('reviews.replies_enabled', SettingRecord::TYPE_BOOLEAN, 'reviews.settings.replies_enabled', true, $this->id());
        $context->settings()->define('reviews.default_locale', SettingRecord::TYPE_STRING, 'reviews.settings.default_locale', 'cs', $this->id());

        $context->router()->group('/admin/reviews', function (Router $routes): void {
            $routes->get('', [ReviewController::class, 'index'])->name('admin.reviews.index')->permission('reviews.view');
            $routes->get('/settings', [ReviewSettingsController::class, 'edit'])->name('admin.reviews.settings')->permission('reviews.manage');
            $routes->post('/settings', [ReviewSettingsController::class, 'update'])->name('admin.reviews.settings.update')->permission('reviews.manage');
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
            'review.imported' => 'Externí recenze importována',
            'review.replied' => 'Odpověď na recenzi vytvořena',
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
            'source' => 'nullable|in:local,google,facebook',
            'external_id' => 'nullable|max:190',
            'external_url' => 'nullable|max:500',
        ]);
        $v->ruleset('reviews.moderate', [
            'status' => 'required|in:pending,approved,rejected',
        ]);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new ReviewsShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->menu()->add(new MenuItem(
            id: 'reviews',
            label: 'nav.reviews',
            icon: 'star',
            route: 'admin.reviews.index',
            permission: 'reviews.view',
            order: 52,
            group: 'Obsah',
        ));
        $context->menu()->add(new MenuItem(
            id: 'reviews.settings',
            label: 'nav.reviews.settings',
            icon: 'settings',
            route: 'admin.reviews.settings',
            permission: 'reviews.manage',
            parent: 'reviews',
            order: 53,
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
        $context->commandPalette()->add(new PaletteCommand(
            id: 'reviews.settings',
            label: 'palette.reviews.settings',
            url: '/admin/reviews/settings',
            icon: 'settings',
            permission: 'reviews.manage',
            keywords: ['recenze', 'google', 'facebook', 'nastavení', 'settings'],
        ));
    }
}
