<?php

declare(strict_types=1);

namespace Integrations\Stripe;

use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Integrations\Stripe\Application\StripeGateway;
use Integrations\Stripe\Application\StripeInboundProvider;
use Integrations\Stripe\Http\StripeNotifyController;
use Modules\Payments\Application\PaymentGatewayRegistry;
use Modules\Webhooks\Application\InboundWebhookRegistry;

final class StripeModule implements ModuleInterface
{
    public function id(): string
    {
        return 'stripe';
    }

    public function version(): string
    {
        return '1.0.1';
    }

    public function register(ModuleContext $context): void
    {
        $gateway = $context->get(StripeGateway::class);
        $context->get(PaymentGatewayRegistry::class)->register($gateway);
        $context->capabilities()->register('payment.gateway.stripe', $this->id(), $gateway);

        $context->settings()->define(
            'payments.stripe_secret',
            SettingRecord::TYPE_ENCRYPTED,
            'stripe.settings.secret',
            '',
            $this->id(),
        );
        $context->settings()->define(
            'payments.stripe_webhook_secret',
            SettingRecord::TYPE_ENCRYPTED,
            'stripe.settings.webhook_secret',
            '',
            $this->id(),
        );

        $context->router()->post(
            '/api/v1/payments/notify/stripe',
            [StripeNotifyController::class, 'notify'],
        )->name('api.payments.notify.stripe');

        if (class_exists(InboundWebhookRegistry::class)) {
            try {
                $context->get(InboundWebhookRegistry::class)
                    ->register($context->get(StripeInboundProvider::class));
            } catch (\Throwable) {
            }
        }
    }

    public function boot(ModuleContext $context): void
    {
    }
}
