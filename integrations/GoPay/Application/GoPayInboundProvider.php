<?php

declare(strict_types=1);

namespace Integrations\GoPay\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Api\InboundWebhookProviderInterface;
use Modules\Shop\Submodules\Orders\Domain\Order;
use Modules\Shop\Submodules\Payments\Application\PaymentService;
use Modules\Shop\Submodules\Payments\Domain\PaymentIntent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class GoPayInboundProvider implements InboundWebhookProviderInterface
{
    public function __construct(
        private readonly GoPayGateway $gateway,
        private readonly PaymentService $payments,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function name(): string
    {
        return 'gopay';
    }

    public function verify(Request $request): bool
    {
        return $this->paymentIdFromRequest($request) !== '';
    }

    public function handle(Request $request): Response
    {
        if (!$this->verify($request)) {
            return ApiResponse::error('invalid', 'Missing GoPay payment id', [], 400);
        }

        $payload = array_merge($request->query->all(), $request->request->all());
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $payload = array_merge($payload, $json);
            }
        }

        $result = $this->gateway->handleNotify($payload);
        if (!$result['ok']) {
            return ApiResponse::error('invalid', $result['message'], [], 400);
        }

        $intent = $this->findIntent($result['external_id'] ?? null, $result['ref_id'] ?? null);
        if ($intent === null) {
            return ApiResponse::error('not_found', 'Payment intent not found', [], 404);
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

    private function paymentIdFromRequest(Request $request): string
    {
        foreach (['id', 'payment_id'] as $key) {
            $value = trim((string) ($request->query->get($key, '') ?: $request->request->get($key, '')));
            if ($value !== '' && preg_match('/^\d{1,32}$/', $value) === 1) {
                return $value;
            }
        }
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                foreach (['id', 'payment_id'] as $key) {
                    $value = trim((string) ($json[$key] ?? ''));
                    if ($value !== '' && preg_match('/^\d{1,32}$/', $value) === 1) {
                        return $value;
                    }
                }
            }
        }

        return '';
    }

    private function findIntent(?string $externalId, mixed $refId): ?PaymentIntent
    {
        if ($externalId) {
            $byExt = $this->em->getRepository(PaymentIntent::class)->findOneBy([
                'provider' => 'gopay',
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
            ['orderId' => $order->id(), 'provider' => 'gopay'],
            ['createdAt' => 'DESC'],
        );

        return $intent instanceof PaymentIntent ? $intent : null;
    }
}
