<?php

declare(strict_types=1);

namespace Modules\Feeds;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Feeds\Application\Drivers\CustomDriver;
use Modules\Feeds\Application\Drivers\GoogleMerchantDriver;
use Modules\Feeds\Application\Drivers\HeurekaDriver;
use Modules\Feeds\Application\FeedsProvider;
use Modules\Feeds\Http\Api\FeedApiController;
use Modules\Feeds\Http\FeedController;
use Modules\Feeds\Application\Widgets\FeedsShortcutWidget;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class FeedsModule implements ModuleInterface
{
    public function id(): string
    {
        return 'feeds';
    }

    public function version(): string
    {
        return '1.0.2';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('feeds.view', 'perm.feeds.view', 'perm.group.feeds');
        $context->permissions()->register('feeds.manage', 'perm.feeds.manage', 'perm.group.feeds');

        $context->capabilities()->register(
            'feeds',
            $this->id(),
            new FeedsProvider(
                $context->get(EntityManagerInterface::class),
                new GoogleMerchantDriver(),
                new HeurekaDriver(),
                new CustomDriver(),
            ),
        );

        $context->router()->group('/admin/feeds', function (Router $routes): void {
            $routes->get('', [FeedController::class, 'index'])
                ->name('admin.feeds.index')
                ->permission('feeds.view');
            $routes->get('/create', [FeedController::class, 'create'])
                ->name('admin.feeds.create')
                ->permission('feeds.manage');
            $routes->post('', [FeedController::class, 'store'])
                ->name('admin.feeds.store')
                ->permission('feeds.manage');
            $routes->get('/{id}/edit', [FeedController::class, 'edit'])
                ->name('admin.feeds.edit')
                ->permission('feeds.manage');
            $routes->post('/{id}', [FeedController::class, 'update'])
                ->name('admin.feeds.update')
                ->permission('feeds.manage');
            $routes->get('/{id}/preview', [FeedController::class, 'preview'])
                ->name('admin.feeds.preview')
                ->permission('feeds.view');
            $routes->post('/{id}/delete', [FeedController::class, 'destroy'])
                ->name('admin.feeds.destroy')
                ->permission('feeds.manage');
        }, ['middleware' => ['auth']]);

        $this->registerApi($context);
        $this->registerPlatformEvents($context);
        $this->registerValidation($context);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new FeedsShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->menu()->add(new MenuItem(
            id: 'feeds',
            label: 'nav.feeds',
            icon: 'rss',
            route: 'admin.feeds.index',
            permission: 'feeds.view',
            order: 38,
            group: 'Obchod',
        ));
        $context->menu()->add(new MenuItem(
            id: 'feeds.create',
            label: 'nav.feeds.create',
            route: 'admin.feeds.create',
            permission: 'feeds.manage',
            parent: 'feeds',
            order: 39,
            hidden: true,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'feeds.index',
            label: 'palette.feeds.index',
            url: '/admin/feeds',
            icon: 'rss',
            permission: 'feeds.view',
            keywords: ['feed', 'heureka', 'google', 'merchant', 'xml'],
        ));
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->registerKnownScope('feeds.read');
        $context->api()->registerKnownScope('feeds.write');

        $context->api()->module('feeds', function (ApiModule $api): void {
            $api->get('/', [FeedApiController::class, 'index'])->scope('feeds.read');
            $api->post('/', [FeedApiController::class, 'store'])->scope('feeds.write');
            $api->get('/definitions/{id}', [FeedApiController::class, 'show'])->scope('feeds.read');
            $api->patch('/definitions/{id}', [FeedApiController::class, 'update'])->scope('feeds.write');
            $api->delete('/definitions/{id}', [FeedApiController::class, 'destroy'])->scope('feeds.write');
            $api->get('/{code}', [FeedApiController::class, 'generate'])->scope('feeds.read');
        });
    }

    private function registerPlatformEvents(ModuleContext $context): void
    {
        foreach ([
            'feed.created' => 'Feed vytvořen',
            'feed.updated' => 'Feed upraven',
            'feed.deleted' => 'Feed smazán',
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
        $context->validation()->ruleset('feeds.save', [
            'code' => 'required|min:1|max:64',
            'name' => 'required|min:2|max:190',
            'driver' => 'required|max:32',
            'format' => 'required|max:16',
            'config_json' => 'nullable|max:100000',
            'active' => 'nullable',
        ]);
        $context->forms()->entity('feeds.save', \Modules\Feeds\Domain\FeedDefinition::class);
    }
}
