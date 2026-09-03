<?php

declare(strict_types=1);

namespace Modules\Customers\Application;

interface ContactsProvider
{
    public function find(string $id): ?ContactReference;

    /** @return list<ContactReference> */
    public function search(string $query, int $limit = 20): array;
}
