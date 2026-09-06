<?php

declare(strict_types=1);

namespace Modules\Portals\Application\Drivers;

use Modules\Portals\Domain\PortalDriverInterface;

final class CustomPortalDriver implements PortalDriverInterface
{
    public function key(): string
    {
        return 'custom';
    }

    public function buildPayload(array $listing, array $config): array
    {
        return [
            'portal' => $this->key(),
            'external_ready' => false,
            'endpoint' => (string) ($config['endpoint'] ?? ''),
            'listing' => $listing,
            'meta' => $config['meta'] ?? [],
        ];
    }
}
