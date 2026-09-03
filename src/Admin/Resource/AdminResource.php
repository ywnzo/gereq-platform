<?php

declare(strict_types=1);

namespace Gereq\Admin\Resource;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class AdminResource
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $permission,
        public readonly ?string $icon = null,
        public readonly ?string $singular = null,
        public readonly ?string $controller = null,
    ) {
    }
}
