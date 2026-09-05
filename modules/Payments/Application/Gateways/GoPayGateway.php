<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateways;

use Gereq\Config\Repository as Config;
use Gereq\Setting\Settings;
use Modules\Payments\Application\PaymentGatewayInterface;
use Modules\Payments\Domain\PaymentIntent;
use Modules\Payments\Domain\PaymentMethod;

/**
 * GoPay REST API — karty + Apple Pay / Google Pay (payment instruments).
 * Settings: payments.gopay_goid, payments.gopay_client_id, payments.gopay_client_secret, payments.gopay_sandbox.
 */
final class GoPayGateway implements PaymentGatewayInterface
{
    private const PROD = 'https://gate.gopay.cz/api';
    private const SANDBOX = 'https://gw.sandbox.gopay.com/api';

    public function __construct(
        private readonly Settings $settings,
        private readonly Config $config,
    ) {
    }

    public function key(): string
    {
        return 'gopay';
    }

    public function createPayment(PaymentIntent $intent, PaymentMethod $method, array $context = []): array
    {
        $goid = trim((string) $this->settings->get('payments.gopay_goid', ''));
        $clientId = trim((string) $this->settings->get('payments.gopay_client_id', ''));
        $clientSecret = trim((string) $this->settings->get('payments.gopay_client_secret', ''));
        $orderNumber = (string) ($context['order_number'] ?? $intent->orderId());

        if ($goid === '' || $clientId === '' || $clientSecret === '') {
            return [
                'status' => 'pending',
                'redirect_url' => null,
                'external_id' => null,
                'payment_reference' => $orderNumber,
                'spayd_payload' => null,
                'meta' => [
                    'error' => 'GoPay not configured (goid / client_id / client_secret)',
                    'provider' => 'gopay',
                ],
            ];
        }

        $token = $this->accessToken($clientId, $clientSecret, 'payment-create');
        if ($token === null) {
            return [
                'status' => 'failed',
                'redirect_url' => null,
                'external_id' => null,
                'payment_reference' => $orderNumber,
                'spayd_payload' => null,
                'meta' => ['error' => 'GoPay OAuth failed', 'provider' => 'gopay'],
            ];
        }

        $baseUrl = rtrim((string) $this->config->get('app.url', 'http://127.0.0.1:8080'), '/');
        $returnBase = rtrim((string) ($context['return_url'] ?? $baseUrl), '/');
        $cents = max(1, (int) round(((float) $intent->amount()) * 100));
        $instrument = $this->instrumentForMethod($method, $context);

        $payload = [
            'payer' => [
                'default_payment_instrument' => $instrument,
                'allowed_payment_instruments' => [$instrument],
                'contact' => array_filter([
                    'email' => (string) ($context['customer_email'] ?? '') ?: null,
                    'first_name' => (string) ($context['customer_first_name'] ?? '') ?: null,
                    'last_name' => (string) ($context['customer_last_name'] ?? $context['customer_name'] ?? '') ?: null,
                    'phone_number' => (string) ($context['customer_phone'] ?? '') ?: null,
                ], static fn ($v) => $v !== null && $v !== ''),
            ],
            'target' => [
                'type' => 'ACCOUNT',
                'goid' => (int) $goid,
            ],
            'amount' => $cents,
            'currency' => strtoupper($intent->currency()),
            'order_number' => mb_substr($orderNumber, 0, 128),
            'order_description' => mb_substr((string) ($context['order_description'] ?? ('Objednávka ' . $orderNumber)), 0, 256),
            'lang' => strtoupper((string) ($context['lang'] ?? 'CS')),
            'callback' => [
                'return_url' => $returnBase . '/api/v1/payments/notify/gopay?return=1',
                'notification_url' => $returnBase . '/api/v1/payments/notify/gopay',
            ],
        ];

        $response = $this->request('POST', '/payments/payment', $token, $payload);
        if (($response['_http'] ?? 0) >= 400 || empty($response['id'])) {
            return [
                'status' => 'failed',
                'redirect_url' => null,
                'external_id' => null,
                'payment_reference' => $orderNumber,
                'spayd_payload' => null,
                'meta' => [
                    'provider' => 'gopay',
                    'error' => (string) ($response['errors'][0]['message'] ?? $response['message'] ?? 'GoPay create failed'),
                    'raw' => $response,
                ],
            ];
        }

        return [
            'status' => 'redirect',
            'redirect_url' => (string) ($response['gw_url'] ?? '') ?: null,
            'external_id' => (string) $response['id'],
            'payment_reference' => $orderNumber,
            'spayd_payload' => null,
            'meta' => [
                'provider' => 'gopay',
                'instrument' => $instrument,
                'sandbox' => $this->sandbox(),
                'state' => $response['state'] ?? null,
                'raw' => [
                    'id' => $response['id'],
                    'gw_url' => $response['gw_url'] ?? null,
                    'state' => $response['state'] ?? null,
                ],
            ],
        ];
    }

