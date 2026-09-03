<?php

declare(strict_types=1);

namespace Gereq\Admin\CommandPalette;

final class PaletteCommand
{
    /**
     * @param list<string> $keywords
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $url,
        public readonly ?string $icon = null,
        public readonly ?string $permission = null,
        public readonly array $keywords = [],
        public readonly string $group = 'Navigace',
    ) {
    }
}
