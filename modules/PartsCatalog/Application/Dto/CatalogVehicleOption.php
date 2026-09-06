<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Application\Dto;

final class CatalogVehicleOption
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ?string $hint = null,
        public readonly ?string $url = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'hint' => $this->hint,
            'url' => $this->url,
        ];
    }
}
