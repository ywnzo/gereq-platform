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
use Modules\Reviews\Http\Public\PublicReviewsController;
use Modules\Reviews\Http\ReviewController;
use Modules\Reviews\Http\ReviewSettingsController;
use Modules\Reviews\Application\Widgets\PendingReviewsWidget;
use Modules\Reviews\Application\Widgets\ReviewRatingChartWidget;
use Modules\Reviews\Application\Widgets\ReviewStatsWidget;
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
        return '2.2.1';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('reviews.view', 'perm.reviews.view', 'perm.group.reviews');
        $context->permissions()->register('reviews.manage', 'perm.reviews.manage', 'perm.group.reviews');
        $context->capabilities()->register('reviews', $this->id(), new ReviewsProvider($context->get(EntityManagerInterface::class)));

        $context->settings()->define('reviews.google_enabled', SettingRecord::TYPE_BOOLEAN, 'reviews.settings.google_enabled', false, $this->id(), [], false);
        $context->settings()->define('reviews.google_place_id', SettingRecord::TYPE_STRING, 'reviews.settings.google_place_id', '', $this->id(), [], false);
        $context->settings()->define('reviews.google_api_key', SettingRecord::TYPE_ENCRYPTED, 'reviews.settings.google_api_key', '', $this->id(), [], false);
        $context->settings()->define('reviews.facebook_enabled', SettingRecord::TYPE_BOOLEAN, 'reviews.settings.facebook_enabled', false, $this->id(), [], false);
        $context->settings()->define('reviews.facebook_page_id', SettingRecord::TYPE_STRING, 'reviews.settings.facebook_page_id', '', $this->id(), [], false);
        $context->settings()->define('reviews.facebook_access_token', SettingRecord::TYPE_ENCRYPTED, 'reviews.settings.facebook_access_token', '', $this->id(), [], false);
        $context->settings()->define('reviews.replies_enabled', SettingRecord::TYPE_BOOLEAN, 'reviews.settings.replies_enabled', true, $this->id(), [], false);
        $context->settings()->define('reviews.default_locale', SettingRecord::TYPE_STRING, 'reviews.settings.default_locale', 'cs', $this->id(), [], false);

        $context->router()->group('/admin/reviews', function (Router $routes): void {
            $routes->get('', [ReviewController::class, 'index'])->name('admin.reviews.index')->permission('reviews.view');
            $routes->get('/create', [ReviewController::class, 'create'])->name('admin.reviews.create')->permission('reviews.manage');
            $routes->post('', [ReviewController::class, 'store'])->name('admin.reviews.store')->permission('reviews.manage');
            $routes->get('/settings', [ReviewSettingsController::class, 'edit'])->name('admin.reviews.settings')->permission('reviews.manage');
            $routes->post('/settings', [ReviewSettingsController::class, 'update'])->name('admin.reviews.settings.update')->permission('reviews.manage');
            $routes->get('/{id}', [ReviewController::class, 'show'])->name('admin.reviews.show')->permission('reviews.view');
            $routes->post('/{id}/approve', [ReviewController::class, 'approve'])->name('admin.reviews.approve')->permission('reviews.manage');
            $routes->post('/{id}/reject', [ReviewController::class, 'reject'])->name('admin.reviews.reject')->permission('reviews.manage');
            $routes->post('/{id}/replies', [ReviewController::class, 'storeReply'])->name('admin.reviews.replies.store')->permission('reviews.manage');
            $routes->post('/{id}/replies/{replyId}/delete', [ReviewController::class, 'destroyReply'])->name('admin.reviews.replies.destroy')->permission('reviews.manage');
            $routes->post('/{id}/delete', [ReviewController::class, 'destroy'])->name('admin.reviews.destroy')->permission('reviews.manage');
        }, ['middleware' => ['auth']]);

        $context->router()->add('/api/public/reviews', ['OPTIONS'], [PublicReviewsController::class, 'options'])
            ->name('api.public.reviews.options');
        $context->router()->get('/api/public/reviews', [PublicReviewsController::class, 'index'])
            ->name('api.public.reviews.index');

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
        ReviewSettingsController::registerValidation($v, $context->get(TranslatorInterface::class));
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
        $v->ruleset('reviews.admin.create', [
            'product_id' => 'nullable|max:36',
            'author_name' => 'required|min:1|max:190',
            'author_email' => 'nullable|email|max:190',
            'rating' => 'required',
            'title' => 'nullable|max:190',
            'body' => 'required|min:2|max:20000',
            'status' => 'nullable|in:pending,approved,rejected',
            'locale' => 'nullable|max:16',
        ]);
        $v->ruleset('reviews.moderate', [
            'status' => 'required|in:pending,approved,rejected',
        ]);
        $v->ruleset('reviews.reply.create', [
            'body' => 'required|min:2|max:20000',
            'author_name' => 'nullable|min:1|max:190',
        ]);
    }

    public function boot(ModuleContext $context): void
    {
        if ($context->cms()->isEnabled()) {
            $provider = $context->get(ReviewsProvider::class);
            $context->cms()->register(
                'reviews',
                'cms.dynamic.source.reviews',
                ['id', 'author_name', 'author_initials', 'rating', 'title', 'body', 'source', 'external_url', 'created_at'],
                static fn (int $limit): array => array_map(
                    $provider->serializeCms(...),
                    $provider->approved('site', $limit),
                ),
                '<blockquote><p>{{ item.body }}</p><strong>{{ item.author_name }}</strong><p>{{ item.rating }} / 5</p></blockquote>',
            );
        }

        $context->dashboard()->addWidget(new ReviewsShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        foreach ([ReviewStatsWidget::class, PendingReviewsWidget::class, ReviewRatingChartWidget::class] as $widgetClass) {
            $context->dashboard()->addWidget(new $widgetClass(
                $context->get(Environment::class),
                $context->get(EntityManagerInterface::class),
                $context->get(TranslatorInterface::class),
                $context->get(Router::class),
            ));
        }
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
        $context->commandPalette()->add(new PaletteCommand(
            id: 'reviews.create',
            label: 'palette.reviews.create',
            url: '/admin/reviews/create',
            icon: 'plus',
            permission: 'reviews.manage',
            keywords: ['recenze', 'nová', 'přidat', 'reference'],
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
