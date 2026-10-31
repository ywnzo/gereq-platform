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
use Modules\Cms\Publish\CmsLinkPublishAdapter;
use Modules\Cms\Publish\CmsMenuPublishAdapter;
use Modules\Cms\Publish\CmsPagePublishAdapter;
use Gereq\Setting\SettingRecord;
use Gereq\Hook\HookPriority;
use Modules\Cms\Application\CmsProvider;
use Modules\Cms\Application\CmsSsgBodyInjector;
use Modules\Cms\Domain\NavLink;
use Modules\Cms\Domain\Page;
use Symfony\Component\HttpFoundation\Request;
use Modules\Cms\Http\Api\BlockApiController;
use Modules\Cms\Http\Api\PageApiController;
use Modules\Cms\Http\BlockController;
use Modules\Cms\Http\LinkController;
use Modules\Cms\Http\PageController;
use Modules\Cms\Http\PartialController;
use Modules\Cms\Http\Public\PublicCmsController;
use Modules\Cms\Application\Widgets\CmsShortcutWidget;
use Modules\Cms\Application\Widgets\CmsStatsWidget;
use Modules\Cms\Application\Widgets\RecentPagesWidget;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CmsModule implements ModuleInterface
{
    public function id(): string
    {
        return 'cms';
    }

    public function version(): string
    {
        return '2.65.1';
    }

    public function register(ModuleContext $context): void
    {
        $context->validation()->ruleset('cms.storefront.settings', [
            'home_page_id' => 'nullable|max:36',
            'cms__custom_css_public' => 'nullable',
        ]);
        $context->validation()->ruleset('cms.storefront.theme', [
            'mode' => 'required|in:light,dark',
        ]);
        $context->validation()->ruleset('cms.public_css.save', [
            'cms__custom_css_public' => 'nullable',
        ]);
        $context->permissions()->register('cms.view', 'perm.cms.view', 'perm.group.cms');
        $context->permissions()->register('cms.manage', 'perm.cms.manage', 'perm.group.cms');

        $provider = $context->get(CmsProvider::class);
        $context->capabilities()->register('cms', $this->id(), $provider);

        // Edited in Brand / CMS pages UI — not the global Settings form.
        $context->settings()->define('cms.site_title', SettingRecord::TYPE_STRING, 'cms.settings.site_title', 'GEREQ Site', $this->id(), [], false);
        $context->settings()->define('cms.home_page_id', SettingRecord::TYPE_STRING, 'cms.settings.home_page_id', '', $this->id(), [], false);
        $context->settings()->define(
            'cms.custom_css_public',
            SettingRecord::TYPE_STRING,
            'cms.settings.custom_css_public',
            "/* Veřejný web (FE) */\n:root {\n  /* --color-brand-700: #17375a; */\n}\n",
            $this->id(),
            [],
            false,
        );

        $context->router()->group('/admin/cms/forms', function (Router $routes): void {
            $routes->post('/uploads/{id}', [\Modules\Cms\Http\FormController::class, 'downloadFormUpload'])->name('admin.cms.forms.download.confirm');
            $routes->post('/uploads/{id}/scan/retry', [\Modules\Cms\Http\FormController::class, 'retryUploadScan'])->name('admin.cms.forms.scan.retry')->permission('cms.manage');
            $routes->get('/uploads/{id}', [\Modules\Cms\Http\FormController::class, 'downloadFormUpload'])->name('admin.cms.forms.download');
            $routes->post('/attempts/{id}/retry', [\Modules\Cms\Http\FormController::class, 'retryFormNotification'])->name('admin.cms.forms.retry')->permission('cms.manage');
            $routes->post('/test', [\Modules\Cms\Http\FormController::class, 'testForm'])->name('admin.cms.forms.test')->permission('cms.manage');
            $routes->get('/attempts', [\Modules\Cms\Http\FormController::class, 'formAttempts'])->name('admin.cms.forms.attempts')->permission('cms.manage');
        }, ['middleware' => ['auth']]);

        $context->router()->group('/admin/cms/pages', function (Router $routes): void {
            $routes->get('', [PageController::class, 'index'])->name('admin.cms.pages.index')->permission('cms.view');
            $routes->get('/create', [PageController::class, 'create'])->name('admin.cms.pages.create')->permission('cms.manage');
            $routes->get('/builder', [PageController::class, 'sandboxBuilder'])->name('admin.cms.pages.builder.sandbox')->permission('cms.manage');
            $routes->post('/settings/storefront', [PageController::class, 'saveStorefrontSettings'])->name('admin.cms.pages.settings.storefront')->permission('cms.manage');
            $routes->post('/settings/theme', [PageController::class, 'saveStorefrontTheme'])->name('admin.cms.pages.settings.theme')->permission('settings.manage');
            $routes->post('/settings/css', [PageController::class, 'savePublicCss'])->name('admin.cms.pages.settings.css')->permission('cms.manage');
            $routes->post('', [PageController::class, 'store'])->name('admin.cms.pages.store')->permission('cms.manage');
            $routes->get('/{id}/edit', [PageController::class, 'edit'])->name('admin.cms.pages.edit')->permission('cms.manage');
            $routes->post('/{id}', [PageController::class, 'update'])->name('admin.cms.pages.update')->permission('cms.manage');
            $routes->post('/{id}/publish', [PageController::class, 'publish'])->name('admin.cms.pages.publish')->permission('cms.manage');
            $routes->post('/{id}/home', [PageController::class, 'setHome'])->name('admin.cms.pages.home')->permission('cms.manage');
            $routes->get('/{id}/builder', [PageController::class, 'builder'])->name('admin.cms.pages.builder')->permission('cms.manage');
            $routes->post('/{id}/builder', [PageController::class, 'builderSave'])->name('admin.cms.pages.builder.save')->permission('cms.manage');
            $routes->get('/{id}/preview', [PageController::class, 'preview'])->name('admin.cms.pages.preview')->permission('cms.view');
            $routes->get('/{id}/preview.json', [PageController::class, 'previewJson'])->name('admin.cms.pages.preview_json')->permission('cms.view');
            $routes->post('/{id}/delete', [PageController::class, 'destroy'])->name('admin.cms.pages.destroy')->permission('cms.manage');
            $routes->get('/{id}', [PageController::class, 'show'])->name('admin.cms.pages.show')->permission('cms.view');

            $routes->post('/{id}/blocks', [BlockController::class, 'store'])->name('admin.cms.blocks.store')->permission('cms.manage');
            $routes->get('/{id}/blocks/{blockId}/edit', [BlockController::class, 'edit'])->name('admin.cms.blocks.edit')->permission('cms.manage');
            $routes->post('/{id}/blocks/{blockId}', [BlockController::class, 'update'])->name('admin.cms.blocks.update')->permission('cms.manage');
            $routes->post('/{id}/blocks/{blockId}/up', [BlockController::class, 'moveUp'])->name('admin.cms.blocks.up')->permission('cms.manage');
            $routes->post('/{id}/blocks/{blockId}/down', [BlockController::class, 'moveDown'])->name('admin.cms.blocks.down')->permission('cms.manage');
            $routes->post('/{id}/blocks/{blockId}/delete', [BlockController::class, 'destroy'])->name('admin.cms.blocks.destroy')->permission('cms.manage');
        }, ['middleware' => ['auth']]);

        $context->router()->group('/admin/cms/partials', function (Router $routes): void {
            $routes->get('', [PartialController::class, 'index'])->name('admin.cms.partials.index')->permission('cms.view');
            $routes->post('', [PartialController::class, 'store'])->name('admin.cms.partials.store')->permission('cms.manage');
            $routes->post('/{id}', [PartialController::class, 'update'])->name('admin.cms.partials.update')->permission('cms.manage');
            $routes->post('/{id}/delete', [PartialController::class, 'destroy'])->name('admin.cms.partials.destroy')->permission('cms.manage');
        }, ['middleware' => ['auth']]);

        $context->router()->group('/admin/cms/links', function (Router $routes): void {
            $routes->get('', [LinkController::class, 'index'])->name('admin.cms.links.index')->permission('cms.view');
            $routes->get('/create', [LinkController::class, 'create'])->name('admin.cms.links.create')->permission('cms.manage');
            $routes->post('', [LinkController::class, 'store'])->name('admin.cms.links.store')->permission('cms.manage');
            $routes->get('/{id}/edit', [LinkController::class, 'edit'])->name('admin.cms.links.edit')->permission('cms.manage');
            $routes->post('/{id}', [LinkController::class, 'update'])->name('admin.cms.links.update')->permission('cms.manage');
            $routes->post('/{id}/publish', [LinkController::class, 'publish'])->name('admin.cms.links.publish')->permission('cms.manage');
            $routes->post('/{id}/delete', [LinkController::class, 'destroy'])->name('admin.cms.links.destroy')->permission('cms.manage');
        }, ['middleware' => ['auth']]);

        // Public storefront: JSON API + optional SSG body inject into SPA shell (ssg=true pages).
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

        $context->router()->add('/api/public/pages/{slug}/forms/{formId}', ['OPTIONS'], [\Modules\Cms\Http\Public\PublicFormController::class, 'options'])->name('api.public.cms.forms.options');
        $context->router()->post('/api/public/pages/{slug}/forms/{formId}', [\Modules\Cms\Http\Public\PublicFormController::class, 'submit'])->name('api.public.cms.forms.submit');

        $this->registerApi($context);
        $this->registerPlatformEvents($context);
        $this->registerValidation($context);

        $context->publishing($this->id())
            ->category('cms.pages')
            ->labelKey('publish.category.cms_pages')
            ->sort(20)
            ->adapter(CmsPagePublishAdapter::class)
            ->category('cms.links')
            ->labelKey('publish.category.cms_links')
            ->sort(25)
            ->adapter(CmsLinkPublishAdapter::class)
            ->category('cms.menus')
            ->labelKey('publish.category.cms_menus')
            ->sort(30)
            ->adapter(CmsMenuPublishAdapter::class);
    }

    public function boot(ModuleContext $context): void
    {
        $context->cms()->registerFormDeliveryUpdater(static function (string $action, string $recordId, string $status) use ($context): void {
            $context->get(\Modules\Cms\Application\FormSubmissionLog::class)->updateDelivery($action, $recordId, $status);
        });
        $context->schedule()->call(static fn () => $context->get(\Modules\Cms\Application\FormUploadScanner::class)->process())
            ->everyMinute()->name('cms.forms.scan')->description('Scan private form attachments and retry unavailable scanners.')->withoutOverlapping(2);
        $context->cms()->registerAttachmentStorage($context->get(\Modules\Cms\Application\FormUploads::class));
        $context->schedule()->call(static fn () => $context->get(\Modules\Cms\Application\FormSubmissionLog::class)->prune(true))
            ->dailyAt('03:40')
            ->timezone('UTC')
            ->name('cms.forms.retention')
            ->description('Purge expired form submission metadata and private attachments.')
            ->withoutOverlapping(60);

        $ssgInjector = $context->get(CmsSsgBodyInjector::class);
        // After Web+ head inject (EARLY): fill #app for pages with publishing.ssg.
        $context->hooks()->addFilter(
            'storefront.spa_html',
            static function (string $html, mixed $request = null) use ($ssgInjector): string {
                if (!$request instanceof Request) {
                    return $html;
                }

                return $ssgInjector->inject($html, $request);
            },
            HookPriority::LATE,
        );

        $context->dashboard()->addWidget(new CmsShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->dashboard()->addWidget(new CmsStatsWidget(
            $context->get(Environment::class),
            $context->get(EntityManagerInterface::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->dashboard()->addWidget(new RecentPagesWidget(
            $context->get(Environment::class),
            $context->get(EntityManagerInterface::class),
            $context->get(\Gereq\Auth\Authenticator::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->menu()->add(new MenuItem(
            id: 'cms',
            label: 'nav.cms',
            icon: 'book',
            route: 'admin.cms.pages.index',
            permission: 'cms.view',
            order: 50,
            group: 'Obsah',
        ));
        $context->menu()->add(new MenuItem(
            id: 'cms.pages',
            label: 'nav.cms.pages',
            route: 'admin.cms.pages.index',
            permission: 'cms.view',
            parent: 'cms',
            order: 10,
        ));
        $context->menu()->add(new MenuItem(
            id: 'cms.forms',
            label: 'nav.cms.forms',
            route: 'admin.cms.forms.attempts',
            permission: 'cms.manage',
            parent: 'cms',
            order: 40,
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'cms.forms',
            label: 'nav.cms.forms',
            url: '/admin/cms/forms/attempts',
            icon: 'inbox',
            permission: 'cms.manage',
            keywords: ['cms', 'forms', 'submissions', 'formuláře', 'odeslání'],
        ));
        $context->menu()->add(new MenuItem(
            id: 'cms.links',
            label: 'nav.cms.links',
            route: 'admin.cms.links.index',
            permission: 'cms.view',
            parent: 'cms',
            order: 30,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'cms.pages',
            label: 'palette.cms.pages',
            url: '/admin/cms/pages',
            icon: 'book',
            permission: 'cms.view',
            keywords: ['cms', 'stránky', 'pages', 'obsah', 'content'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'cms.links',
            label: 'palette.cms.links',
            url: '/admin/cms/links',
            icon: 'link',
            permission: 'cms.view',
            keywords: ['odkazy', 'links', 'menu', 'navigace', 'cms'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'cms.page.create',
            label: 'palette.cms.page.create',
            url: '/admin/cms/pages/create',
            icon: 'plus',
            permission: 'cms.manage',
            group: 'Akce',
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'cms.link.create',
            label: 'palette.cms.link.create',
            url: '/admin/cms/links/create',
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
                new EventDefinition($type, $this->id())
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

        $validation->rule('cms_dynamic_source', static function (mixed $value) use ($context): ?string {
            $ids = array_column($context->cms()->catalog(), 'id');
            return is_string($value) && in_array($value, $ids, true)
                ? null
                : $context->get(TranslatorInterface::class)->trans('cms.dynamic.invalid_source');
        });
        $validation->ruleset('cms.dynamic_template.save', ['source' => 'required|cms_dynamic_source']);

        \Modules\Cms\Application\FormSchema::registerValidation($validation, $context->get(TranslatorInterface::class));

        $validation->ruleset('cms.page.save', [
            'slug' => 'required|min:1|max:180|cms_slug_available',
            'title' => 'required|min:1|max:190',
            'status' => 'required|in:draft,published,archived',
            'template' => 'required|in:default,landing',
            'content_mode' => 'required|in:blocks,raw',
            'body' => 'nullable|max:200000',
            'meta_description' => 'nullable|max:2000',
            'ssg' => 'nullable|boolean',
        ]);
        $context->forms()->entity('cms.page.save', Page::class);

        $validation->ruleset('cms.link.save', [
            'title' => 'required|min:1|max:190',
            'url' => 'required|min:1|max:2048',
            'status' => 'required|in:draft,published,archived',
        ]);
        $context->forms()->entity('cms.link.save', NavLink::class);

        $validation->ruleset('cms.block.save', [
            'type' => 'required|in:heading,text,image,html,cta,divider,dynamic,rating,avatar,badge,widget,products,gallery,quote,video,spacer,form,form_field,icon,table',
            'payload' => 'nullable',
            'action' => 'nullable|max:120',
            'schema_version' => 'nullable|integer',
            'children' => 'nullable',
            'columns' => 'nullable|integer|in:1,2',
            'error_summary' => 'nullable|integer|in:0,1',
            'submit_label' => 'nullable|max:190',
            'success_message' => 'nullable|max:1000',
            'item_blocks' => 'nullable',
            'value' => 'nullable|max:100',
            'source' => 'nullable|max:120',
            'limit' => 'nullable|integer|min:1|max:100',
            'item_template' => 'nullable|max:50000',
            'empty_text' => 'nullable|max:500',
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
            'name' => 'nullable|max:64',
            'control' => 'nullable|in:text,email,tel,date,textarea,select,checkbox,radio,consent,file',
            'placeholder' => 'nullable|max:255',
            'required' => 'nullable|in:0,1',
            'options' => 'nullable|max:5000',
            'options_layout' => 'nullable|in:vertical,horizontal',
            'full' => 'nullable|in:0,1',
            'left' => 'nullable|max:20000',
            'right' => 'nullable|max:20000',
            'product_ids' => 'nullable|max:2000',
            'media_ids' => 'nullable|max:2000',
            'variant' => 'nullable|in:list,grid,grid-3,masonry,mosaic,spotlight,carousel,carousel-peek,filmstrip',
            'film_speed' => 'nullable|integer|min:8|max:180',
            'quote' => 'nullable|max:5000',
            'author' => 'nullable|max:190',
            'role' => 'nullable|max:190',
            'size' => 'nullable|in:sm,md,lg,xl,8,16,24,32,40,48,56,64',
            'icon_style' => 'nullable|in:outlined,rounded,sharp',
            'fill' => 'nullable|in:0,1',
            'weight' => 'nullable|integer|min:100|max:700',
            'grade' => 'nullable|integer|min:-50|max:200',
            'opsz' => 'nullable|integer|min:20|max:48',
            'spacing' => 'nullable|in:tight,normal,loose',
            'align' => 'nullable|in:start,center,end',
            'z_index' => 'nullable|integer',
            'img_w' => 'nullable|in:auto,25,33,50,66,75,100',
            'img_h' => 'nullable|in:auto,sm,md,lg,xl,square',
            'img_fit' => 'nullable|in:cover,contain,fill',
            'width' => 'nullable|in:auto,25,33,50,66,75,100',
            'height' => 'nullable|in:auto,sm,md,lg,xl,square',
            'ratio' => 'nullable|in:,1/1,4/3,3/2,16/9,21/9',
            'line' => 'nullable|in:solid,dashed,dotted',
            'thickness' => 'nullable|integer',
            'visible' => 'nullable|in:0,1',
            'opacity' => 'nullable|integer',
            'bg_color' => 'nullable|max:32',
            'text_color' => 'nullable|max:32',
            'border_w' => 'nullable|integer',
            'border_style' => 'nullable|in:solid,dashed,dotted',
            'border_color' => 'nullable|max:32',
            'shadow' => 'nullable|in:none,soft,strong',
            'radius' => 'nullable|integer',
            'padding' => 'nullable|integer',
            'margin' => 'nullable|integer|min:0|max:256',
            'margin_top' => 'nullable|integer|min:0|max:256',
            'margin_right' => 'nullable|integer|min:0|max:256',
            'margin_bottom' => 'nullable|integer|min:0|max:256',
            'margin_left' => 'nullable|integer|min:0|max:256',
            'padding_top' => 'nullable|integer|min:0|max:512',
            'padding_right' => 'nullable|integer|min:0|max:512',
            'padding_bottom' => 'nullable|integer|min:0|max:512',
            'padding_left' => 'nullable|integer|min:0|max:512',
            'font_family' => 'nullable|in:inherit,sans,display,serif,mono',
            'font_size' => 'nullable|in:inherit,sm,md,lg,xl,2xl,3xl',
            'font_weight' => 'nullable|in:inherit,normal,medium,semibold,bold',
        ]);

        $validation->ruleset('cms.menu.save', [
            'name' => 'required|min:1|max:120',
            'location' => 'required|in:header,footer',
        ]);
        $context->forms()->entity('cms.menu.save', \Modules\Cms\Domain\SiteMenu::class);

        $validation->ruleset('cms.menu_item.save', [
            'label' => 'required|min:1|max:190',
            'url' => 'nullable|max:2048',
            'page_id' => 'nullable|max:36',
            'parent_id' => 'nullable|max:36',
            'sort_order' => 'nullable|integer',
        ]);
    }
}
