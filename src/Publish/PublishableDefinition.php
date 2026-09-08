<?php

declare(strict_types=1);

namespace Gereq\Publish;

final class PublishableDefinition
{
    public function __construct(
        public readonly string $category,
        public readonly string $label,
        public readonly PublishableAdapterInterface $adapter,
        public readonly ?string $module = null,
        public readonly int $sort = 100,
    ) {
    }
}
