<?php

declare(strict_types=1);

namespace Modules\Vikendo;

use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Modules\Vikendo\Application\VikendoClient;
use Modules\Vikendo\Application\VikendoImportConfig;
use Modules\Vikendo\Http\VikendoSettingsController;

/**
 * Vikendo.cz — modul pro import událostí do KdeAco.
 */
final class VikendoModule implements ModuleInterface
{
    public function id(): string
    {
        return 'vikendo';
    }

    public function version(): string
    {
        return '0.3.3';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('vikendo.view', 'perm.vikendo.view', 'perm.group.vikendo');
        $context->permissions()->register('vikendo.manage', 'perm.vikendo.manage', 'perm.group.vikendo');

        $client = $context->get(VikendoClient::class);
        $context->capabilities()->register('events.source.vikendo', $this->id(), $client);

        $context->settings()->define(
            'vikendo.district_eids',
            SettingRecord::TYPE_JSON,
            'vikendo.settings.district_eids',
            array_keys(VikendoImportConfig::DISTRICTS),
            $this->id(),
            [],
            false,
        );
        $context->settings()->define(
            'vikendo.import_enabled',
            SettingRecord::TYPE_BOOLEAN,
            'vikendo.settings.import_enabled',
            true,
            $this->id(),
            [],
            false,
        );
        $context->settings()->define(
            'vikendo.per_page',
            SettingRecord::TYPE_INTEGER,
            'vikendo.settings.per_page',
            100,
            $this->id(),
            [],
            false,
        );
        $context->settings()->define(
            'vikendo.max_pages',
            SettingRecord::TYPE_INTEGER,
            'vikendo.settings.max_pages',
            40,
            $this->id(),
            [],
            false,
        );
        $context->settings()->define(
            'vikendo.start_from',
            SettingRecord::TYPE_STRING,
            'vikendo.settings.start_from',
            '',
            $this->id(),
            [],
            false,
        );
        $context->settings()->define(
            'vikendo.base_url',
            SettingRecord::TYPE_STRING,
            'vikendo.settings.base_url',
            VikendoClient::DEFAULT_BASE_URL,
            $this->id(),
            [],
            false,
        );
        $context->settings()->define(
            'vikendo.auth_token',
            SettingRecord::TYPE_ENCRYPTED,
            'vikendo.settings.auth_token',
            '',
            $this->id(),
            [],
            false,
        );
        $context->settings()->define(
            'vikendo.default_region_eid',
            SettingRecord::TYPE_STRING,
            'vikendo.settings.default_region_eid',
            VikendoClient::REGION_PLZENSKY,
            $this->id(),
            [],
            false,
        );
        $context->settings()->define(
            'vikendo.timeout',
            SettingRecord::TYPE_INTEGER,
            'vikendo.settings.timeout',
            20,
            $this->id(),
            [],
            false,
        );

        $context->router()->group('/admin/vikendo', function (Router $routes): void {
            $routes->get('', [VikendoSettingsController::class, 'importPage'])
                ->name('admin.vikendo.import')->permission('vikendo.view');
            // zpětná kompatibilita (module settings_route / staré odkazy)
            $routes->get('/settings', [VikendoSettingsController::class, 'edit'])
                ->name('admin.vikendo.settings')->permission('vikendo.view');
            $routes->post('/import', [VikendoSettingsController::class, 'saveImport'])
                ->name('admin.vikendo.import.save')->permission('vikendo.manage');
            $routes->post('/import/run', [VikendoSettingsController::class, 'import'])
                ->name('admin.vikendo.import.run')->permission('vikendo.manage');

            $routes->get('/districts', [VikendoSettingsController::class, 'districtsPage'])
                ->name('admin.vikendo.districts')->permission('vikendo.view');
            $routes->post('/districts', [VikendoSettingsController::class, 'saveDistricts'])
                ->name('admin.vikendo.districts.save')->permission('vikendo.manage');

            $routes->get('/catalog', [VikendoSettingsController::class, 'catalogPage'])
                ->name('admin.vikendo.catalog')->permission('vikendo.view');

            $routes->get('/api', [VikendoSettingsController::class, 'apiPage'])
                ->name('admin.vikendo.api')->permission('vikendo.view');
            $routes->post('/api', [VikendoSettingsController::class, 'saveApi'])
                ->name('admin.vikendo.api.save')->permission('vikendo.manage');

            $routes->get('/log', [VikendoSettingsController::class, 'logPage'])
                ->name('admin.vikendo.log')->permission('vikendo.view');
            $routes->get('/log/tail', [VikendoSettingsController::class, 'logTail'])
                ->name('admin.vikendo.log.tail')->permission('vikendo.view');
            $routes->post('/log/clear', [VikendoSettingsController::class, 'logClear'])
                ->name('admin.vikendo.log.clear')->permission('vikendo.manage');
        }, ['middleware' => ['auth'], 'module' => 'vikendo']);
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'vikendo',
            label: 'nav.vikendo',
            icon: 'dynamic_form',
            route: 'admin.vikendo.import',
            permission: 'vikendo.view',
            order: 26,
            group: 'KdeAco',
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'vikendo.settings',
            label: 'palette.vikendo',
            url: '/admin/vikendo',
            icon: 'dynamic_form',
            permission: 'vikendo.view',
            keywords: ['vikendo', 'import', 'okres', 'plzen', 'kdeaco', 'log'],
        ));
    }
}
