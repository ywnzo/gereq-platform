<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateways;

use Gereq\Config\Repository as Config;
use Gereq\Setting\Settings;
use Modules\Payments\Application\PaymentGatewayInterface;
use Modules\Payments\Domain\PaymentIntent;
use Modules\Payments\Domain\PaymentMethod;

/**
 * Stripe Checkout Session + webhook.
 * Settings: payments.stripe_secret, payments.stripe_webhook_secret.
 */
final class StripeGateway implements PaymentGatewayInterface
{
    private const API = 'https://api.stripe.com/v1';

    public function __construct(
        private readonly Settings $settings,
        private readonly Config $config,
    ) {
    }

    public function key(): string
    {
        return 'stripe';
    }

    public function createPayment(PaymentIntent $intent, PaymentMethod $method, array $context = []): array
    {
        $secret = trim((string) $this->settings->get('payments.stripe_secret', ''));
        $orderNumber = (string) ($context['order_number'] ?? $intent->orderId());
        if ($secret === '') {
            return [
                'status' => 'pending',
                'redirect_url' => null,
                'external_id' => null,
                'payment_reference' => $orderNumber,
                'spayd_payload' => null,
                'meta' => ['error' => 'Stripe not configured (secret key)', 'provider' => 'stripe'],
            ];
        }

        $baseUrl = rtrim((string) $this->config->get('app.url', 'http://127.0.0.1:8080'), '/');
        $returnBase = rtrim((string) ($context['return_url'] ?? $baseUrl), '/');
        $cents = max(1, (int) round(((float) $intent->amount()) * 100));
        $currency = strtolower($intent->currency());

        $params = [
            'mode' => 'payment',
            'success_url' => $returnBase . '/api/v1/payments/notify/stripe?session_id={CHECKOUT_SESSION_ID}&result=success',
            'cancel_url' => $returnBase . '/api/v1/payments/notify/stripe?session_id={CHECKOUT_SESSION_ID}&result=cancel',
            'client_reference_id' => mb_substr($orderNumber, 0, 200),
            'line_items[0][quantity]' => '1',
            'line_items[0][price_data][currency]' => $currency,
            'line_items[0][price_data][unit_amount]' => (string) $cents,
            'line_items[0][price_data][product_data][name]' => mb_substr('Objednávka ' . $orderNumber, 0, 120),
            'metadata[order_number]' => $orderNumber,
            'metadata[order_id]' => (string) ($context['order_id'] ?? $intent->orderId()),
            'metadata[intent_hint]' => $intent->id(),
            'payment_intent_data[metadata][order_number]' => $orderNumber,
        ];

        if (!empty($context['customer_email'])) {
            $params['customer_email'] = (string) $context['customer_email'];
        }

        // Apple Pay / Google Pay jsou u Stripe Checkout automaticky, pokud jsou povolené v Dashboardu.
        $wallet = strtoupper($method->code());
        if (str_contains($wallet, 'APPLE') || str_contains($wallet, 'GOOGLE')) {
            $params['payment_method_types[0]'] = 'card';
        }

        $response = $this->request('POST', '/checkout/sessions', $secret, $params);
        if (($response['_http'] ?? 0) >= 400 || empty($response['id']) || empty($response['url'])) {
            return [
                'status' => 'failed',
                'redirect_url' => null,
                'external_id' => null,
                'payment_reference' => $orderNumber,
                'spayd_payload' => null,
                'meta' => [
                    'provider' => 'stripe',
                    'error' => (string) ($response['error']['message'] ?? 'Stripe Checkout Session failed'),
                    'raw' => $response,
                ],
            ];
        }

        return [
            'status' => 'redirect',
            'redirect_url' => (string) $response['url'],
            'external_id' => (string) $response['id'],
            'payment_reference' => $orderNumber,
            'spayd_payload' => null,
            'meta' => [
                'provider' => 'stripe',
                'payment_intent' => $response['payment_intent'] ?? null,
                'raw' => [
                    'id' => $response['id'],
                    'url' => $response['url'],
                    'payment_status' => $response['payment_status'] ?? null,
                ],
            ],
        ];
    }

