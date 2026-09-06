<?php

declare(strict_types=1);

namespace Modules\SearchEngines;

use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\SearchEngines\Application\SearchEnginesProvider;
use Modules\SearchEngines\Http\SearchController;

final class SearchEnginesModule implements ModuleInterface
{
    public function id(): string
    {
        return 'searchengines';
    }

    public function version(): string
    {
        return '1.3.6';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('searchengines.view', 'perm.searchengines.view', 'perm.group.searchengines');
        $context->permissions()->register('searchengines.manage', 'perm.searchengines.manage', 'perm.group.searchengines');

        $provider = new SearchEnginesProvider();
        $context->capabilities()->register('searchengines', $this->id(), $provider);

        $context->router()->group('/admin/searchengines', function (Router $routes): void {
            $routes->get('', [SearchController::class, 'index'])
                ->name('admin.searchengines.index')
                ->permission('searchengines.view');
            $routes->get('/search', [SearchController::class, 'search'])
                ->name('admin.searchengines.search')
                ->permission('searchengines.view');
            $routes->get('/offers', [SearchController::class, 'offers'])
                ->name('admin.searchengines.offers')
                ->permission('searchengines.view');
        }, ['middleware' => ['auth']]);
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'searchengines',
            label: 'nav.searchengines',
            icon: 'search',
            route: 'admin.searchengines.index',
            permission: 'searchengines.view',
            order: 36,
            group: 'Nástroje',
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'searchengines.index',
            label: 'palette.searchengines.index',
            url: '/admin/searchengines',
            icon: 'search',
            permission: 'searchengines.view',
            keywords: ['vyhledavac', 'heureka', 'seznam', 'zbozi', 'hledejceny', 'jama', 'autodilypro', 'autokelly', 'autodily', 'mojekolo', 'search'],
        ));
    }
}
