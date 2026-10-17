<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Admin;

use Gereq\Module\ModuleManifest;
use Gereq\Testing\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class ModuleChildrenViewTest extends TestCase
{
    public function testDisabledParentHidesChildActivation(): void
    {
        $html = $this->renderChild(false, false, ['webplus'], false);
        self::assertStringContainsString('data-submodule-id="webplus_ai"', $html);
        self::assertStringContainsString('/admin/modules/webplus_ai', $html);
        self::assertStringNotContainsString('/admin/modules/enable/webplus_ai', $html);
        self::assertStringNotContainsString('/admin/modules/disable/webplus_ai', $html);
    }

    public function testPausedInstalledChildCanBeActivatedWhenParentEnabled(): void
    {
        $html = $this->renderChild(false, true, [], true);
        self::assertStringContainsString('/admin/modules/enable/webplus_ai', $html);
        self::assertStringContainsString('/admin/modules/disable/webplus_ai', $html);
        self::assertStringContainsString('name="_token"', $html);
    }

    public function testMissingDepsDisableActivateWhenParentEnabled(): void
    {
        $html = $this->renderChild(false, false, ['other'], true);
        self::assertStringContainsString('/admin/modules/enable/webplus_ai', $html);
        self::assertStringContainsString('disabled', $html);
    }

    /** @param list<string> $missing */
    private function renderChild(bool $enabled, bool $installed, array $missing, bool $parentEnabled): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/resources/views'));
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('url', static function (string $route, array $params): string {
            $action = $route === 'admin.modules.show' ? '' : substr($route, strlen('admin.modules.')) . '/';

            return '/admin/modules/' . $action . $params['id'];
        }));
        $twig->addFunction(new TwigFunction('csrf_field', static fn (): string => '<input name="_token" value="test">'));
        $parent = ModuleManifest::fromArray(['id' => 'webplus', 'class' => 'ParentModule'], '/tmp/WebPlus');
        $child = ModuleManifest::fromArray(['id' => 'webplus_ai', 'name' => 'AI', 'class' => 'AiModule'], '/tmp/WebPlus/Submodules/Ai', 'webplus');

        return $twig->render('admin/modules/_children.html.twig', [
            'parent' => $parent,
            'parent_enabled' => $parentEnabled,
            'children' => [[
                'manifest' => $child, 'enabled' => $enabled, 'installed' => $installed,
                'missing' => $missing, 'update_available' => false,
            ]],
        ]);
    }
}
