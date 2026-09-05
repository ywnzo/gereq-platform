<?php

declare(strict_types=1);

namespace Gereq\Api;

use Symfony\Component\HttpFoundation\Request;

final class ApiQuery
{
    /** @param list<string> $searchable */
    /** @param list<string> $sortable */
    /** @param list<string> $filterable */
    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly ?string $sort,
        public readonly string $direction,
        public readonly string $search,
        /** @var array<string, string> */
        public readonly array $filters,
        /** @var list<string> */
        public readonly array $includes,
        /** @var list<string> */
        public readonly array $fields,
    ) {
    }

    /**
     * @param list<string> $searchable
     * @param list<string> $sortable
     * @param list<string> $filterable
     * @param list<string> $allowedIncludes
     * @param list<string> $allowedFields
     */
    public static function fromRequest(
        Request $request,
        array $sortable = [],
        array $filterable = [],
        array $allowedIncludes = [],
        array $allowedFields = [],
        int $defaultPerPage = 25,
        int $maxPerPage = 100,
    ): self {
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min($maxPerPage, max(1, (int) $request->query->get('per_page', $defaultPerPage)));
        $sort = (string) $request->query->get('sort', '');
        if ($sort !== '' && $sortable !== [] && !in_array($sort, $sortable, true)) {
            $sort = $sortable[0] ?? '';
        }
        $direction = strtolower((string) $request->query->get('direction', 'asc')) === 'desc' ? 'desc' : 'asc';
        $search = trim((string) $request->query->get('search', $request->query->get('q', '')));

        $rawFilters = $request->query->all('filter');
        $filters = [];
        if (is_array($rawFilters)) {
            foreach ($rawFilters as $key => $value) {
                if (!is_string($key) || ($filterable !== [] && !in_array($key, $filterable, true))) {
                    continue;
                }
                if (is_scalar($value)) {
                    $filters[$key] = (string) $value;
                }
            }
        }

        $includes = self::csvList($request->query->get('include'), $allowedIncludes);
        $fields = self::csvList($request->query->get('fields'), $allowedFields);

        return new self($page, $perPage, $sort !== '' ? $sort : null, $direction, $search, $filters, $includes, $fields);
    }

    /** @return array{page: int, per_page: int, total: int, pages: int} */
    public function meta(int $total): array
    {
        return [
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $total,
            'pages' => (int) ceil(max(1, $total) / $this->perPage),
        ];
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * @param list<string> $allowed
     * @return list<string>
     */
    private static function csvList(mixed $raw, array $allowed): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if ($allowed === []) {
            return $parts;
        }

        return array_values(array_filter($parts, static fn (string $p): bool => in_array($p, $allowed, true)));
    }
}
