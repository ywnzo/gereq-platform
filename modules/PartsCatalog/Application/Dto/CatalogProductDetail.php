<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Application\Dto;

/**
 * Detail dílu z Jama (popis, parametry, OE, galerie).
 */
final class CatalogProductDetail
{
    /**
     * @param list<array{label: string, value: string}> $properties
     * @param list<array{brand: string, number: string}> $references
     * @param list<string> $imageUrls local /media/... or remote
     * @param list<string> $mediaIds
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $oem = null,
        public readonly ?string $brand = null,
        public readonly ?string $price = null,
        public readonly ?string $description = null,
        public readonly array $properties = [],
        public readonly array $references = [],
        public readonly array $imageUrls = [],
        public readonly array $mediaIds = [],
        public readonly ?string $sourceUrl = null,
    ) {
    }
}
