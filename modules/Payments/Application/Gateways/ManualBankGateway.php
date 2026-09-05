<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateways;

use Gereq\Setting\Settings;
use Modules\Payments\Application\PaymentGatewayInterface;
use Modules\Payments\Application\SpaydBuilder;
use Modules\Payments\Domain\PaymentIntent;
use Modules\Payments\Domain\PaymentMethod;

/** Offline bank transfer + optional COD — no external API. */
final class ManualBankGateway implements PaymentGatewayInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function key(): string
    {
        return 'manual';
    }

    public function createPayment(PaymentIntent $intent, PaymentMethod $method, array $context = []): array
    {
        $vs = (string) ($context['order_number'] ?? $intent->orderId());
        $vs = preg_replace('/\D+/', '', $vs) ?: substr(preg_replace('/\W+/', '', $vs) ?? $intent->id(), 0, 10);
        $iban = (string) $this->settings->get('payments.bank_iban', '');
        $account = (string) $this->settings->get('payments.bank_account', '');
        $bankCode = (string) $this->settings->get('payments.bank_code', '');
        $recipient = (string) $this->settings->get('payments.bank_recipient', $this->settings->get('app.name', 'GEREQ'));

        $spayd = null;
        if ($method->type() === 'bank_transfer' && ($iban !== '' || ($account !== '' && $bankCode !== ''))) {
            $spayd = SpaydBuilder::build([
                'iban' => $iban,
                'account' => $account,
                'bank_code' => $bankCode,
                'amount' => $intent->amount(),
                'currency' => $intent->currency(),
                'vs' => $vs,
                'message' => 'Order ' . ($context['order_number'] ?? ''),
                'recipient' => $recipient,
            ]);
        }

        return [
            'status' => $method->type() === 'cod' ? 'pending' : 'pending',
            'redirect_url' => null,
            'external_id' => null,
            'payment_reference' => $vs,
            'spayd_payload' => $spayd,
            'meta' => [
                'instructions' => $method->instructions(),
                'type' => $method->type(),
            ],
        ];
    }

    public function handleNotify(array $payload): array
    {
        // Manual gateway has no provider webhook; use admin mark-paid.
        return [
            'ok' => false,
            'status' => null,
            'external_id' => null,
            'message' => 'Manual gateway does not accept remote notify',
        ];
    }
}
