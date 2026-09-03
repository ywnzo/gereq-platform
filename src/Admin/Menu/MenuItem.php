<?php

declare(strict_types=1);

namespace Gereq\Admin\Menu;

final class MenuItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ?string $icon = null,
        public readonly ?string $route = null,
        public readonly ?string $url = null,
        public readonly ?string $permission = null,
        public readonly ?string $parent = null,
        public readonly int $order = 50,
        public readonly ?string $badge = null,
        public readonly bool $divider = false,
        public readonly bool $hidden = false,
        public readonly ?string $group = null,
    ) {
    }
}
