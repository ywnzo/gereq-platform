<?php

declare(strict_types=1);

namespace Modules\Shop\Application;

final class ShippingCarrierRegistry
{
    /** @var array<string, ShippingCarrierInterface> */
    private array $carriers = [];

    public function register(ShippingCarrierInterface $carrier): void
    {
        $this->carriers[$carrier->key()] = $carrier;
    }

    /** Register the same driver under an alternate key (e.g. packeta ↔ zasilkovna). */
    public function registerAlias(string $alias, ShippingCarrierInterface $carrier): void
    {
        $this->carriers[strtolower(trim($alias))] = $carrier;
    }

    public function get(string $key): ?ShippingCarrierInterface
    {
        return $this->carriers[strtolower(trim($key))] ?? null;
    }

    public function getOrManual(string $key): ShippingCarrierInterface
    {
        return $this->get($key) ?? $this->get('manual')
            ?? throw new \RuntimeException('shop.error.no_carrier');
    }

    /** @return list<ShippingCarrierInterface> */
    public function all(): array
    {
        return array_values($this->carriers);
    }
}
