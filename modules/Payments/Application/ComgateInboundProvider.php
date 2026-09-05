<?php

declare(strict_types=1);

namespace Modules\Payments\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Api\InboundWebhookProviderInterface;
use Gereq\Setting\Settings;
use Modules\Orders\Domain\Order;
use Modules\Payments\Application\Gateways\ComgateGateway;
use Modules\Payments\Domain\PaymentIntent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Inbound Comgate notify + browser return callbacks. */
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
        // Secret is validated inside handleNotify; allow empty GET browser returns
        // that only carry id/refId (status poll will re-check with merchant secret).
        return true;
    }

    public function handle(Request $request): Response
    {
        $payload = array_merge($request->query->all(), $request->request->all());
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            parse_str($raw, $parsed);
            if (is_array($parsed)) {
                $payload = array_merge($payload, $parsed);
            }
        }

        // Browser return without secret — poll status by transId
        if (empty($payload['secret']) && !empty($payload['id'])) {
            $payload['transId'] = (string) $payload['id'];
            $payload['secret'] = ''; // handleNotify will fail secret unless we special-case
        }

        $result = $this->resolveStatus($payload);
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

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, status: ?string, external_id: ?string, message: string, ref_id?: string}
     */
    private function resolveStatus(array $payload): array
    {
        if (!empty($payload['secret'])) {
            /** @var array{ok: bool, status: ?string, external_id: ?string, message: string, ref_id?: string} */
            return $this->gateway->handleNotify($payload);
        }

        // Browser redirect: trust only after live status poll (secret from settings inside gateway)
        $transId = (string) ($payload['transId'] ?? $payload['id'] ?? '');
        if ($transId === '') {
            return ['ok' => false, 'status' => null, 'external_id' => null, 'message' => 'Missing transId'];
        }

        $polled = $this->gateway->handleNotify([
            'transId' => $transId,
            'refId' => (string) ($payload['refId'] ?? ''),
            'secret' => (string) $this->settings->get('payments.comgate_secret', ''),
        ]);

        return $polled;
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
