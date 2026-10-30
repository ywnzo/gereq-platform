<?php

declare(strict_types=1);

namespace Integrations\Comgate;

use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Integrations\Comgate\Application\ComgateGateway;
use Integrations\Comgate\Application\ComgateInboundProvider;
use Integrations\Comgate\Http\ComgateNotifyController;
use Modules\Shop\Submodules\Payments\Application\PaymentGatewayRegistry;
use Gereq\Webhooks\Application\InboundWebhookRegistry;

/**
 * Third-party Comgate payment gateway — registers into Payments capability.
 */
final class ComgateModule implements ModuleInterface
{
    public function id(): string
    {
        return 'comgate';
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function register(ModuleContext $context): void
    {
        $gateway = $context->get(ComgateGateway::class);
        $context->get(PaymentGatewayRegistry::class)->register($gateway);
        $context->capabilities()->register('payment.gateway.comgate', $this->id(), $gateway);

        // Keep settings keys payments.comgate_* for BC with existing installs.
        $context->settings()->define(
            'payments.comgate_merchant',
            SettingRecord::TYPE_STRING,
            'comgate.settings.merchant',
            '',
            $this->id(),
        );
        $context->settings()->define(
            'payments.comgate_secret',
            SettingRecord::TYPE_ENCRYPTED,
            'comgate.settings.secret',
            '',
            $this->id(),
        );
        $context->settings()->define(
            'payments.comgate_test',
            SettingRecord::TYPE_BOOLEAN,
            'comgate.settings.test',
            true,
            $this->id(),
        );

        $context->router()->add(
            '/api/v1/payments/notify/comgate',
            ['GET', 'POST'],
            [ComgateNotifyController::class, 'notify'],
        )->name('api.payments.notify.comgate');

        if (class_exists(InboundWebhookRegistry::class)) {
            try {
                $context->get(InboundWebhookRegistry::class)
                    ->register($context->get(ComgateInboundProvider::class));
            } catch (\Throwable) {
                // Webhooks module not booted — public notify route still works.
            }
        }
    }

    public function boot(ModuleContext $context): void
    {
    }
}
