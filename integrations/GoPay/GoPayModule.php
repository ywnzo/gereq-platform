<?php

declare(strict_types=1);

namespace Integrations\GoPay;

use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Integrations\GoPay\Application\GoPayGateway;
use Integrations\GoPay\Application\GoPayInboundProvider;
use Integrations\GoPay\Http\GoPayNotifyController;
use Modules\Shop\Submodules\Payments\Application\PaymentGatewayRegistry;
use Gereq\Webhooks\Application\InboundWebhookRegistry;

final class GoPayModule implements ModuleInterface
{
    public function id(): string
    {
        return 'gopay';
    }

    public function version(): string
    {
        return '1.0.1';
    }

    public function register(ModuleContext $context): void
    {
        $gateway = $context->get(GoPayGateway::class);
        $context->get(PaymentGatewayRegistry::class)->register($gateway);
        $context->capabilities()->register('payment.gateway.gopay', $this->id(), $gateway);

        $context->settings()->define(
            'payments.gopay_goid',
            SettingRecord::TYPE_STRING,
            'gopay.settings.goid',
            '',
            $this->id(),
        );
        $context->settings()->define(
            'payments.gopay_client_id',
            SettingRecord::TYPE_STRING,
            'gopay.settings.client_id',
            '',
            $this->id(),
        );
        $context->settings()->define(
            'payments.gopay_client_secret',
            SettingRecord::TYPE_ENCRYPTED,
            'gopay.settings.client_secret',
            '',
            $this->id(),
        );
        $context->settings()->define(
            'payments.gopay_sandbox',
            SettingRecord::TYPE_BOOLEAN,
            'gopay.settings.sandbox',
            true,
            $this->id(),
        );

        $context->router()->add(
            '/api/v1/payments/notify/gopay',
            ['GET', 'POST'],
            [GoPayNotifyController::class, 'notify'],
        )->name('api.payments.notify.gopay');

        if (class_exists(InboundWebhookRegistry::class)) {
            try {
                $context->get(InboundWebhookRegistry::class)
                    ->register($context->get(GoPayInboundProvider::class));
            } catch (\Throwable) {
            }
        }
    }

    public function boot(ModuleContext $context): void
    {
    }
}
