<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateways;

use Gereq\Setting\Settings;
use Modules\Payments\Application\PaymentGatewayInterface;
use Modules\Payments\Domain\PaymentIntent;
use Modules\Payments\Domain\PaymentMethod;

/** GoPay scaffold — register when credentials set; createPayment returns not-configured until wired. */
final class GoPayGateway implements PaymentGatewayInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function key(): string
    {
        return 'gopay';
    }

    public function createPayment(PaymentIntent $intent, PaymentMethod $method, array $context = []): array
    {
        $goid = trim((string) $this->settings->get('payments.gopay_goid', ''));
        if ($goid === '') {
            return [
                'status' => 'pending',
                'redirect_url' => null,
                'external_id' => null,
                'payment_reference' => (string) ($context['order_number'] ?? $intent->orderId()),
                'spayd_payload' => null,
                'meta' => ['error' => 'GoPay not configured', 'provider' => 'gopay'],
            ];
        }

        return [
            'status' => 'pending',
            'redirect_url' => null,
            'external_id' => null,
            'payment_reference' => (string) ($context['order_number'] ?? ''),
            'spayd_payload' => null,
            'meta' => [
                'provider' => 'gopay',
                'note' => 'GoPay HTTP client scaffold — implement createPayment against GoPay REST when ready',
                'goid' => $goid,
            ],
        ];
    }

    public function handleNotify(array $payload): array
    {
        return [
            'ok' => false,
            'status' => null,
            'external_id' => isset($payload['id']) ? (string) $payload['id'] : null,
            'message' => 'GoPay notify adapter not fully wired',
        ];
    }
}
