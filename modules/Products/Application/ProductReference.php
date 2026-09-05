<?php

declare(strict_types=1);

namespace Modules\Products\Application;

final class ProductReference
{
    public function __construct(
        public readonly string $id,
        public readonly string $sku,
        public readonly string $name,
        public readonly string $status,
    ) {
    }
}
