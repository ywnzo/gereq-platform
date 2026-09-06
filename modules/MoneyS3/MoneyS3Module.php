<?php

declare(strict_types=1);

namespace Modules\MoneyS3;

use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Modules\MoneyS3\Application\MoneyS3Service;
use Modules\MoneyS3\Application\Widgets\MoneyS3ShortcutWidget;
use Modules\MoneyS3\Http\MoneyS3SettingsController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class MoneyS3Module implements ModuleInterface
{
    public function id(): string
    {
        return 'moneys3';
    }

    public function version(): string
    {
        return '1.0.1';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('moneys3.view', 'perm.moneys3.view', 'perm.group.moneys3');
        $context->permissions()->register('moneys3.manage', 'perm.moneys3.manage', 'perm.group.moneys3');
        $context->permissions()->register('moneys3.sync', 'perm.moneys3.sync', 'perm.group.moneys3');

        $context->capabilities()->register('moneys3', $this->id(), $context->get(MoneyS3Service::class));

        $context->settings()->define('moneys3.enabled', SettingRecord::TYPE_BOOLEAN, 'moneys3.settings.enabled', false, $this->id(), [], false);
        $context->settings()->define('moneys3.api_url', SettingRecord::TYPE_STRING, 'moneys3.settings.api_url', '', $this->id(), [], false);
        $context->settings()->define('moneys3.app_id', SettingRecord::TYPE_STRING, 'moneys3.settings.app_id', '', $this->id(), [], false);
        $context->settings()->define('moneys3.client_id', SettingRecord::TYPE_STRING, 'moneys3.settings.client_id', '', $this->id(), [], false);
        $context->settings()->define('moneys3.client_secret', SettingRecord::TYPE_ENCRYPTED, 'moneys3.settings.client_secret', '', $this->id(), [], false);
        $context->settings()->define('moneys3.username', SettingRecord::TYPE_STRING, 'moneys3.settings.username', '', $this->id(), [], false);
        $context->settings()->define('moneys3.password', SettingRecord::TYPE_ENCRYPTED, 'moneys3.settings.password', '', $this->id(), [], false);
        $context->settings()->define('moneys3.default_agenda_id', SettingRecord::TYPE_STRING, 'moneys3.settings.default_agenda_id', '', $this->id(), [], false);
        $context->settings()->define('moneys3.cache', SettingRecord::TYPE_JSON, 'moneys3.settings.cache', [], $this->id(), [], false);

        $context->router()->group('/admin/integrations/moneys3', function (Router $routes): void {
            $routes->get('', [MoneyS3SettingsController::class, 'edit'])
                ->name('admin.moneys3.settings')
                ->permission('moneys3.view');
            $routes->post('', [MoneyS3SettingsController::class, 'update'])
                ->name('admin.moneys3.settings.update')
                ->permission('moneys3.manage');
            $routes->post('/sync', [MoneyS3SettingsController::class, 'sync'])
                ->name('admin.moneys3.sync')
                ->permission('moneys3.sync');
        }, ['middleware' => ['auth']]);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new MoneyS3ShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));

        $context->menu()->add(new MenuItem(
            id: 'moneys3',
            label: 'nav.moneys3',
            icon: 'database',
            route: 'admin.moneys3.settings',
            permission: 'moneys3.view',
            order: 79,
            group: 'Integrace',
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'moneys3.settings',
            label: 'palette.moneys3.settings',
            url: '/admin/integrations/moneys3',
            icon: 'database',
            permission: 'moneys3.view',
            keywords: ['moneys3', 'money', 'účetnictví', 'agenda', 'graphql'],
        ));
    }
}
