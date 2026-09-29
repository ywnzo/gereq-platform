<?php

declare(strict_types=1);

namespace Gereq\Webhooks\Application;

use Gereq\Api\InboundWebhookProviderInterface;

/** Registry of inbound webhook providers (empty stub for now). */
final class InboundWebhookRegistry
{
    /** @var array<string, InboundWebhookProviderInterface> */
    private array $providers = [];

    public function register(InboundWebhookProviderInterface $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    public function get(string $name): ?InboundWebhookProviderInterface
    {
        return $this->providers[$name] ?? null;
    }

    /** @return list<InboundWebhookProviderInterface> */
    public function all(): array
    {
        return array_values($this->providers);
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }
}