    public function handleNotify(array $payload): array
    {
        $id = (string) ($payload['id'] ?? $payload['payment_id'] ?? '');
        if ($id === '') {
            return ['ok' => false, 'status' => null, 'external_id' => null, 'message' => 'Missing GoPay payment id'];
        }

        $clientId = trim((string) $this->settings->get('payments.gopay_client_id', ''));
        $clientSecret = trim((string) $this->settings->get('payments.gopay_client_secret', ''));
        $token = $this->accessToken($clientId, $clientSecret, 'payment-all');
        if ($token === null) {
            return ['ok' => false, 'status' => null, 'external_id' => $id, 'message' => 'GoPay OAuth failed'];
        }

        $payment = $this->request('GET', '/payments/payment/' . rawurlencode($id), $token);
        if (($payment['_http'] ?? 0) >= 400 || empty($payment['id'])) {
            return [
                'ok' => false,
                'status' => null,
                'external_id' => $id,
                'message' => 'GoPay status request failed',
                'raw' => $payment,
            ];
        }

        $state = strtoupper((string) ($payment['state'] ?? ''));
        $mapped = match ($state) {
            'PAID' => 'paid',
            'CANCELED', 'CANCELLED', 'TIMEOUTED' => 'cancelled',
            'CREATED', 'PAYMENT_METHOD_CHOSEN', 'AUTHORIZED' => 'pending',
            'REFUNDED', 'PARTIALLY_REFUNDED' => 'paid',
            default => 'pending',
        };

        return [
            'ok' => true,
            'status' => $mapped,
            'external_id' => (string) $payment['id'],
            'message' => 'GoPay state: ' . $state,
            'ref_id' => (string) ($payment['order_number'] ?? ''),
            'raw' => $payment,
        ];
    }

    /** @param array<string, mixed> $context */
    private function instrumentForMethod(PaymentMethod $method, array $context): string
    {
        if (!empty($context['gopay_instrument'])) {
            return strtoupper((string) $context['gopay_instrument']);
        }

        $code = strtoupper($method->code());

        return match (true) {
            str_contains($code, 'APPLE') => 'APPLE_PAY',
            str_contains($code, 'GOOGLE') => 'GOOGLE_PAY',
            str_contains($code, 'BANK') => 'BANK_ACCOUNT',
            default => 'PAYMENT_CARD',
        };
    }

    private function sandbox(): bool
    {
        return filter_var($this->settings->get('payments.gopay_sandbox', true), FILTER_VALIDATE_BOOLEAN);
    }

    private function baseUrl(): string
    {
        return $this->sandbox() ? self::SANDBOX : self::PROD;
    }

    private function accessToken(string $clientId, string $clientSecret, string $scope): ?string
    {
        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $url = $this->baseUrl() . '/oauth2/token';
        $raw = $this->http('POST', $url, [
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'client_credentials',
            'scope' => $scope,
        ]));

        $json = json_decode($raw['body'], true);
        if (!is_array($json) || empty($json['access_token'])) {
            return null;
        }

        return (string) $json['access_token'];
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, string $token, ?array $json = null): array
    {
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];
        $body = null;
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        $raw = $this->http($method, $this->baseUrl() . $path, $headers, $body);
        $decoded = json_decode($raw['body'], true);
        if (!is_array($decoded)) {
            $decoded = ['message' => $raw['body'] !== '' ? $raw['body'] : 'Invalid JSON'];
        }
        $decoded['_http'] = $raw['status'];

        return $decoded;
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string}
     */
    private function http(string $method, string $url, array $headers, ?string $body): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $body ?? '',
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }

        return [
            'status' => $status,
            'body' => is_string($response) ? $response : '',
        ];
    }
}
