<?php

declare(strict_types=1);

namespace Gereq\Customers\Application;

use Doctrine\ORM\QueryBuilder;

/** Optional broker/tenant filter over customers. Listings is not required. */
interface CustomerScope
{
    /** @return list<string>|null null = no restriction */
    public function scopedCustomerIds(mixed $user): ?array;

    public function applyCustomerScope(QueryBuilder $qb, string $alias, mixed $user): void;
}
