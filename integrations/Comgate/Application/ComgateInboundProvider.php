<?php

declare(strict_types=1);

namespace Integrations\Comgate\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Api\InboundWebhookProviderInterface;
use Gereq\Setting\Settings;
use Modules\Shop\Submodules\Orders\Domain\Order;
use Modules\Shop\Submodules\Payments\Application\PaymentService;
use Modules\Shop\Submodules\Payments\Domain\PaymentIntent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Inbound Comgate server notify (must include merchant secret). */
final class ComgateInboundProvider implements InboundWebhookProviderInterface
{
    public function __construct(
        private readonly ComgateGateway $gateway,
        private readonly PaymentService $payments,
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
    ) {
    }

    public function name(): string
    {
        return 'comgate';
    }

    public function verify(Request $request): bool
    {
        $payload = $this->payload($request);
        $incoming = (string) ($payload['secret'] ?? '');
        $expected = trim((string) $this->settings->get('payments.comgate_secret', ''));

        return $expected !== '' && $incoming !== '' && hash_equals($expected, $incoming);
    }

    public function handle(Request $request): Response
    {
        $payload = $this->payload($request);
        if (empty($payload['secret'])) {
            return ApiResponse::error('invalid', 'Missing Comgate secret', [], 400);
        }

        $result = $this->gateway->handleNotify($payload);
        if (!$result['ok']) {
            return ApiResponse::error('invalid', $result['message'], [], 400);
        }

        $intent = $this->findIntent($result['external_id'] ?? null, $result['ref_id'] ?? ($payload['refId'] ?? null));
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

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $payload = array_merge($request->query->all(), $request->request->all());
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            parse_str($raw, $parsed);
            if (is_array($parsed)) {
                $payload = array_merge($payload, $parsed);
            }
        }

        return $payload;
    }

    private function findIntent(?string $externalId, mixed $refId): ?PaymentIntent
    {
        if ($externalId !== null && $externalId !== '') {
            $byExt = $this->em->getRepository(PaymentIntent::class)->findOneBy([
                'provider' => 'comgate',
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
            ['orderId' => $order->id(), 'provider' => 'comgate'],
            ['createdAt' => 'DESC'],
        );

        return $intent instanceof PaymentIntent ? $intent : null;
    }
}
