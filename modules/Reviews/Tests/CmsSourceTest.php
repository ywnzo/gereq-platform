<?php

declare(strict_types=1);

namespace Modules\Reviews\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Dashboard\Dashboard;
use Gereq\Admin\Menu\Menu;
use Gereq\Cms\Cms;
use Gereq\Http\Router;
use Gereq\Module\EnabledModulesProvider;
use Gereq\Module\ModuleContext;
use Gereq\Tests\Support\PassthroughTranslator;
use Gereq\Testing\TestCase;
use Modules\Reviews\Application\ReviewsProvider;
use Modules\Reviews\ReviewsModule;
use Psr\Container\ContainerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class CmsSourceTest extends TestCase
{
    public function testBootRegistersSourceOnlyWhenCmsIsEnabled(): void
    {
        foreach ([false, true] as $enabled) {
            $modules = $this->createStub(EnabledModulesProvider::class);
            $modules->method('enabledIds')->willReturn($enabled ? ['cms'] : []);
            $cms = new Cms($modules);
            $em = $this->createStub(EntityManagerInterface::class);
            $translator = new PassthroughTranslator();
            $services = [
                Cms::class => $cms,
                ReviewsProvider::class => new ReviewsProvider($em),
                EntityManagerInterface::class => $em,
                Environment::class => new Environment(new ArrayLoader()),
                TranslatorInterface::class => $translator,
                Router::class => new Router(),
            ];
            $container = $this->createStub(ContainerInterface::class);
            $container->method('get')->willReturnCallback(static fn (string $id): mixed => $services[$id]);
            $reflection = new \ReflectionClass(ModuleContext::class);
            $context = $reflection->newInstanceWithoutConstructor();
            $dashboard = (new \ReflectionClass(Dashboard::class))->newInstanceWithoutConstructor();
            foreach (['container' => $container, 'dashboard' => $dashboard, 'menu' => new Menu($translator), 'commandPalette' => new CommandPalette()] as $key => $value) {
                $reflection->getProperty($key)->setValue($context, $value);
            }
            (new ReviewsModule())->boot($context);
            self::assertCount($enabled ? 1 : 0, $cms->catalog());
            if ($enabled) {
                self::assertSame('reviews', $cms->catalog()[0]['id']);
                self::assertContains('rating', $cms->catalog()[0]['fields']);
                self::assertNotContains('email', $cms->catalog()[0]['fields']);
            }
        }
    }
}
