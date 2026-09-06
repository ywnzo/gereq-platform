<?php

declare(strict_types=1);

namespace Modules\Payments;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Modules\Payments\Application\ComgateInboundProvider;
use Modules\Payments\Application\Gateways\ComgateGateway;
use Modules\Payments\Application\Gateways\GoPayGateway;
use Modules\Payments\Application\Gateways\ManualBankGateway;
use Modules\Payments\Application\Gateways\StripeGateway;
use Modules\Payments\Application\GoPayInboundProvider;
use Modules\Payments\Application\PaymentGatewayRegistry;
use Modules\Payments\Application\PaymentService;
use Modules\Payments\Application\PaymentsProvider;
use Modules\Payments\Application\StripeInboundProvider;
use Modules\Payments\Application\Widgets\PaymentsShortcutWidget;
use Modules\Payments\Http\Api\PaymentIntentApiController;
use Modules\Payments\Http\Api\PaymentMethodApiController;
use Modules\Payments\Http\Api\PaymentNotifyController;
use Modules\Payments\Http\PaymentMethodController;
use Modules\Webhooks\Application\InboundWebhookRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class PaymentsModule implements ModuleInterface
{
    public function id(): string
    {
        return 'payments';
    }

    public function version(): string
    {
        return '1.1.1';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('payments.view', 'perm.payments.view', 'perm.group.payments');
        $context->permissions()->register('payments.manage', 'perm.payments.manage', 'perm.group.payments');

        $context->capabilities()->register(
            'payments',
            $this->id(),
            new PaymentsProvider($context->get(EntityManagerInterface::class)),
        );

        $registry = $context->get(PaymentGatewayRegistry::class);
        $registry->register($context->get(ManualBankGateway::class));
        $registry->register($context->get(ComgateGateway::class));
        $registry->register($context->get(GoPayGateway::class));
        $registry->register($context->get(StripeGateway::class));
        $context->capabilities()->register('payment_gateways', $this->id(), $registry);

        $context->get(InboundWebhookRegistry::class)
            ->register($context->get(ComgateInboundProvider::class));
        $context->get(InboundWebhookRegistry::class)
            ->register($context->get(GoPayInboundProvider::class));
        $context->get(InboundWebhookRegistry::class)
            ->register($context->get(StripeInboundProvider::class));

        $context->settings()->define('payments.bank_iban', SettingRecord::TYPE_STRING, 'payments.settings.bank_iban', '', $this->id());
        $context->settings()->define('payments.bank_account', SettingRecord::TYPE_STRING, 'payments.settings.bank_account', '', $this->id());
        $context->settings()->define('payments.bank_code', SettingRecord::TYPE_STRING, 'payments.settings.bank_code', '', $this->id());
        $context->settings()->define('payments.bank_recipient', SettingRecord::TYPE_STRING, 'payments.settings.bank_recipient', '', $this->id());
        $context->settings()->define('payments.comgate_merchant', SettingRecord::TYPE_STRING, 'payments.settings.comgate_merchant', '', $this->id());
        $context->settings()->define('payments.comgate_secret', SettingRecord::TYPE_ENCRYPTED, 'payments.settings.comgate_secret', '', $this->id());
        $context->settings()->define('payments.comgate_test', SettingRecord::TYPE_BOOLEAN, 'payments.settings.comgate_test', true, $this->id());
        $context->settings()->define('payments.gopay_goid', SettingRecord::TYPE_STRING, 'payments.settings.gopay_goid', '', $this->id());
        $context->settings()->define('payments.gopay_client_id', SettingRecord::TYPE_STRING, 'payments.settings.gopay_client_id', '', $this->id());
        $context->settings()->define('payments.gopay_client_secret', SettingRecord::TYPE_ENCRYPTED, 'payments.settings.gopay_client_secret', '', $this->id());
        $context->settings()->define('payments.gopay_sandbox', SettingRecord::TYPE_BOOLEAN, 'payments.settings.gopay_sandbox', true, $this->id());
        $context->settings()->define('payments.stripe_secret', SettingRecord::TYPE_ENCRYPTED, 'payments.settings.stripe_secret', '', $this->id());
        $context->settings()->define('payments.stripe_webhook_secret', SettingRecord::TYPE_ENCRYPTED, 'payments.settings.stripe_webhook_secret', '', $this->id());
        $context->settings()->define('payments.imap_mailbox', SettingRecord::TYPE_STRING, 'payments.settings.imap_mailbox', '', $this->id());
        $context->settings()->define('payments.imap_user', SettingRecord::TYPE_STRING, 'payments.settings.imap_user', '', $this->id());
        $context->settings()->define('payments.imap_password', SettingRecord::TYPE_ENCRYPTED, 'payments.settings.imap_password', '', $this->id());

        // Public notify endpoints (bez Bearer) — fungují i bez zapnutého Webhooks modulu.
        $context->router()->add('/api/v1/payments/notify/comgate', ['GET', 'POST'], [PaymentNotifyController::class, 'comgate'])
            ->name('api.payments.notify.comgate');
        $context->router()->add('/api/v1/payments/notify/gopay', ['GET', 'POST'], [PaymentNotifyController::class, 'gopay'])
            ->name('api.payments.notify.gopay');
        $context->router()->post('/api/v1/payments/notify/stripe', [PaymentNotifyController::class, 'stripe'])
            ->name('api.payments.notify.stripe');

        $context->router()->group('/admin/payments/methods', function (Router $routes): void {
            $routes->get('', [PaymentMethodController::class, 'index'])
                ->name('admin.payments.methods.index')
                ->permission('payments.view');
            $routes->get('/create', [PaymentMethodController::class, 'create'])
                ->name('admin.payments.methods.create')
                ->permission('payments.manage');
            $routes->post('', [PaymentMethodController::class, 'store'])
                ->name('admin.payments.methods.store')
                ->permission('payments.manage');
            $routes->get('/{id}/edit', [PaymentMethodController::class, 'edit'])
                ->name('admin.payments.methods.edit')
                ->permission('payments.manage');
            $routes->post('/{id}', [PaymentMethodController::class, 'update'])
                ->name('admin.payments.methods.update')
                ->permission('payments.manage');
            $routes->post('/{id}/delete', [PaymentMethodController::class, 'destroy'])
                ->name('admin.payments.methods.destroy')
                ->permission('payments.manage');
        }, ['middleware' => ['auth']]);

        $this->registerApi($context);
        $this->registerPlatformEvents($context);
        $this->registerValidation($context);
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'payments',
            label: 'nav.payments',
            icon: 'credit-card',
            route: 'admin.payments.methods.index',
            permission: 'payments.view',
            order: 32,
            group: 'Obchod',
        ));
        $context->menu()->add(new MenuItem(
            id: 'payments.methods.create',
            label: 'nav.payments.methods.create',
            route: 'admin.payments.methods.create',
            permission: 'payments.manage',
            parent: 'payments',
            order: 33,
            hidden: true,
        ));

        $twig = $context->get(Environment::class);
        $translator = $context->get(TranslatorInterface::class);
        $router = $context->get(Router::class);
        $context->dashboard()->addWidget(new PaymentsShortcutWidget($twig, $translator, $router));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'payments.methods',
            label: 'palette.payments.methods',
            url: '/admin/payments/methods',
            icon: 'credit-card',
            permission: 'payments.view',
            keywords: ['platba', 'payment', 'platební', 'gopay', 'stripe'],
        ));
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->registerKnownScope('payments.read');
        $context->api()->registerKnownScope('payments.write');

        $context->api()->module('payments', function (ApiModule $api): void {
            $api->get('/methods', [PaymentMethodApiController::class, 'index'])->scope('payments.read');
            $api->post('/methods', [PaymentMethodApiController::class, 'store'])->scope('payments.write');
            $api->get('/methods/{id}', [PaymentMethodApiController::class, 'show'])->scope('payments.read');
            $api->patch('/methods/{id}', [PaymentMethodApiController::class, 'update'])->scope('payments.write');
            $api->delete('/methods/{id}', [PaymentMethodApiController::class, 'destroy'])->scope('payments.write');
            $api->get('/intents/{id}', [PaymentIntentApiController::class, 'show'])->scope('payments.read');
            $api->post('/intents/{id}/mark-paid', [PaymentIntentApiController::class, 'markPaid'])->scope('payments.write');
            $api->get('/orders/{orderId}/intents', [PaymentIntentApiController::class, 'forOrder'])->scope('payments.read');
        });
    }

    private function registerPlatformEvents(ModuleContext $context): void
    {
        foreach ([
            'payment.method_created' => 'Platební metoda vytvořena',
            'payment.method_updated' => 'Platební metoda upravena',
            'payment.method_deleted' => 'Platební metoda smazána',
            'payment.intent_created' => 'Payment intent vytvořen',
            'payment.intent_paid' => 'Payment intent zaplacen',
        ] as $type => $description) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))
                    ->description($description)
                    ->webhookEnabled(true)
            );
        }
    }

    private function registerValidation(ModuleContext $context): void
    {
        $context->validation()->ruleset('payments.method.save', [
            'code' => 'required|min:1|max:64',
            'name' => 'required|min:2|max:190',
            'type' => 'required|max:32',
            'provider' => 'nullable|max:64',
            'fee_fixed' => 'nullable',
            'fee_percent' => 'nullable',
            'currency' => 'nullable|max:3',
            'instructions' => 'nullable|max:20000',
            'sort_order' => 'nullable',
            'active' => 'nullable',
        ]);
    }
}
