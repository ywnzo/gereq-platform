<?php

declare(strict_types=1);

namespace Gereq\Admin\Search;

use Gereq\User\Entity\User;

/**
 * Registry živých search zdrojů pro Ctrl+K.
 *
 * Použití v modulu:
 * ```php
 * $context->adminSearch()->add(
 *     'fleet.vehicles',
 *     ['plate', 'vin', 'brand', 'model'],
 *     fn (string $q, int $limit): array => [...],
 *     permission: 'fleet.view',
 *     group: 'fleet.title',
 * );
 * ```
 */
final class AdminSearchRegistry
{
    /** @var list<AdminSearchSource> */
    private array $sources = [];

    /**
     * @param list<string>|string $fields pole nebo CSV / "entity.field, entity.field"
     * @param callable(string, int): list<AdminSearchHit> $search
     */
    public function add(
        string $id,
        array|string $fields,
        callable $search,
        ?string $permission = null,
        string $group = 'Data',
        ?string $icon = null,
        int $limit = 8,
    ): self {
        $this->sources[] = new AdminSearchSource(
            id: $id,
            fields: $this->normalizeFields($fields),
            search: $search,
            permission: $permission,
            group: $group,
            icon: $icon,
            limit: max(1, min(20, $limit)),
        );

        return $this;
    }

    public function source(AdminSearchSource $source): self
    {
        $this->sources[] = $source;

        return $this;
    }

    /**
     * @return list<AdminSearchSource>
     */
    public function sources(?User $user = null): array
    {
        if ($user === null) {
            return $this->sources;
        }

        return array_values(array_filter(
            $this->sources,
            static fn (AdminSearchSource $s): bool => $s->permission === null || $user->can($s->permission),
        ));
    }

    /**
     * @return list<AdminSearchHit>
     */
    public function search(string $query, ?User $user = null, int $maxTotal = 24): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $hits = [];
        foreach ($this->sources($user) as $source) {
            foreach ($source->search($query) as $hit) {
                $hits[] = $hit;
                if (count($hits) >= $maxTotal) {
                    return $hits;
                }
            }
        }

        return $hits;
    }

    /**
     * @param list<string>|string $fields
     * @return list<string>
     */
    private function normalizeFields(array|string $fields): array
    {
        if (is_array($fields)) {
            return array_values(array_filter(array_map(
                static fn ($f): string => self::fieldName((string) $f),
                $fields,
            )));
        }

        $parts = preg_split('/\s*,\s*/', trim($fields)) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $f): string => self::fieldName($f),
            $parts,
        )));
    }

    /** "user.name" → "name", "plate" → "plate" */
    private static function fieldName(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (str_contains($raw, '.')) {
            $parts = explode('.', $raw);

            return (string) end($parts);
        }

        return $raw;
    }
}
