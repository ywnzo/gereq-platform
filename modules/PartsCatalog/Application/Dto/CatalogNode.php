<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Application\Dto;

final class CatalogNode
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $nameOriginal = null,
        public readonly bool $hasChildren = false,
        public readonly bool $hasProducts = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_original' => $this->nameOriginal,
            'has_children' => $this->hasChildren,
            'has_products' => $this->hasProducts,
        ];
    }
}
