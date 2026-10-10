<?php

declare(strict_types=1);

namespace Modules\Authorization\Application;

final class OAuthProviderRegistry
{
    /** @var array<string, OAuthProviderInterface> */
    private array $providers = [];

    public function register(OAuthProviderInterface $provider): void
    {
        $this->providers[$provider->id()] = $provider;
    }

    public function get(string $id): ?OAuthProviderInterface
    {
        return $this->providers[$id] ?? null;
    }

    /** @return list<OAuthProviderInterface> */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /** @return array<string, mixed> */
    public function publicStatus(): array
    {
        $providers = [];
        $flags = [];
        foreach ($this->all() as $provider) {
            $ok = $provider->configured();
            $flags[$provider->id()] = $ok;
            if ($ok) {
                $providers[] = [
                    'id' => $provider->id(),
                    'label' => $provider->label(),
                ];
            }
        }

        return array_merge($flags, ['providers' => $providers]);
    }
}
