<?php

declare(strict_types=1);

namespace Modules\Portals\Application\Drivers;

use Modules\Portals\Domain\PortalDriverInterface;

final class IdnesDriver implements PortalDriverInterface
{
    public function key(): string
    {
        return 'idnes';
    }

    public function buildPayload(array $listing, array $config): array
    {
        return [
            'portal' => $this->key(),
            'external_ready' => false,
            'note' => 'Stub: připraveno na Idnes Reality export.',
            'partner_id' => (string) ($config['partner_id'] ?? ''),
            'listing' => [
                'title' => $listing['title'] ?? null,
                'price' => $listing['price'] ?? null,
                'locality' => trim(($listing['city'] ?? '') . ' ' . ($listing['street'] ?? '')),
                'text' => $listing['description'] ?? null,
                'photos' => $listing['images'] ?? [],
            ],
        ];
    }
}
