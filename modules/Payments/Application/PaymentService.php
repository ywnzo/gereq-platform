<?php

declare(strict_types=1);

namespace Modules\Payments\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Event\EventBus;
use Modules\Orders\Domain\Order;
use Modules\Payments\Domain\PaymentIntent;
use Modules\Payments\Domain\PaymentMethod;

final class PaymentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaymentsProvider $methods,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly EventBus $events,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function createForOrder(Order $order, string $methodCode, array $context = []): PaymentIntent
    {
        $method = $this->methods->findByCode($methodCode);
        if (!$method instanceof PaymentMethod || !$method->isActive()) {
            throw new \InvalidArgumentException('payments.error.invalid_method');
        }

        $providerKey = strtolower((string) ($method->provider() ?: 'manual'));
        $gateway = $this->gateways->get($providerKey) ?? $this->gateways->getOrManual('manual');

        $intent = new PaymentIntent(
            $order->id(),
            $method->code(),
            $order->total(),
            $order->currency(),
            $gateway->key(),
        );
        $intent->setPaymentMethodId($method->id());
        $this->em->persist($intent);

        $result = $gateway->createPayment($intent, $method, array_merge($context, [
            'order_number' => $order->number(),
            'order_id' => $order->id(),
        ]));

        $intent->setStatus($result['status'] === 'redirect' ? 'redirect' : ($result['status'] ?? 'pending'));
        $intent->setRedirectUrl($result['redirect_url'] ?? null);
        $intent->setExternalId($result['external_id'] ?? null);
        $intent->setPaymentReference($result['payment_reference'] ?? $order->number());
        $intent->setSpaydPayload($result['spayd_payload'] ?? null);
        $intent->setMeta($result['meta'] ?? []);
        $this->em->flush();

        $payload = $this->serialize($intent);
        $this->events->emit('payment.intent_created', ['type' => 'payment_intent', 'id' => $intent->id()], $payload);

        return $intent;
    }

    public function markPaid(PaymentIntent $intent, ?string $externalId = null): void
    {
        $intent->markPaid($externalId);
        $order = $this->em->find(Order::class, $intent->orderId());
        if ($order instanceof Order) {
            $order->setPaymentStatus('paid');
        }
        $this->em->flush();
        $this->events->emit('payment.intent_paid', ['type' => 'payment_intent', 'id' => $intent->id()], $this->serialize($intent));
    }

    /** @return array<string, mixed> */
    public function serialize(PaymentIntent $intent): array
    {
        return [
            'id' => $intent->id(),
            'order_id' => $intent->orderId(),
            'method_code' => $intent->methodCode(),
            'provider' => $intent->provider(),
            'amount' => $intent->amount(),
            'currency' => $intent->currency(),
            'status' => $intent->status(),
            'external_id' => $intent->externalId(),
            'redirect_url' => $intent->redirectUrl(),
            'payment_reference' => $intent->paymentReference(),
            'spayd_payload' => $intent->spaydPayload(),
            'meta' => $intent->meta(),
            'paid_at' => $intent->paidAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $intent->createdAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
