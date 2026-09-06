<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Application\Dto;

final class CatalogArticle
{
    /**
     * @param array<string, mixed> $attrs
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $nameOriginal = null,
        public readonly ?string $oem = null,
        public readonly ?string $brand = null,
        public readonly ?string $imageUrl = null,
        public readonly ?string $mediaId = null,
        public readonly array $attrs = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_original' => $this->nameOriginal,
            'oem' => $this->oem,
            'brand' => $this->brand,
            'image_url' => $this->imageUrl,
            'media_id' => $this->mediaId,
            'attrs' => $this->attrs,
        ];
    }
}