    public function handleNotify(array $payload): array
    {
        // Prefer verified webhook event
        if (isset($payload['type'], $payload['data']['object']) && is_array($payload['data']['object'])) {
            $object = $payload['data']['object'];
            $type = (string) $payload['type'];
            $id = (string) ($object['id'] ?? '');
            $ref = (string) ($object['client_reference_id'] ?? $object['metadata']['order_number'] ?? '');

            $status = match ($type) {
                'checkout.session.completed', 'checkout.session.async_payment_succeeded' => (
                    (($object['payment_status'] ?? '') === 'paid' || ($object['status'] ?? '') === 'complete')
                        ? 'paid'
                        : 'pending'
                ),
                'checkout.session.expired', 'checkout.session.async_payment_failed' => 'cancelled',
                'payment_intent.succeeded' => 'paid',
                'payment_intent.payment_failed', 'payment_intent.canceled' => 'failed',
                default => null,
            };

            if ($status === null) {
                return [
                    'ok' => true,
                    'status' => 'pending',
                    'external_id' => $id !== '' ? $id : null,
                    'message' => 'Stripe event ignored: ' . $type,
                    'ref_id' => $ref,
                ];
            }

            return [
                'ok' => true,
                'status' => $status,
                'external_id' => $id !== '' ? $id : null,
                'message' => 'Stripe event: ' . $type,
                'ref_id' => $ref,
            ];
        }

        // Browser return — poll session
        $sessionId = (string) ($payload['session_id'] ?? $payload['id'] ?? '');
        if ($sessionId === '') {
            return ['ok' => false, 'status' => null, 'external_id' => null, 'message' => 'Missing Stripe session id'];
        }

        $secret = trim((string) $this->settings->get('payments.stripe_secret', ''));
        if ($secret === '') {
            return ['ok' => false, 'status' => null, 'external_id' => $sessionId, 'message' => 'Stripe not configured'];
        }

        $session = $this->request('GET', '/checkout/sessions/' . rawurlencode($sessionId), $secret);
        if (($session['_http'] ?? 0) >= 400 || empty($session['id'])) {
            return [
                'ok' => false,
                'status' => null,
                'external_id' => $sessionId,
                'message' => 'Stripe session fetch failed',
            ];
        }

        $resultHint = strtolower((string) ($payload['result'] ?? ''));
        $paymentStatus = (string) ($session['payment_status'] ?? '');
        $mapped = match (true) {
            $paymentStatus === 'paid' => 'paid',
            $resultHint === 'cancel' => 'cancelled',
            ($session['status'] ?? '') === 'expired' => 'cancelled',
            default => 'pending',
        };

        return [
            'ok' => true,
            'status' => $mapped,
            'external_id' => (string) $session['id'],
            'message' => 'Stripe session: ' . $paymentStatus,
            'ref_id' => (string) ($session['client_reference_id'] ?? ''),
            'raw' => $session,
        ];
    }

    /**
     * Verify Stripe-Signature header and decode event JSON.
     *
     * @return array<string, mixed>|null
     */
    public function parseWebhook(string $payload, string $signatureHeader): ?array
    {
        $secret = trim((string) $this->settings->get('payments.stripe_webhook_secret', ''));
        if ($secret === '' || $signatureHeader === '') {
            return null;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $item) {
            [$k, $v] = array_pad(explode('=', trim($item), 2), 2, '');
            $parts[$k] = $v;
        }
        $timestamp = (string) ($parts['t'] ?? '');
        $sig = (string) ($parts['v1'] ?? '');
        if ($timestamp === '' || $sig === '') {
            return null;
        }
        if (abs(time() - (int) $timestamp) > 300) {
            return null;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, scalar|null> $form
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, string $secret, array $form = []): array
    {
        $headers = [
            'Authorization: Bearer ' . $secret,
            'Accept: application/json',
        ];
        $body = null;
        if ($method !== 'GET') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $body = http_build_query($form);
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $body ?? '',
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents(self::API . $path, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        $decoded = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($decoded)) {
            $decoded = ['message' => is_string($raw) ? $raw : 'Stripe HTTP failed'];
        }
        $decoded['_http'] = $status;

        return $decoded;
    }
}
