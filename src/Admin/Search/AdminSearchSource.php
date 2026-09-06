<?php

declare(strict_types=1);

namespace Gereq\Admin\Search;

/**
 * Zdroj živého hledání — registruje se z modulu přes adminSearch()->add(...).
 *
 * @phpstan-type SearchFn callable(string, int): list<AdminSearchHit>
 */
final class AdminSearchSource
{
    /** @var SearchFn */
    private $search;

    /**
     * @param list<string> $fields dokumentace / UI hint (plate, vin, name, …)
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
    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $hits = ($this->search)($query, $this->limit);

        return array_values(array_filter(
            $hits,
            static fn ($hit): bool => $hit instanceof AdminSearchHit,
        ));
    }
}
