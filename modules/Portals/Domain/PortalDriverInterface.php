<?php

declare(strict_types=1);

namespace Modules\Portals\Domain;

interface PortalDriverInterface
{
    public function key(): string;

    /**
     * Build export payload from a serialized listing (no remote call yet).
     *
     * @param array<string, mixed> $listing
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function buildPayload(array $listing, array $config): array;
}
