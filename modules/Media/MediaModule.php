<?php

declare(strict_types=1);

namespace Modules\Media;

use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Media\Application\MediaProvider;
use Modules\Media\Http\Api\MediaApiController;
use Modules\Media\Http\MediaController;
use Modules\Media\Application\Widgets\MediaShortcutWidget;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class MediaModule implements ModuleInterface
{
    public function id(): string
    {
        return 'media';
    }

    public function version(): string
    {
        return '1.2.4';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('media.view', 'perm.media.view', 'perm.group.media');
        $context->permissions()->register('media.manage', 'perm.media.manage', 'perm.group.media');

        $provider = $context->get(MediaProvider::class);
        $context->capabilities()->register('media', $this->id(), $provider);

        $context->router()->group('/admin/media', function (Router $routes): void {
            $routes->get('', [MediaController::class, 'index'])
                ->name('admin.media.index')
                ->permission('media.view');
            $routes->get('/upload', [MediaController::class, 'create'])
                ->name('admin.media.upload')
                ->permission('media.manage');
            $routes->post('', [MediaController::class, 'store'])
                ->name('admin.media.store')
                ->permission('media.manage');
            $routes->post('/bulk', [MediaController::class, 'bulk'])
                ->name('admin.media.bulk')
                ->permission('media.manage');

            $routes->get('/folders/create', [MediaController::class, 'createFolder'])
                ->name('admin.media.folders.create')
                ->permission('media.manage');
            $routes->post('/folders', [MediaController::class, 'storeFolder'])
                ->name('admin.media.folders.store')
                ->permission('media.manage');
            $routes->get('/folders/{id}/edit', [MediaController::class, 'editFolder'])
                ->name('admin.media.folders.edit')
                ->permission('media.manage');
            $routes->post('/folders/{id}', [MediaController::class, 'updateFolder'])
                ->name('admin.media.folders.update')
                ->permission('media.manage');
            $routes->get('/folders/{id}/move', [MediaController::class, 'moveFolderForm'])
                ->name('admin.media.folders.move')
                ->permission('media.manage');
            $routes->post('/folders/{id}/move', [MediaController::class, 'moveFolder'])
                ->name('admin.media.folders.move.submit')
                ->permission('media.manage');
            $routes->post('/folders/{id}/delete', [MediaController::class, 'destroyFolder'])
                ->name('admin.media.folders.destroy')
                ->permission('media.manage');

            $routes->get('/{id}', [MediaController::class, 'show'])
                ->name('admin.media.show')
                ->permission('media.view');
            $routes->get('/{id}/edit', [MediaController::class, 'edit'])
                ->name('admin.media.edit')
                ->permission('media.manage');
            $routes->post('/{id}', [MediaController::class, 'update'])
                ->name('admin.media.update')
                ->permission('media.manage');
            $routes->get('/{id}/move', [MediaController::class, 'moveForm'])
                ->name('admin.media.move')
                ->permission('media.manage');
            $routes->post('/{id}/move', [MediaController::class, 'move'])
                ->name('admin.media.move.submit')
                ->permission('media.manage');
            $routes->get('/{id}/file', [MediaController::class, 'file'])
                ->name('admin.media.file')
                ->permission('media.view');
            $routes->post('/{id}/delete', [MediaController::class, 'destroy'])
                ->name('admin.media.destroy')
                ->permission('media.manage');
        }, ['middleware' => ['auth']]);

        $context->router()->get('/media/{id}/file', [MediaController::class, 'publicFile'])
            ->name('media.public.file');

        $this->registerApi($context);
        $this->registerPlatformEvents($context);
        $this->registerValidation($context);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new MediaShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->menu()->add(new MenuItem(
            id: 'media',
            label: 'nav.media',
            icon: 'image',
            route: 'admin.media.index',
            permission: 'media.view',
            order: 50,
            group: 'Obsah',
        ));
        $context->menu()->add(new MenuItem(
            id: 'media.upload',
            label: 'nav.media.upload',
            route: 'admin.media.upload',
            permission: 'media.manage',
            parent: 'media',
            order: 51,
            hidden: true,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'media.index',
            label: 'palette.media.index',
            url: '/admin/media',
            icon: 'image',
            permission: 'media.view',
            keywords: ['média', 'media', 'obrázky', 'assets', 'galerie', 'složky'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'media.upload',
            label: 'palette.media.upload',
            url: '/admin/media/upload',
            icon: 'plus',
            permission: 'media.manage',
            group: 'Akce',
        ));
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->registerKnownScope('media.read');
        $context->api()->registerKnownScope('media.write');

        $context->api()->module('media', function (ApiModule $api): void {
            $api->get('/', [MediaApiController::class, 'index'])->scope('media.read');
            $api->post('/', [MediaApiController::class, 'store'])->scope('media.write');
            $api->get('/{id}', [MediaApiController::class, 'show'])->scope('media.read');
            $api->patch('/{id}', [MediaApiController::class, 'update'])->scope('media.write');
            $api->delete('/{id}', [MediaApiController::class, 'destroy'])->scope('media.write');
        });
    }

    private function registerPlatformEvents(ModuleContext $context): void
    {
        $events = [
            'media.uploaded' => 'Médiový soubor nahrán',
            'media.updated' => 'Médiový soubor upraven',
            'media.deleted' => 'Médiový soubor smazán',
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
        $validation = $context->validation();

        $validation->ruleset('media.save', [
            'title' => 'required|min:1|max:190',
            'alt' => 'nullable|max:255',
            'folder_id' => 'nullable|max:36',
        ]);
        $context->forms()->entity('media.save', \Modules\Media\Domain\MediaAsset::class);

        $validation->ruleset('media.upload', [
            'title' => 'nullable|max:190',
            'alt' => 'nullable|max:255',
            'folder_id' => 'nullable|max:36',
        ]);
        $context->forms()->entity('media.upload', \Modules\Media\Domain\MediaAsset::class);

        $validation->ruleset('media.move', [
            'folder_id' => 'nullable|max:36',
        ]);

        $validation->ruleset('media.bulk', [
            'action' => 'required|max:32',
            'folder_id' => 'nullable|max:36',
            'confirmed' => 'nullable',
        ]);

        $validation->ruleset('media.folder.save', [
            'name' => 'required|min:1|max:190',
            'parent_id' => 'nullable|max:36',
        ]);
        $context->forms()->entity('media.folder.save', \Modules\Media\Domain\MediaFolder::class);

        $validation->ruleset('media.folder.move', [
            'parent_id' => 'nullable|max:36',
        ]);
    }
}
