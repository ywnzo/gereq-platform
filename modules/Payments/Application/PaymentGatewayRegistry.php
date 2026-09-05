<?php

declare(strict_types=1);

namespace Modules\Payments\Application;

/**
 * Registry of payment gateway drivers.
 * External providers (Comgate, GoPay…) register themselves from their adapter modules.
 */
final class PaymentGatewayRegistry
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    public function register(PaymentGatewayInterface $gateway): void
    {
        $this->gateways[$gateway->key()] = $gateway;
    }

    public function get(string $key): ?PaymentGatewayInterface
    {
        return $this->gateways[strtolower(trim($key))] ?? null;
    }

    public function getOrManual(string $key): PaymentGatewayInterface
    {
        return $this->get($key) ?? $this->get('manual')
            ?? throw new \RuntimeException('payments.error.no_gateway');
    }

    /** @return list<PaymentGatewayInterface> */
    public function all(): array
    {
        return array_values($this->gateways);
    }
}
