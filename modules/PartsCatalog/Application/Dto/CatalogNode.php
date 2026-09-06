<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Application\Dto;

final class CatalogNode
{
    /**
     * @param list<self> $children Preloaded one level of subgroups (empty if not loaded / leaf).
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $nameOriginal = null,
        public readonly bool $hasChildren = false,
        public readonly bool $hasProducts = false,
        public readonly array $children = [],
        public readonly string $icon = 'pc-parts',
    ) {
    }

    /** @param list<self> $children */
    public function withChildren(array $children): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->nameOriginal,
            $this->hasChildren,
            $this->hasProducts,
            $children,
            $this->icon,
        );
    }

    public function withPresentation(string $name, string $icon): self
    {
        return new self(
            $this->id,
            $name,
            $this->nameOriginal,
            $this->hasChildren,
            $this->hasProducts,
            $this->children,
            $icon,
        );
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
            'icon' => $this->icon,
            'children' => array_map(static fn (self $n): array => $n->toArray(), $this->children),
        ];
    }
}
