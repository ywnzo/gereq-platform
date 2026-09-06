<?php

declare(strict_types=1);

namespace Modules\ESign;

use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Modules\ESign\Application\ESignProvider;
use Modules\ESign\Application\Widgets\ESignShortcutWidget;
use Modules\ESign\Http\DocumentController;
use Modules\ESign\Http\PublicSignController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ESignModule implements ModuleInterface
{
    public function id(): string
    {
        return 'esign';
    }

    public function version(): string
    {
        return '1.1.2';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('esign.view', 'perm.esign.view', 'perm.group.esign');
        $context->permissions()->register('esign.manage', 'perm.esign.manage', 'perm.group.esign');
        $context->capabilities()->register('esign', $this->id(), $context->get(ESignProvider::class));

        $context->settings()->define('esign.provider_name', SettingRecord::TYPE_STRING, 'esign.settings.provider_name', 'GEREQ', $this->id(), [], false);
        $context->settings()->define('esign.provider_email', SettingRecord::TYPE_STRING, 'esign.settings.provider_email', '', $this->id(), [], false);
        $context->settings()->define('esign.provider_signature_path', SettingRecord::TYPE_STRING, 'esign.settings.provider_signature_path', '', $this->id(), [], false);
        $context->settings()->define('esign.link_ttl_days', SettingRecord::TYPE_INTEGER, 'esign.settings.link_ttl_days', 14, $this->id(), [], false);
        $context->settings()->define('esign.consent_version', SettingRecord::TYPE_STRING, 'esign.settings.consent_version', '2026-09-06', $this->id(), [], false);
        $context->settings()->define('esign.display_timezone', SettingRecord::TYPE_STRING, 'esign.settings.display_timezone', 'Europe/Prague', $this->id(), [], false);

        $context->router()->group('/admin/esign', function (Router $routes): void {
            $routes->get('/documents', [DocumentController::class, 'index'])->name('admin.esign.documents.index')->permission('esign.view');
            $routes->get('/documents/create', [DocumentController::class, 'create'])->name('admin.esign.documents.create')->permission('esign.manage');
            $routes->post('/documents', [DocumentController::class, 'store'])->name('admin.esign.documents.store')->permission('esign.manage');
            $routes->get('/documents/{id}', [DocumentController::class, 'show'])->name('admin.esign.documents.show')->permission('esign.view');
            $routes->get('/documents/{id}/edit', [DocumentController::class, 'edit'])->name('admin.esign.documents.edit')->permission('esign.manage');
            $routes->post('/documents/{id}', [DocumentController::class, 'update'])->name('admin.esign.documents.update')->permission('esign.manage');
            $routes->post('/documents/{id}/sign-provider', [DocumentController::class, 'signProvider'])->name('admin.esign.documents.sign_provider')->permission('esign.manage');
            $routes->post('/documents/{id}/send', [DocumentController::class, 'send'])->name('admin.esign.documents.send')->permission('esign.manage');
            $routes->post('/documents/{id}/cancel', [DocumentController::class, 'cancel'])->name('admin.esign.documents.cancel')->permission('esign.manage');
            $routes->post('/documents/{id}/delete', [DocumentController::class, 'destroy'])->name('admin.esign.documents.destroy')->permission('esign.manage');
            $routes->get('/settings', [DocumentController::class, 'settings'])->name('admin.esign.settings')->permission('esign.manage');
            $routes->post('/settings', [DocumentController::class, 'saveSettings'])->name('admin.esign.settings.save')->permission('esign.manage');
        }, ['middleware' => ['auth']]);

        $context->router()->get('/esign/podpis/{token}', [PublicSignController::class, 'show'])->name('esign.sign');
        $context->router()->post('/esign/podpis/{token}', [PublicSignController::class, 'store'])->name('esign.sign.store');

        foreach ([
            'esign.document_created' => 'Dokument k podpisu vytvořen',
            'esign.document_sent' => 'Dokument odeslán k podpisu',
            'esign.document_signed' => 'Dokument podepsán protistranou',
        ] as $type => $desc) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))->description($desc)->webhookEnabled(true)
            );
        }

        $v = $context->validation();
        $v->ruleset('esign.document.save', [
            'title' => 'required|min:1|max:190',
            'body_html' => 'required|min:1|max:500000',
            'counterparty_name' => 'required|min:1|max:190',
            'counterparty_email' => 'nullable|email|max:190',
            'counterparty_company' => 'nullable|max:190',
            'number' => 'nullable|max:64',
        ]);
        $v->ruleset('esign.settings', [
            'provider_name' => 'required|min:1|max:190',
            'provider_email' => 'nullable|email|max:190',
            'link_ttl_days' => 'required|integer|min:1|max:90',
            'consent_version' => 'required|min:1|max:32',
        ]);
        $v->ruleset('esign.public.sign', [
            'consent_read' => 'accepted',
            'consent_authority' => 'accepted',
            'consent_eidas' => 'accepted',
            'signature' => 'required|string|min:80|max:400000',
        ]);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new ESignShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));

        $context->menu()->add(new MenuItem(
            id: 'esign',
            label: 'nav.esign',
            icon: 'edit',
            route: 'admin.esign.documents.index',
            permission: 'esign.view',
            order: 48,
            group: 'Provoz',
        ));
        $context->menu()->add(new MenuItem(
            id: 'esign.documents',
            label: 'nav.esign.documents',
            route: 'admin.esign.documents.index',
            permission: 'esign.view',
            parent: 'esign',
            order: 49,
        ));
        $context->menu()->add(new MenuItem(
            id: 'esign.settings',
            label: 'nav.esign.settings',
            route: 'admin.esign.settings',
            permission: 'esign.manage',
            parent: 'esign',
            order: 50,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'esign.documents',
            label: 'palette.esign.documents',
            url: '/admin/esign/documents',
            icon: 'edit',
            permission: 'esign.view',
            keywords: ['esign', 'podpis', 'signature', 'e-podpis', 'dokument'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'esign.settings',
            label: 'palette.esign.settings',
            url: '/admin/esign/settings',
            icon: 'settings',
            permission: 'esign.manage',
            keywords: ['esign', 'podpis', 'nastavení'],
        ));
    }
}
