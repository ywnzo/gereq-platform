<?php

declare(strict_types=1);

namespace Modules\Translate;

use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Modules\Translate\Application\TranslateService;
use Modules\Translate\Http\SettingsController;

final class TranslateModule implements ModuleInterface
{
    public function id(): string
    {
        return 'translate';
    }

    public function version(): string
    {
        return '2.0.0';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('translate.view', 'perm.translate.view', 'perm.group.translate');
        $context->permissions()->register('translate.manage', 'perm.translate.manage', 'perm.group.translate');

        $context->capabilities()->register('translate', $this->id(), $context->get(TranslateService::class));

        $context->settings()->define('translate.ms_api_key', SettingRecord::TYPE_ENCRYPTED, 'translate.settings.api_key', '', $this->id());
        $context->settings()->define('translate.ms_region', SettingRecord::TYPE_STRING, 'translate.settings.region', '', $this->id());
        $context->settings()->define('translate.ms_endpoint', SettingRecord::TYPE_STRING, 'translate.settings.endpoint', '', $this->id());
        $context->settings()->define('translate.source_locale', SettingRecord::TYPE_STRING, 'translate.settings.source_locale', 'ru', $this->id());
        $context->settings()->define('translate.target_locale', SettingRecord::TYPE_STRING, 'translate.settings.target_locale', 'cs', $this->id());

        $context->router()->group('/admin/translate', function (Router $routes): void {
            $routes->get('/settings', [SettingsController::class, 'edit'])->name('admin.translate.settings')->permission('translate.view');
            $routes->post('/settings', [SettingsController::class, 'update'])->name('admin.translate.settings.update')->permission('translate.manage');
            $routes->post('/test', [SettingsController::class, 'test'])->name('admin.translate.test')->permission('translate.manage');
            $routes->post('/ping', [SettingsController::class, 'ping'])->name('admin.translate.ping')->permission('translate.manage');
            $routes->post('/cache/clear', [SettingsController::class, 'clearCache'])->name('admin.translate.cache.clear')->permission('translate.manage');
        }, ['middleware' => ['auth']]);
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'translate',
            label: 'nav.translate',
            icon: 'edit',
            route: 'admin.translate.settings',
            permission: 'translate.view',
            order: 79,
            group: 'Integrace',
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'translate.settings',
            label: 'palette.translate.settings',
            url: '/admin/translate/settings',
            icon: 'edit',
            permission: 'translate.view',
            keywords: ['translate', 'překlad', 'microsoft', 'azure', 'translator'],
        ));
    }
}
