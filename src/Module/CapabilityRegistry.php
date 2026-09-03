<?php

declare(strict_types=1);

namespace Gereq\Module;

final class CapabilityRegistry
{
    /** @var array<string, list<array{provider: string, implementation: object}>> */
    private array $capabilities = [];

    public function register(string $capability, string $providerId, object $implementation): void
    {
        $this->capabilities[$capability][] = [
            'provider' => $providerId,
            'implementation' => $implementation,
        ];
    }

    public function has(string $capability): bool
    {
        return isset($this->capabilities[$capability]) && $this->capabilities[$capability] !== [];
    }

    public function get(string $capability): object
    {
        if (!$this->has($capability)) {
            throw new \RuntimeException(sprintf('Capability "%s" není k dispozici.', $capability));
        }

        return $this->capabilities[$capability][0]['implementation'];
    }

    /**
     * @return list<string>
     */
    public function providers(string $capability): array
    {
        return array_map(
            static fn (array $item): string => $item['provider'],
            $this->capabilities[$capability] ?? []
        );
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->capabilities);
    }
}
