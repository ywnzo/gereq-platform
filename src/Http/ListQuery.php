<?php

declare(strict_types=1);

namespace Gereq\Http;

use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared list/table query state (search, sort, pagination, filters).
 */
final class ListQuery
{
    /**
     * @param list<string> $sortable
     * @param array<string, string> $filters
     */
    public function __construct(
        public readonly string $search,
        public readonly string $sort,
        public readonly string $dir,
        public readonly int $page,
        public readonly int $perPage,
        public readonly array $sortable,
        public readonly array $filters = [],
    ) {
    }

    /**
     * @param list<string> $sortable
     * @param list<string> $filterKeys query keys to preserve (e.g. status)
     */
    public static function fromRequest(
        Request $request,
        array $sortable,
        string $defaultSort,
        string $defaultDir = 'asc',
        int $perPage = 20,
        array $filterKeys = [],
        int $maxPerPage = 100,
    ): self {
        $search = trim((string) $request->query->get('q', ''));
        $sort = (string) $request->query->get('sort', $defaultSort);
        if (!in_array($sort, $sortable, true)) {
            $sort = $defaultSort;
        }
        $dir = strtolower((string) $request->query->get('dir', $defaultDir)) === 'desc' ? 'desc' : 'asc';
        $page = max(1, (int) $request->query->get('page', 1));
        $requested = $request->query->has('per_page')
            ? (int) $request->query->get('per_page')
            : $perPage;
        $resolvedPerPage = min(max(1, $maxPerPage), max(1, $requested));

        $filters = [];
        foreach ($filterKeys as $key) {
            $filters[$key] = trim((string) $request->query->get($key, ''));
        }

        return new self($search, $sort, $dir, $page, $resolvedPerPage, $sortable, $filters);
    }

    public function direction(): string
    {
        return $this->dir === 'desc' ? 'DESC' : 'ASC';
    }

    /**
     * Apply typo/diacritics-tolerant search (self-repair) to a Doctrine query.
     *
     * @param list<string> $fields
     */
    public function applySearch(QueryBuilder $qb, string $alias, array $fields): void
    {
        if ($this->search === '') {
            return;
        }

        self_repair_search($qb, $alias, $fields, $this->search);
    }

    public function filter(string $key, string $default = ''): string
    {
        return $this->filters[$key] ?? $default;
    }

    public function pages(int $total): int
    {
        return max(1, (int) ceil(max(0, $total) / $this->perPage));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** @param array<string, scalar|null> $overrides */
    public function params(array $overrides = []): array
    {
        $params = array_merge(
            [
                'q' => $this->search,
                'sort' => $this->sort,
                'dir' => $this->dir,
                'page' => $this->page,
                'per_page' => $this->perPage,
            ],
            $this->filters,
            $overrides,
        );

        foreach ($params as $key => $value) {
            if ($value === null || $value === '' || $value === false) {
                unset($params[$key]);
            }
        }

        return $params;
    }

    /** @return array<string, mixed> */
    public function viewData(int $total): array
    {
        return [
            'search' => $this->search,
            'sort' => $this->sort,
            'dir' => $this->dir,
            'page' => $this->page,
            'perPage' => $this->perPage,
            'total' => $total,
            'pages' => $this->pages($total),
            'list_query' => $this,
        ];
    }
}
