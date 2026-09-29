<?php

declare(strict_types=1);

namespace Gereq\Media;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Dashboard\Dashboard;
use Gereq\Admin\Form\Forms;
use Gereq\Admin\Menu\Menu;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Api\ApiRegistry;
use Gereq\Event\EventDefinition;
use Gereq\Event\EventRegistry;
use Gereq\Http\Router;
use Gereq\Media\Application\MediaProvider;
use Gereq\Media\Application\Widgets\MediaShortcutWidget;
use Gereq\Media\Application\Widgets\MediaStatsWidget;
use Gereq\Media\Application\Widgets\MediaUploadsChartWidget;
use Gereq\Media\Domain\MediaAsset;
use Gereq\Media\Domain\MediaFolder;
use Gereq\Media\Http\Api\MediaApiController;
use Gereq\Media\Http\MediaController;
use Gereq\Module\CapabilityRegistry;
use Gereq\User\PermissionRegistry;
use Gereq\Validation\ValidationRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Core registration for media library (formerly modules/Media).
 */
final class MediaRegistrar
{
    public function __construct(
        private readonly Router $router,
        private readonly Menu $menu,
        private readonly PermissionRegistry $permissions,
        private readonly CapabilityRegistry $capabilities,
        private readonly ApiRegistry $api,
        private readonly EventRegistry $platformEvents,
        private readonly Dashboard $dashboard,
        private readonly CommandPalette $palette,
        private readonly ValidationRegistry $validation,
        private readonly Forms $forms,
        private readonly EntityManagerInterface $em,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly MediaProvider $provider,
    ) {
    }

    public function register(): void
    {
        $this->permissions();
        $this->capabilities();
        $this->routes();
        $this->api();
        $this->platformEvents();
        $this->validation();
        $this->menu();
        $this->dashboard();
        $this->commands();
    }

    private function permissions(): void
    {
        $this->permissions->register('media.view', 'perm.media.view', 'perm.group.media');
        $this->permissions->register('media.manage', 'perm.media.manage', 'perm.group.media');
    }

    private function capabilities(): void
    {
        $this->capabilities->register('media', 'core', $this->provider);
    }

    private function routes(): void
    {
        $this->router->group('/admin/media', function (Router $routes): void {
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

        $this->router->get('/media/{id}/file', [MediaController::class, 'publicFile'])
            ->name('media.public.file');
    }

    private function api(): void
    {
        $this->api->registerKnownScope('media.read');
        $this->api->registerKnownScope('media.write');
        $this->api->module('media', function (ApiModule $api): void {
            $api->get('/', [MediaApiController::class, 'index'])->scope('media.read');
            $api->post('/', [MediaApiController::class, 'store'])->scope('media.write');
            $api->get('/{id}', [MediaApiController::class, 'show'])->scope('media.read');
            $api->patch('/{id}', [MediaApiController::class, 'update'])->scope('media.write');
            $api->delete('/{id}', [MediaApiController::class, 'destroy'])->scope('media.write');
        });
    }

    private function platformEvents(): void
    {
        foreach (
            [
                'media.uploaded' => 'Médiový soubor nahrán',
                'media.updated' => 'Médiový soubor upraven',
                'media.deleted' => 'Médiový soubor smazán',
            ] as $type => $description
        ) {
            $this->platformEvents->register(
                (new EventDefinition($type, 'core'))
                    ->description($description)
                    ->webhookEnabled(true)
            );
        }
    }

    private function validation(): void
    {
        $this->validation->ruleset('media.save', [
            'title' => 'required|min:1|max:190',
            'alt' => 'nullable|max:255',
            'folder_id' => 'nullable|max:36',
        ]);
        $this->forms->entity('media.save', MediaAsset::class);

        $this->validation->ruleset('media.upload', [
            'title' => 'nullable|max:190',
            'alt' => 'nullable|max:255',
            'folder_id' => 'nullable|max:36',
        ]);
        $this->forms->entity('media.upload', MediaAsset::class);

        $this->validation->ruleset('media.move', [
            'folder_id' => 'nullable|max:36',
        ]);

        $this->validation->ruleset('media.bulk', [
            'action' => 'required|max:32',
            'folder_id' => 'nullable|max:36',
            'confirmed' => 'nullable',
        ]);

        $this->validation->ruleset('media.folder.save', [
            'name' => 'required|min:1|max:190',
            'parent_id' => 'nullable|max:36',
        ]);
        $this->forms->entity('media.folder.save', MediaFolder::class);

        $this->validation->ruleset('media.folder.move', [
            'parent_id' => 'nullable|max:36',
        ]);
    }

    private function menu(): void
    {
        $this->menu->add(new MenuItem(
            id: 'media',
            label: 'nav.media',
            icon: 'image',
            route: 'admin.media.index',
            permission: 'media.view',
            order: 50,
            group: 'Obsah',
        ));
        $this->menu->add(new MenuItem(
            id: 'media.upload',
            label: 'nav.media.upload',
            route: 'admin.media.upload',
            permission: 'media.manage',
            parent: 'media',
            order: 51,
            hidden: true,
        ));
    }

    private function dashboard(): void
    {
        $this->dashboard->addWidget(new MediaShortcutWidget(
            $this->twig,
            $this->translator,
            $this->router,
        ));
        foreach ([MediaStatsWidget::class, MediaUploadsChartWidget::class] as $widgetClass) {
            $this->dashboard->addWidget(new $widgetClass(
                $this->twig,
                $this->em,
                $this->translator,
                $this->router,
            ));
        }
    }

    private function commands(): void
    {
        $this->palette->add(new PaletteCommand(
            id: 'media.index',
            label: 'palette.media.index',
            url: '/admin/media',
            icon: 'image',
            permission: 'media.view',
            keywords: ['média', 'media', 'obrázky', 'assets', 'galerie', 'složky'],
        ));
        $this->palette->add(new PaletteCommand(
            id: 'media.upload',
            label: 'palette.media.upload',
            url: '/admin/media/upload',
            icon: 'plus',
            permission: 'media.manage',
            group: 'Akce',
        ));
    }
}
