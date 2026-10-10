<?php

declare(strict_types=1);

namespace Modules\Events\Application\Import;

/** Normalized event row ready for upsert into CatalogEvent. */
final class NormalizedEvent
{
    /**
     * @param list<array<string, mixed>> $gallery Gallery items (kind/url/title…)
     */
    public function __construct(
        public readonly string $source,
        public readonly string $sourceId,
        public readonly string $title,
        public readonly string $region,
        public readonly string $place,
        public readonly \DateTimeImmutable $startsAt,
        public readonly ?\DateTimeImmutable $endsAt = null,
        public readonly string $category = 'Kultura',
        public readonly string $description = '',
        public readonly ?string $longDescription = null,
        public readonly ?string $price = null,
        public readonly ?string $organizer = null,
        public readonly ?string $externalUrl = null,
        public readonly ?string $imageUrl = null,
        public readonly string $timeLabel = '',
        public readonly string $color = 'purple',
        public readonly array $gallery = [],
        public readonly ?string $capacity = null,
    ) {
    }
}
