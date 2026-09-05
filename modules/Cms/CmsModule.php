<?php

declare(strict_types=1);

namespace Modules\Cms;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Modules\Cms\Application\CmsProvider;
use Modules\Cms\Domain\Page;
use Modules\Cms\Http\Api\BlockApiController;
use Modules\Cms\Http\Api\PageApiController;
use Modules\Cms\Http\BlockController;
use Modules\Cms\Http\MenuController;
use Modules\Cms\Http\PageController;
use Modules\Cms\Http\Public\PublicCmsController;

final class CmsModule implements ModuleInterface
{
    public function id(): string
    {
        return 'cms';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('cms.view', 'perm.cms.view', 'perm.group.cms');
        $context->permissions()->register('cms.manage', 'perm.cms.manage', 'perm.group.cms');

        $provider = $context->get(CmsProvider::class);
        $context->capabilities()->register('cms', $this->id(), $provider);

        $context->settings()->define('cms.site_title', SettingRecord::TYPE_STRING, 'cms.settings.site_title', 'GEREQ Site', $this->id());
        $context->settings()->define('cms.home_page_id', SettingRecord::TYPE_STRING, 'cms.settings.home_page_id', '', $this->id());

        $context->router()->group('/admin/cms/pages', function (Router $routes): void {
            $routes->get('', [PageController::class, 'index'])->name('admin.cms.pages.index')->permission('cms.view');
            $routes->get('/create', [PageController::class, 'create'])->name('admin.cms.pages.create')->permission('cms.manage');
            $routes->post('', [PageController::class, 'store'])->name('admin.cms.pages.store')->permission('cms.manage');
            $routes->get('/{id}', [PageController::class, 'show'])->name('admin.cms.pages.show')->permission('cms.view');
            $routes->get('/{id}/edit', [PageController::class, 'edit'])->name('admin.cms.pages.edit')->permission('cms.manage');
            $routes->post('/{id}', [PageController::class, 'update'])->name('admin.cms.pages.update')->permission('cms.manage');
            $routes->post('/{id}/publish', [PageController::class, 'publish'])->name('admin.cms.pages.publish')->permission('cms.manage');
            $routes->get('/{id}/preview', [PageController::class, 'preview'])->name('admin.cms.pages.preview')->permission('cms.view');
            $routes->post('/{id}/delete', [PageController::class, 'destroy'])->name('admin.cms.pages.destroy')->permission('cms.manage');

            $routes->post('/{id}/blocks', [BlockController::class, 'store'])->name('admin.cms.blocks.store')->permission('cms.manage');
            $routes->get('/{id}/blocks/{blockId}/edit', [BlockController::class, 'edit'])->name('admin.cms.blocks.edit')->permission('cms.manage');
            $routes->post('/{id}/blocks/{blockId}', [BlockController::class, 'update'])->name('admin.cms.blocks.update')->permission('cms.manage');
            $routes->post('/{id}/blocks/{blockId}/up', [BlockController::class, 'moveUp'])->name('admin.cms.blocks.up')->permission('cms.manage');
            $routes->post('/{id}/blocks/{blockId}/down', [BlockController::class, 'moveDown'])->name('admin.cms.blocks.down')->permission('cms.manage');
            $routes->post('/{id}/blocks/{blockId}/delete', [BlockController::class, 'destroy'])->name('admin.cms.blocks.destroy')->permission('cms.manage');
        }, ['middleware' => ['auth']]);

        $context->router()->group('/admin/cms/menus', function (Router $routes): void {
            $routes->get('', [MenuController::class, 'index'])->name('admin.cms.menus.index')->permission('cms.view');
            $routes->get('/create', [MenuController::class, 'create'])->name('admin.cms.menus.create')->permission('cms.manage');
            $routes->post('', [MenuController::class, 'store'])->name('admin.cms.menus.store')->permission('cms.manage');
            $routes->get('/{id}', [MenuController::class, 'show'])->name('admin.cms.menus.show')->permission('cms.view');
            $routes->get('/{id}/edit', [MenuController::class, 'edit'])->name('admin.cms.menus.edit')->permission('cms.manage');
            $routes->post('/{id}', [MenuController::class, 'update'])->name('admin.cms.menus.update')->permission('cms.manage');
            $routes->post('/{id}/delete', [MenuController::class, 'destroy'])->name('admin.cms.menus.destroy')->permission('cms.manage');
            $routes->post('/{id}/items', [MenuController::class, 'storeItem'])->name('admin.cms.menus.items.store')->permission('cms.manage');
            $routes->post('/{id}/items/{itemId}', [MenuController::class, 'updateItem'])->name('admin.cms.menus.items.update')->permission('cms.manage');
            $routes->post('/{id}/items/{itemId}/delete', [MenuController::class, 'destroyItem'])->name('admin.cms.menus.items.destroy')->permission('cms.manage');
        }, ['middleware' => ['auth']]);

        // Public storefront: JSON only — React FE renders blocks, CMS never outputs HTML.
        $cmsPublic = [PublicCmsController::class, 'options'];
        $context->router()->add('/api/public/pages', ['OPTIONS'], $cmsPublic)->name('api.public.cms.pages.options');
        $context->router()->add('/api/public/pages/{slug}', ['OPTIONS'], $cmsPublic)->name('api.public.cms.pages.slug.options');
        $context->router()->add('/api/public/menus', ['OPTIONS'], $cmsPublic)->name('api.public.cms.menus.options');
        $context->router()->add('/api/public/menus/{location}', ['OPTIONS'], $cmsPublic)->name('api.public.cms.menus.location.options');

        $context->router()->get('/api/public/pages', [PublicCmsController::class, 'pages'])
            ->name('api.public.cms.pages.index');
        $context->router()->get('/api/public/pages/{slug}', [PublicCmsController::class, 'page'])
            ->name('api.public.cms.pages.show');
        $context->router()->get('/api/public/menus', [PublicCmsController::class, 'menus'])
            ->name('api.public.cms.menus.index');
        $context->router()->get('/api/public/menus/{location}', [PublicCmsController::class, 'menu'])
            ->name('api.public.cms.menus.show');

        $this->registerApi($context);
        $this->registerPlatformEvents($context);
        $this->registerValidation($context);
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'cms',
            label: 'nav.cms',
            icon: 'layout',
            route: 'admin.cms.pages.index',
            permission: 'cms.view',
            order: 52,
            group: 'Obsah',
        ));
        $context->menu()->add(new MenuItem(
            id: 'cms.pages',
            label: 'nav.cms.pages',
            route: 'admin.cms.pages.index',
            permission: 'cms.view',
            parent: 'cms',
            order: 53,
        ));
        $context->menu()->add(new MenuItem(
            id: 'cms.menus',
            label: 'nav.cms.menus',
            route: 'admin.cms.menus.index',
            permission: 'cms.view',
            parent: 'cms',
            order: 54,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'cms.pages',
            label: 'palette.cms.pages',
            url: '/admin/cms/pages',
            icon: 'layout',
            permission: 'cms.view',
            keywords: ['cms', 'stránky', 'pages', 'obsah', 'content'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'cms.menus',
            label: 'palette.cms.menus',
            url: '/admin/cms/menus',
            icon: 'layout',
            permission: 'cms.view',
            keywords: ['menu', 'navigace', 'cms'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'cms.page.create',
            label: 'palette.cms.page.create',
            url: '/admin/cms/pages/create',
            icon: 'plus',
            permission: 'cms.manage',
            group: 'Akce',
        ));
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->registerKnownScope('cms.read');
        $context->api()->registerKnownScope('cms.write');

        $context->api()->module('pages', function (ApiModule $api): void {
            $api->get('/', [PageApiController::class, 'index'])->scope('cms.read');
            $api->post('/', [PageApiController::class, 'store'])->scope('cms.write');
            $api->get('/{id}', [PageApiController::class, 'show'])->scope('cms.read');
            $api->patch('/{id}', [PageApiController::class, 'update'])->scope('cms.write');
            $api->post('/{id}/publish', [PageApiController::class, 'publish'])->scope('cms.write');
            $api->delete('/{id}', [PageApiController::class, 'destroy'])->scope('cms.write');

            $api->get('/{id}/blocks', [BlockApiController::class, 'index'])->scope('cms.read');
            $api->post('/{id}/blocks', [BlockApiController::class, 'store'])->scope('cms.write');
            $api->patch('/{id}/blocks/{blockId}', [BlockApiController::class, 'update'])->scope('cms.write');
            $api->delete('/{id}/blocks/{blockId}', [BlockApiController::class, 'destroy'])->scope('cms.write');
        });
    }

    private function registerPlatformEvents(ModuleContext $context): void
    {
        $events = [
            'page.published' => 'Stránka publikována',
            'page.updated' => 'Stránka upravena',
            'block.created' => 'Blok stránky vytvořen',
            'block.updated' => 'Blok stránky upraven',
            'block.deleted' => 'Blok stránky smazán',
        ];

        foreach ($events as $type => $description) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))
                    ->description($description)
                    ->webhookEnabled(true)
            );
        }
    }

    private function registerValidation(ModuleContext $context): void
    {
        $em = $context->get(EntityManagerInterface::class);
        $validation = $context->validation();

        $validation->rule(
            'cms_slug_available',
            static function (mixed $value, array $params, array $data, array $ctx) use ($em): ?string {
                if (!is_string($value) || $value === '') {
                    return null;
                }
                $slug = strtolower(trim($value));
                $slug = preg_replace('~[^a-z0-9]+~', '-', $slug) ?? $slug;
                $slug = trim($slug, '-');
                $found = $em->getRepository(Page::class)->findOneBy(['slug' => $slug !== '' ? $slug : 'page']);
                if ($found instanceof Page && $found->id() !== ($ctx['ignore_id'] ?? null)) {
                    return 'Stránka s tímto slugem už existuje.';
                }

                return null;
            }
        );

        $validation->ruleset('cms.page.save', [
            'slug' => 'required|min:1|max:180|cms_slug_available',
            'title' => 'required|min:1|max:190',
            'status' => 'required|in:draft,published,archived',
            'template' => 'required|in:default,landing',
            'meta_description' => 'nullable|max:2000',
        ]);

        $validation->ruleset('cms.block.save', [
            'type' => 'required|in:heading,text,image,html,cta,columns,divider,products,gallery',
            'text' => 'nullable|max:20000',
            'level' => 'nullable|in:1,2,3',
            'body' => 'nullable|max:50000',
            'html' => 'nullable|max:50000',
            'media_id' => 'nullable|max:36',
            'url' => 'nullable|max:2048',
            'alt' => 'nullable|max:255',
            'caption' => 'nullable|max:500',
            'label' => 'nullable|max:190',
            'style' => 'nullable|max:64',
            'left' => 'nullable|max:20000',
            'right' => 'nullable|max:20000',
            'product_ids' => 'nullable|max:2000',
            'media_ids' => 'nullable|max:2000',
        ]);

        $validation->ruleset('cms.menu.save', [
            'name' => 'required|min:1|max:120',
            'location' => 'required|in:header,footer',
        ]);

        $validation->ruleset('cms.menu_item.save', [
            'label' => 'required|min:1|max:190',
            'url' => 'nullable|max:2048',
            'page_id' => 'nullable|max:36',
            'parent_id' => 'nullable|max:36',
            'sort_order' => 'nullable|integer',
        ]);
    }
}
