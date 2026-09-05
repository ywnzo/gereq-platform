<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateways;

use Gereq\Config\Repository as Config;
use Gereq\Setting\Settings;
use Modules\Payments\Application\PaymentGatewayInterface;
use Modules\Payments\Domain\PaymentIntent;
use Modules\Payments\Domain\PaymentMethod;

/**
 * Comgate payment gateway (CZ/SK).
 * Settings: payments.comgate_merchant, payments.comgate_secret, payments.comgate_test.
 */
final class ComgateGateway implements PaymentGatewayInterface
{
    private const API = 'https://payments.comgate.cz/v1.0';

    public function __construct(
        private readonly Settings $settings,
        private readonly Config $config,
    ) {
    }

    public function key(): string
    {
        return 'comgate';
    }

    public function createPayment(PaymentIntent $intent, PaymentMethod $method, array $context = []): array
    {
        $merchant = trim((string) $this->settings->get('payments.comgate_merchant', ''));
        $secret = trim((string) $this->settings->get('payments.comgate_secret', ''));
        if ($merchant === '' || $secret === '') {
            return [
                'status' => 'pending',
                'redirect_url' => null,
                'external_id' => null,
                'payment_reference' => (string) ($context['order_number'] ?? $intent->orderId()),
                'spayd_payload' => null,
                'meta' => [
                    'error' => 'Comgate not configured (merchant/secret)',
                    'provider' => 'comgate',
                ],
            ];
        }

        $orderNumber = (string) ($context['order_number'] ?? $intent->orderId());
        $baseUrl = rtrim((string) $this->config->get('app.url', 'http://127.0.0.1:8080'), '/');
        $returnBase = rtrim((string) ($context['return_url'] ?? $baseUrl), '/');
        $cents = max(1, (int) round(((float) $intent->amount()) * 100));
        $test = filter_var($this->settings->get('payments.comgate_test', true), FILTER_VALIDATE_BOOLEAN);

        $params = [
            'merchant' => $merchant,
            'secret' => $secret,
            'prepareOnly' => 'true',
            'test' => $test ? 'true' : 'false',
            'price' => (string) $cents,
            'curr' => strtoupper($intent->currency()),
            'label' => mb_substr($orderNumber, 0, 16),
            'refId' => $orderNumber,
            'method' => (string) ($context['comgate_method'] ?? 'ALL'),
            'email' => (string) ($context['customer_email'] ?? ''),
            'fullName' => (string) ($context['customer_name'] ?? ''),
            'lang' => (string) ($context['lang'] ?? 'cs'),
            'country' => (string) ($context['country'] ?? 'CZ'),
            'delivery' => (string) ($context['delivery'] ?? 'HOME_DELIVERY'),
            'category' => 'PHYSICAL_GOODS_ONLY',
            'embedded' => !empty($context['embedded']) ? 'true' : 'false',
            'url_paid' => $returnBase . '/api/v1/webhooks/inbound/comgate?status=paid',
            'url_pending' => $returnBase . '/api/v1/webhooks/inbound/comgate?status=pending',
            'url_cancelled' => $returnBase . '/api/v1/webhooks/inbound/comgate?status=cancelled',
        ];

        $response = $this->postForm(self::API . '/create', $params);
        $code = (int) ($response['code'] ?? -1);
        if ($code !== 0) {
            return [
                'status' => 'failed',
                'redirect_url' => null,
                'external_id' => null,
                'payment_reference' => $orderNumber,
                'spayd_payload' => null,
                'meta' => [
                    'provider' => 'comgate',
                    'error' => (string) ($response['message'] ?? 'Comgate create failed'),
                    'raw' => $response,
                ],
            ];
        }

        return [
            'status' => 'redirect',
            'redirect_url' => (string) ($response['redirect'] ?? null) ?: null,
            'external_id' => (string) ($response['transId'] ?? null) ?: null,
            'payment_reference' => $orderNumber,
            'spayd_payload' => null,
            'meta' => [
                'provider' => 'comgate',
                'test' => $test,
                'raw' => $response,
            ],
        ];
    }

    public function handleNotify(array $payload): array
    {
        $secret = trim((string) $this->settings->get('payments.comgate_secret', ''));
        $incoming = (string) ($payload['secret'] ?? '');
        if ($secret === '' || $incoming === '' || !hash_equals($secret, $incoming)) {
            return [
                'ok' => false,
                'status' => null,
                'external_id' => null,
                'message' => 'Invalid Comgate secret',
            ];
        }

        $transId = (string) ($payload['transId'] ?? $payload['id'] ?? '');
        $refId = (string) ($payload['refId'] ?? '');
        if ($transId === '') {
            return ['ok' => false, 'status' => null, 'external_id' => null, 'message' => 'Missing transId'];
        }

        $statusPayload = $this->postForm(self::API . '/status', [
            'merchant' => (string) $this->settings->get('payments.comgate_merchant', ''),
            'secret' => $secret,
            'transId' => $transId,
        ]);

        if (($statusPayload['code'] ?? '') === '-1' || ($statusPayload['message'] ?? '') === 'Comgate HTTP request failed') {
            return [
                'ok' => false,
                'status' => null,
                'external_id' => $transId,
                'message' => 'Comgate status request failed',
                'ref_id' => $refId,
            ];
        }

        $status = strtoupper((string) ($statusPayload['status'] ?? $payload['status'] ?? ''));
        if ($status === '') {
            return [
                'ok' => false,
                'status' => null,
                'external_id' => $transId,
                'message' => 'Comgate status unknown',
                'ref_id' => $refId,
            ];
        }

        $mapped = match ($status) {
            'PAID' => 'paid',
            'CANCELLED', 'CANCEL' => 'cancelled',
            'PENDING' => 'pending',
            default => strtolower($status) ?: 'pending',
        };

        return [
            'ok' => true,
            'status' => $mapped,
            'external_id' => $transId,
            'message' => 'Comgate status: ' . $status,
            'ref_id' => $refId,
            'raw' => $statusPayload,
        ];
    }

    /** @param array<string, scalar|null> $params @return array<string, string> */
    private function postForm(string $url, array $params): array
    {
        $body = http_build_query($params);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return ['code' => '-1', 'message' => 'Comgate HTTP request failed'];
        }
        $parsed = [];
        parse_str($raw, $parsed);

        /** @var array<string, string> $parsed */
        return $parsed;
    }
}
