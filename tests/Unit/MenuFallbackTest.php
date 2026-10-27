<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Admin\Menu\Menu;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Http\Router;
use Gereq\Tests\Support\PassthroughTranslator;
use PHPUnit\Framework\TestCase;

final class MenuFallbackTest extends TestCase
{
    public function testChildAppearsUnderVisibleParent(): void
    {
        $menu = $this->menu(null);
        $sections = $menu->tree(null, new Router());
        self::assertSame('cms', $sections[0]['nodes'][0]['item']->id);
        self::assertSame('content.staging', $sections[0]['nodes'][0]['children'][0]->id);
    }

    public function testChildAppearsAfterFormSubmissions(): void
    {
        $menu = $this->menu(null);
        $menu->add(new MenuItem(id: 'forms', label: 'forms', parent: 'cms', order: 40));
        $children = $menu->tree(null, new Router())[0]['nodes'][0]['children'];
        self::assertSame(['forms', 'content.staging'], array_map(static fn (MenuItem $item): string => $item->id, $children));
    }

    public function testChildRemainsAvailableWhenParentIsUnavailable(): void
    {
        $menu = $this->menu('cms.view');
        $sections = $menu->tree(null, new Router());
        self::assertSame('content.staging', $sections[0]['nodes'][0]['item']->id);
    }

    public function testChildPreservesItsSectionPosition(): void
    {
        $menu = new Menu(new PassthroughTranslator());
        $menu->add(new MenuItem(id: 'shop', label: 'shop', order: 30, group: 'shop'));
        $menu->add(new MenuItem(id: 'cms', label: 'cms', order: 50, group: 'content'));
        $menu->add(new MenuItem(id: 'staging', label: 'staging', parent: 'cms', order: 50, group: 'content', fallbackToRoot: true, groupOrder: 15));

        self::assertSame('content', $menu->tree(null, new Router())[0]['group']);
    }

    private function menu(?string $parentPermission): Menu
    {
        $menu = new Menu(new PassthroughTranslator());
        $menu->add(new MenuItem(id: 'cms', label: 'cms', permission: $parentPermission, group: 'content'));
        $menu->add(new MenuItem(id: 'content.staging', label: 'staging', parent: 'cms', order: 50, group: 'content', fallbackToRoot: true));

        return $menu;
    }
}
