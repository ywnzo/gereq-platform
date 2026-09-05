<?php

declare(strict_types=1);

namespace Modules\Payments\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Setting\Settings;
use Modules\Orders\Domain\Order;
use Modules\Payments\Application\Gateways\ComgateGateway;
use Modules\Payments\Application\Gateways\GoPayGateway;
use Modules\Payments\Application\Gateways\StripeGateway;
use Modules\Payments\Application\PaymentService;
use Modules\Payments\Domain\PaymentIntent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/** Public payment provider callbacks (Comgate / GoPay / Stripe). No Bearer auth. */
final class PaymentNotifyController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly ComgateGateway $comgate,
        private readonly GoPayGateway $gopay,
        private readonly StripeGateway $stripe,
        private readonly PaymentService $payments,
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function comgate(Request $request): Response
    {
        $payload = array_merge($request->query->all(), $request->request->all());
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            parse_str($raw, $parsed);
            if (is_array($parsed)) {
                $payload = array_merge($payload, $parsed);
            }
        }

        if (empty($payload['secret'])) {
            $transId = (string) ($payload['transId'] ?? $payload['id'] ?? '');
            if ($transId === '') {
                return ApiResponse::error('invalid', 'Missing transId', [], 400);
            }
            $payload['transId'] = $transId;
            $payload['secret'] = (string) $this->settings->get('payments.comgate_secret', '');
        }

        $result = $this->comgate->handleNotify($payload);
        if (!$result['ok']) {
            return ApiResponse::error('invalid', $result['message'], [], 400);
        }

        $intent = $this->findIntent('comgate', $result['external_id'] ?? null, $result['ref_id'] ?? ($payload['refId'] ?? null));
        if ($intent === null) {
            return ApiResponse::error('not_found', 'Payment intent not found', [], 404);
        }

        $this->applyStatus($intent, $result['status'] ?? null, $result['external_id'] ?? null);

        return ApiResponse::data([
            'ok' => true,
            'provider' => 'comgate',
            'status' => $result['status'],
            'intent_id' => $intent->id(),
            'external_id' => $result['external_id'],
        ]);
    }

    public function gopay(Request $request): Response
    {
        $payload = array_merge($request->query->all(), $request->request->all());
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $payload = array_merge($payload, $json);
            }
        }

        $result = $this->gopay->handleNotify($payload);
        if (!$result['ok']) {
            return ApiResponse::error('invalid', $result['message'], [], 400);
        }

        $intent = $this->findIntent('gopay', $result['external_id'] ?? null, $result['ref_id'] ?? null);
        if ($intent === null) {
            return ApiResponse::error('not_found', 'Payment intent not found', [], 404);
        }

        $this->applyStatus($intent, $result['status'] ?? null, $result['external_id'] ?? null);

        return ApiResponse::data([
            'ok' => true,
            'provider' => 'gopay',
            'status' => $result['status'],
            'intent_id' => $intent->id(),
            'external_id' => $result['external_id'],
        ]);
    }

    public function stripe(Request $request): Response
    {
        $sig = (string) $request->headers->get('Stripe-Signature', '');
        $raw = (string) $request->getContent();

        if ($sig !== '' && $raw !== '') {
            $event = $this->stripe->parseWebhook($raw, $sig);
            if ($event === null) {
                return ApiResponse::error('invalid', 'Invalid Stripe signature', [], 400);
            }
            $result = $this->stripe->handleNotify($event);
        } else {
            $payload = array_merge($request->query->all(), $request->request->all());
            $result = $this->stripe->handleNotify($payload);
        }

        if (!$result['ok']) {
            return ApiResponse::error('invalid', $result['message'], [], 400);
        }

        $intent = $this->findIntent('stripe', $result['external_id'] ?? null, $result['ref_id'] ?? null);
        if ($intent === null) {
            // Webhooks can arrive before we persist mapping — acknowledge to avoid retries storms for unknown events
            if ($sig !== '') {
                return ApiResponse::data(['ok' => true, 'ignored' => true, 'message' => 'Intent not found yet']);
            }

            return ApiResponse::error('not_found', 'Payment intent not found', [], 404);
        }

        $this->applyStatus($intent, $result['status'] ?? null, $result['external_id'] ?? null);

        return ApiResponse::data([
            'ok' => true,
            'provider' => 'stripe',
            'status' => $result['status'],
            'intent_id' => $intent->id(),
            'external_id' => $result['external_id'],
        ]);
    }

    private function applyStatus(PaymentIntent $intent, ?string $status, ?string $externalId): void
    {
        if ($status === 'paid' && $intent->status() !== 'paid') {
            $this->payments->markPaid($intent, $externalId);
            return;
        }
        if (in_array($status, ['cancelled', 'failed'], true)) {
            $intent->setStatus($status === 'cancelled' ? 'cancelled' : 'failed');
            if ($externalId) {
                $intent->setExternalId($externalId);
            }
            $this->em->flush();
        }
    }

    private function findIntent(string $provider, ?string $externalId, mixed $refId): ?PaymentIntent
    {
        if ($externalId !== null && $externalId !== '') {
            $byExt = $this->em->getRepository(PaymentIntent::class)->findOneBy([
                'provider' => $provider,
                'externalId' => $externalId,
            ]);
            if ($byExt instanceof PaymentIntent) {
                return $byExt;
            }
        }

        $ref = is_string($refId) ? $refId : '';
        if ($ref === '') {
            return null;
        }

        $order = $this->em->getRepository(Order::class)->findOneBy(['number' => $ref]);
        if (!$order instanceof Order) {
            return null;
        }

        $intent = $this->em->getRepository(PaymentIntent::class)->findOneBy(
            ['orderId' => $order->id(), 'provider' => $provider],
            ['createdAt' => 'DESC'],
        );

        return $intent instanceof PaymentIntent ? $intent : null;
    }
}
