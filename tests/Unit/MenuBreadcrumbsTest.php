<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Admin\Menu\Menu;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Http\Router;
use Gereq\Tests\Support\PassthroughTranslator;
use PHPUnit\Framework\TestCase;

final class MenuBreadcrumbsTest extends TestCase
{
    public function testCustomerCreateTrail(): void
    {
        $menu = $this->menu();
        $router = $this->router([
            'admin.dashboard' => '/admin',
            'admin.customers.index' => '/admin/customers',
            'admin.customers.create' => '/admin/customers/create',
        ]);

        $crumbs = $menu->breadcrumbsFor(
            'admin.customers.create',
            $router,
            null,
            'Nový zákazník',
        );

        self::assertSame(['nav.overview', 'nav.customers', 'Nový zákazník'], array_column($crumbs, 'label'));
        self::assertNotEmpty($crumbs[0]['url'] ?? null);
        self::assertNotEmpty($crumbs[1]['url'] ?? null);
        self::assertArrayNotHasKey('url', $crumbs[2]);
    }

    public function testCustomerEditUsesIndexParent(): void
    {
        $menu = $this->menu();
        $router = $this->router([
            'admin.dashboard' => '/admin',
            'admin.customers.index' => '/admin/customers',
        ]);

        $crumbs = $menu->breadcrumbsFor(
            'admin.customers.edit',
            $router,
            null,
            'Jiří Grygerek',
        );

        self::assertSame(['nav.overview', 'nav.customers', 'Jiří Grygerek'], array_column($crumbs, 'label'));
    }

    public function testModuleIndexIsTwoLevels(): void
    {
        $menu = $this->menu();
        $router = $this->router([
            'admin.dashboard' => '/admin',
            'admin.customers.index' => '/admin/customers',
        ]);

        $crumbs = $menu->breadcrumbsFor('admin.customers.index', $router, null, 'nav.customers');

        self::assertSame(['nav.overview', 'nav.customers'], array_column($crumbs, 'label'));
        self::assertArrayNotHasKey('url', $crumbs[1]);
    }

    public function testNestedBrokerCreate(): void
    {
        $menu = $this->menu();
        $menu->add(new MenuItem(
            id: 'listings',
            label: 'nav.listings',
            route: 'admin.listings.index',
            order: 30,
        ));
        $menu->add(new MenuItem(
            id: 'listings.brokers',
            label: 'nav.listings.brokers',
            route: 'admin.listings.brokers.index',
            parent: 'listings',
            order: 10,
        ));
        $menu->add(new MenuItem(
            id: 'listings.brokers.create',
            label: 'nav.listings.brokers.create',
            route: 'admin.listings.brokers.create',
            parent: 'listings.brokers',
            hidden: true,
        ));

        $router = $this->router([
            'admin.dashboard' => '/admin',
            'admin.listings.index' => '/admin/listings',
            'admin.listings.brokers.index' => '/admin/listings/brokers',
            'admin.listings.brokers.create' => '/admin/listings/brokers/create',
        ]);

        $crumbs = $menu->breadcrumbsFor(
            'admin.listings.brokers.create',
            $router,
            null,
            'Nový makléř',
        );

        self::assertSame(
            ['nav.overview', 'nav.listings', 'nav.listings.brokers', 'Nový makléř'],
            array_column($crumbs, 'label'),
        );
    }

    private function menu(): Menu
    {
        $menu = new Menu(new PassthroughTranslator());
        $menu->add(new MenuItem(
            id: 'customers',
            label: 'nav.customers',
            route: 'admin.customers.index',
            order: 20,
        ));
        $menu->add(new MenuItem(
            id: 'customers.create',
            label: 'nav.customers.create',
            route: 'admin.customers.create',
            parent: 'customers',
            hidden: true,
        ));

        return $menu;
    }

    /** @param array<string, string> $routes */
    private function router(array $routes): Router
    {
        $router = new Router();
        foreach ($routes as $name => $path) {
            $router->get($path, static fn () => null)->name($name);
        }

        return $router;
    }
}
