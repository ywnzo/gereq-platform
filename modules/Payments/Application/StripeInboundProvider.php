<?php

declare(strict_types=1);

namespace Modules\Payments\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Api\InboundWebhookProviderInterface;
use Modules\Orders\Domain\Order;
use Modules\Payments\Application\Gateways\StripeGateway;
use Modules\Payments\Domain\PaymentIntent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class StripeInboundProvider implements InboundWebhookProviderInterface
{
    public function __construct(
        private readonly StripeGateway $gateway,
        private readonly PaymentService $payments,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function name(): string
    {
        return 'stripe';
    }

    public function verify(Request $request): bool
    {
        $sig = (string) $request->headers->get('Stripe-Signature', '');
        if ($sig === '') {
            return true; // browser return
        }

        return $this->gateway->parseWebhook((string) $request->getContent(), $sig) !== null;
    }

    public function handle(Request $request): Response
    {
        $sig = (string) $request->headers->get('Stripe-Signature', '');
        $raw = (string) $request->getContent();
        if ($sig !== '' && $raw !== '') {
            $event = $this->gateway->parseWebhook($raw, $sig);
            if ($event === null) {
                return ApiResponse::error('invalid', 'Invalid Stripe signature', [], 400);
            }
            $result = $this->gateway->handleNotify($event);
        } else {
            $result = $this->gateway->handleNotify(array_merge($request->query->all(), $request->request->all()));
        }

        if (!$result['ok']) {
            return ApiResponse::error('invalid', $result['message'], [], 400);
        }

        $intent = $this->findIntent($result['external_id'] ?? null, $result['ref_id'] ?? null);
        if ($intent === null) {
            return ApiResponse::data(['ok' => true, 'ignored' => true]);
        }

        if (($result['status'] ?? '') === 'paid' && $intent->status() !== 'paid') {
            $this->payments->markPaid($intent, $result['external_id'] ?? null);
        } elseif (in_array($result['status'] ?? '', ['cancelled', 'failed'], true)) {
            $intent->setStatus($result['status'] === 'cancelled' ? 'cancelled' : 'failed');
            $this->em->flush();
        }

        return ApiResponse::data([
            'ok' => true,
            'status' => $result['status'],
            'intent_id' => $intent->id(),
            'external_id' => $result['external_id'],
        ]);
    }

    private function findIntent(?string $externalId, mixed $refId): ?PaymentIntent
    {
        if ($externalId) {
            $byExt = $this->em->getRepository(PaymentIntent::class)->findOneBy([
                'provider' => 'stripe',
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
            ['orderId' => $order->id(), 'provider' => 'stripe'],
            ['createdAt' => 'DESC'],
        );

        return $intent instanceof PaymentIntent ? $intent : null;
    }
}
