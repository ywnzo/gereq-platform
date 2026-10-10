<?php

declare(strict_types=1);

namespace Gereq\Customers\Application;

use Doctrine\ORM\QueryBuilder;

final class UnrestrictedCustomerScope implements CustomerScope
{
    public function scopedCustomerIds(mixed $user): ?array
    {
        return null;
    }

    public function applyCustomerScope(QueryBuilder $qb, string $alias, mixed $user): void
    {
    }
}
