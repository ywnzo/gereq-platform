<?php

declare(strict_types=1);

namespace Gereq\Customers\Application;

final class ContactReference
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $company = null,
    ) {
    }
}
