<?php

declare(strict_types=1);

namespace Modules\Portals\Application\Drivers;

use Modules\Portals\Domain\PortalDriverInterface;

final class BezrealitkyDriver implements PortalDriverInterface
{
    public function key(): string
    {
        return 'bezrealitky';
    }

    public function buildPayload(array $listing, array $config): array
    {
        return [
            'portal' => $this->key(),
            'external_ready' => false,
            'note' => 'Stub: připraveno na Bezrealitky API.',
            'api_key_set' => trim((string) ($config['api_key'] ?? '')) !== '',
            'listing' => [
                'id' => $listing['id'] ?? null,
                'title' => $listing['title'] ?? null,
                'price' => $listing['price'] ?? null,
                'type' => $listing['property_type'] ?? null,
                'disposition' => $listing['rooms'] ?? null,
                'location' => [
                    'city' => $listing['city'] ?? null,
                    'lat' => $listing['latitude'] ?? null,
                    'lng' => $listing['longitude'] ?? null,
                ],
                'images' => $listing['images'] ?? [],
            ],
        ];
    }
}
