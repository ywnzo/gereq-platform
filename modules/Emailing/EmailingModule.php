<?php

declare(strict_types=1);

namespace Modules\Emailing;

use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Emailing\Application\EmailingProvider;
use Modules\Emailing\Http\AccountController;
use Modules\Emailing\Http\Api\AccountApiController;
use Modules\Emailing\Http\Api\MailboxApiController;
use Modules\Emailing\Http\MailboxController;
use Modules\Emailing\Http\RuleController;

final class EmailingModule implements ModuleInterface
{
    public function id(): string
    {
        return 'emailing';
    }

    public function version(): string
    {
        return '1.5.3';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('emailing.view', 'perm.emailing.view', 'perm.group.emailing');
        $context->permissions()->register('emailing.accounts.manage', 'perm.emailing.accounts.manage', 'perm.group.emailing');
        $context->permissions()->register('emailing.send', 'perm.emailing.send', 'perm.group.emailing');
        $context->permissions()->register('emailing.rules.manage', 'perm.emailing.rules.manage', 'perm.group.emailing');

        $provider = $context->get(EmailingProvider::class);
        $context->capabilities()->register('emailing', $this->id(), $provider);

        $context->router()->group('/admin/emailing/accounts', function (Router $routes): void {
            $routes->get('', [AccountController::class, 'index'])
                ->name('admin.emailing.accounts.index')
                ->permission('emailing.accounts.manage');
            $routes->get('/create', [AccountController::class, 'create'])
                ->name('admin.emailing.accounts.create')
                ->permission('emailing.accounts.manage');
            $routes->post('', [AccountController::class, 'store'])
                ->name('admin.emailing.accounts.store')
                ->permission('emailing.accounts.manage');
            $routes->get('/{id}/edit', [AccountController::class, 'edit'])
                ->name('admin.emailing.accounts.edit')
                ->permission('emailing.accounts.manage');
            $routes->post('/{id}', [AccountController::class, 'update'])
                ->name('admin.emailing.accounts.update')
                ->permission('emailing.accounts.manage');
            $routes->post('/{id}/delete', [AccountController::class, 'destroy'])
                ->name('admin.emailing.accounts.destroy')
                ->permission('emailing.accounts.manage');
            $routes->post('/{id}/sync', [AccountController::class, 'sync'])
                ->name('admin.emailing.accounts.sync')
                ->permission('emailing.accounts.manage');
            $routes->post('/{id}/test', [AccountController::class, 'test'])
                ->name('admin.emailing.accounts.test')
                ->permission('emailing.accounts.manage');
        }, ['middleware' => ['auth']]);

        $context->router()->group('/admin/emailing/rules', function (Router $routes): void {
            $routes->get('', [RuleController::class, 'index'])
                ->name('admin.emailing.rules.index')
                ->permission('emailing.rules.manage');
            $routes->get('/create', [RuleController::class, 'create'])
                ->name('admin.emailing.rules.create')
                ->permission('emailing.rules.manage');
            $routes->post('', [RuleController::class, 'store'])
                ->name('admin.emailing.rules.store')
                ->permission('emailing.rules.manage');
            $routes->get('/{id}/edit', [RuleController::class, 'edit'])
                ->name('admin.emailing.rules.edit')
                ->permission('emailing.rules.manage');
            $routes->post('/{id}', [RuleController::class, 'update'])
                ->name('admin.emailing.rules.update')
                ->permission('emailing.rules.manage');
            $routes->post('/{id}/delete', [RuleController::class, 'destroy'])
                ->name('admin.emailing.rules.destroy')
                ->permission('emailing.rules.manage');
        }, ['middleware' => ['auth']]);

        $context->router()->group('/admin/emailing', function (Router $routes): void {
            $routes->get('', [MailboxController::class, 'index'])
                ->name('admin.emailing.mailbox')
                ->permission('emailing.view');
            $routes->get('/compose', [MailboxController::class, 'compose'])
                ->name('admin.emailing.compose')
                ->permission('emailing.send');
            $routes->post('/send', [MailboxController::class, 'send'])
                ->name('admin.emailing.send')
                ->permission('emailing.send');
            $routes->post('/sync', [MailboxController::class, 'sync'])
                ->name('admin.emailing.sync')
                ->permission('emailing.view');
            $routes->get('/poll', [MailboxController::class, 'poll'])
                ->name('admin.emailing.poll')
                ->permission('emailing.view');
            $routes->post('/action', [MailboxController::class, 'action'])
                ->name('admin.emailing.action')
                ->permission('emailing.view');
            $routes->get('/attachments/{id}', [MailboxController::class, 'downloadAttachment'])
                ->name('admin.emailing.attachments.download')
                ->permission('emailing.view');
            $routes->get('/messages/{id}/html', [MailboxController::class, 'messageHtml'])
                ->name('admin.emailing.messages.html')
                ->permission('emailing.view');
        }, ['middleware' => ['auth']]);

        $this->registerApi($context);
        $this->registerEvents($context);
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'emailing',
            label: 'nav.emailing',
            icon: 'mail',
            route: 'admin.emailing.mailbox',
            permission: 'emailing.view',
            order: 32,
            group: 'Hlavní',
        ));
        $context->menu()->add(new MenuItem(
            id: 'emailing.mailbox',
            label: 'nav.emailing.mailbox',
            route: 'admin.emailing.mailbox',
            permission: 'emailing.view',
            parent: 'emailing',
            order: 33,
        ));
        $context->menu()->add(new MenuItem(
            id: 'emailing.accounts',
            label: 'nav.emailing.accounts',
            route: 'admin.emailing.accounts.index',
            permission: 'emailing.accounts.manage',
            parent: 'emailing',
            order: 34,
        ));
        $context->menu()->add(new MenuItem(
            id: 'emailing.rules',
            label: 'nav.emailing.rules',
            route: 'admin.emailing.rules.index',
            permission: 'emailing.rules.manage',
            parent: 'emailing',
            order: 35,
        ));
        $context->menu()->add(new MenuItem(
            id: 'emailing.compose',
            label: 'nav.emailing.compose',
            route: 'admin.emailing.compose',
            permission: 'emailing.send',
            parent: 'emailing',
            order: 36,
            hidden: true,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'emailing.mailbox',
            label: 'palette.emailing.mailbox',
            url: '/admin/emailing',
            icon: 'mail',
            permission: 'emailing.view',
            keywords: ['email', 'mail', 'schránka', 'inbox'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'emailing.rules',
            label: 'palette.emailing.rules',
            url: '/admin/emailing/rules',
            icon: 'filter',
            permission: 'emailing.rules.manage',
            keywords: ['pravidla', 'filtr', 'rules'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'emailing.compose',
            label: 'palette.emailing.compose',
            url: '/admin/emailing/compose',
            icon: 'plus',
            permission: 'emailing.send',
            group: 'Akce',
        ));
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->registerKnownScope('emailing.accounts.read');
        $context->api()->registerKnownScope('emailing.accounts.write');
        $context->api()->registerKnownScope('emailing.mailbox.read');
        $context->api()->registerKnownScope('emailing.mailbox.write');

        $context->api()->module('emailing', function (ApiModule $api): void {
            $api->get('/accounts', [AccountApiController::class, 'index'])->scope('emailing.accounts.read');
            $api->post('/accounts', [AccountApiController::class, 'store'])->scope('emailing.accounts.write');
            $api->get('/accounts/{id}', [AccountApiController::class, 'show'])->scope('emailing.accounts.read');
            $api->patch('/accounts/{id}', [AccountApiController::class, 'update'])->scope('emailing.accounts.write');
            $api->delete('/accounts/{id}', [AccountApiController::class, 'destroy'])->scope('emailing.accounts.write');
            $api->post('/accounts/{id}/sync', [AccountApiController::class, 'sync'])->scope('emailing.accounts.write');

            $api->get('/folders', [MailboxApiController::class, 'folders'])->scope('emailing.mailbox.read');
            $api->get('/messages', [MailboxApiController::class, 'messages'])->scope('emailing.mailbox.read');
            $api->get('/messages/{id}', [MailboxApiController::class, 'show'])->scope('emailing.mailbox.read');
            $api->post('/send', [MailboxApiController::class, 'send'])->scope('emailing.mailbox.write');
        });
    }

    private function registerEvents(ModuleContext $context): void
    {
        $events = [
            'emailing.mailbox.polled' => 'Kontrola počtů zpráv ve složkách (poll)',
            'emailing.mailbox.folder_reconciled' => 'Reconcile složky podle UID (přidání/smazání/změna)',
            'emailing.message.received' => 'Nová zpráva ve schránce (po sync/reconcile)',
        ];
        foreach ($events as $type => $description) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))
                    ->description($description)
                    ->webhookEnabled(false)
            );
        }
    }
}
