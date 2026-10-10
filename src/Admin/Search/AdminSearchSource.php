<?php

declare(strict_types=1);

namespace Gereq\Admin\Search;

/**
 * Live search source — registered from a module via adminSearch()->add(...).
 *
 * @phpstan-type SearchFn callable(string, int): list<AdminSearchHit>
 */
final class AdminSearchSource
{
    /** @var SearchFn */
    private $search;

    /**
     * @param list<string> $fields documentation / UI hint (plate, vin, name, …)
     * @param SearchFn $search
     */
    public function __construct(
        public readonly string $id,
        public readonly array $fields,
        callable $search,
        public readonly ?string $permission = null,
        public readonly string $group = 'Data',
        public readonly ?string $icon = null,
        public readonly int $limit = 8,
    ) {
        $this->search = $search;
    }

    /**
     * @return list<AdminSearchHit>
     */
    public function search(string $query, ?int $limit = null): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $hits = ($this->search)($query, $limit ?? $this->limit);

        return array_values(array_filter(
            $hits,
            static fn ($hit): bool => $hit instanceof AdminSearchHit,
        ));
    }
}
