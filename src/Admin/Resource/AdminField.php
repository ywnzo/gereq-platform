<?php

declare(strict_types=1);

namespace Gereq\Admin\Resource;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class AdminField
{
    public function __construct(
        public readonly string $type = 'text',
        public readonly string $label = '',
        public readonly bool $searchable = false,
        public readonly bool $sortable = false,
        public readonly bool $filterable = false,
        public readonly bool $required = false,
        public readonly bool $showOnIndex = true,
        public readonly bool $showOnDetail = true,
        public readonly bool $showOnForm = true,
    ) {
    }
}
