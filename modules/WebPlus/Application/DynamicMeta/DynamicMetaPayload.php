<?php

declare(strict_types=1);

namespace Modules\WebPlus\Application\DynamicMeta;

/** Entity-derived SEO/GEO/OG fields for a concrete public path. */
final class DynamicMetaPayload
{
    /**
     * @param array<string, string|int|float|null> $vars interpolation tokens
     * @param array<string, mixed>|null $jsonLd schema.org object
     */
    public function __construct(
        public readonly string $source,
        public readonly string $path,
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $image = null,
        public readonly ?string $geoRegion = null,
        public readonly ?string $geoPlacename = null,
        public readonly ?string $geoPosition = null,
        public readonly ?string $aiSummary = null,
        public readonly ?array $jsonLd = null,
        public readonly array $vars = [],
        public readonly ?string $canonical = null,
        public readonly ?string $ogType = null,
        public readonly ?\DateTimeInterface $updatedAt = null,
    ) {
    }
}
