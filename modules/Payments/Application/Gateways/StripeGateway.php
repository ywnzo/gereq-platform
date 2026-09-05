<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateways;

use Gereq\Setting\Settings;
use Modules\Payments\Application\PaymentGatewayInterface;
use Modules\Payments\Domain\PaymentIntent;
use Modules\Payments\Domain\PaymentMethod;

/** Stripe scaffold (PaymentIntents / Checkout Session). */
final class StripeGateway implements PaymentGatewayInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function key(): string
    {
        return 'stripe';
    }

    public function createPayment(PaymentIntent $intent, PaymentMethod $method, array $context = []): array
    {
        $secret = trim((string) $this->settings->get('payments.stripe_secret', ''));
        if ($secret === '') {
            return [
                'status' => 'pending',
                'redirect_url' => null,
                'external_id' => null,
                'payment_reference' => (string) ($context['order_number'] ?? $intent->orderId()),
                'spayd_payload' => null,
                'meta' => ['error' => 'Stripe not configured', 'provider' => 'stripe'],
            ];
        }

        return [
            'status' => 'pending',
            'redirect_url' => null,
            'external_id' => null,
            'payment_reference' => (string) ($context['order_number'] ?? ''),
            'spayd_payload' => null,
            'meta' => [
                'provider' => 'stripe',
                'note' => 'Stripe client scaffold — wire Checkout Session / PaymentIntent API',
            ],
        ];
    }

    public function handleNotify(array $payload): array
    {
        return [
            'ok' => false,
            'status' => null,
            'external_id' => isset($payload['id']) ? (string) $payload['id'] : null,
            'message' => 'Stripe webhook adapter not fully wired',
        ];
    }
}
