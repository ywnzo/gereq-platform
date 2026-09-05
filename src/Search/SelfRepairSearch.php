<?php

declare(strict_types=1);

namespace Gereq\Search;

use Doctrine\ORM\QueryBuilder;

/**
 * Self-repairing list search: diacritics-insensitive + typo variants via LIKE.
 * Default driver needs no Elasticsearch (optional drivers can wrap this later).
 */
final class SelfRepairSearch
{
    public function __construct(
        private readonly TextNormalizer $normalizer = new TextNormalizer(),
        private readonly TypoExpander $expander = new TypoExpander(),
    ) {
    }

    /**
     * @param list<string> $fields Entity field names (without alias), e.g. ['title','city']
     */
    public function apply(QueryBuilder $qb, string $alias, array $fields, string $query): void
    {
        $query = trim($query);
        if ($query === '' || $fields === []) {
            return;
        }

        $fields = array_values(array_filter(
            array_map(static fn (string $f): string => trim($f), $fields),
            static fn (string $f): bool => $f !== '' && !str_contains($f, ' '),
        ));
        if ($fields === []) {
            return;
        }

        $tokens = $this->normalizer->tokens($query);
        if ($tokens === []) {
            return;
        }

        // Multi-word: each token must match (AND), with per-token typo repair
        if (count($tokens) > 1) {
            foreach ($tokens as $i => $token) {
                $this->applyPatterns($qb, $alias, $fields, $this->expander->expand($token), 'srt' . $i . '_');
            }

            return;
        }

        $this->applyPatterns($qb, $alias, $fields, $this->expander->expand($tokens[0]), 'sr_');
    }

    /**
     * @param list<string> $fields
     * @param list<string> $patterns
     */
    private function applyPatterns(
        QueryBuilder $qb,
        string $alias,
        array $fields,
        array $patterns,
        string $paramPrefix,
    ): void {
        if ($patterns === []) {
            return;
        }

        $ors = [];
        $pi = 0;
        foreach ($fields as $field) {
            $col = $alias . '.' . $field;
            $foldedCol = $this->normalizer->sqlFoldExpression($col);
            foreach ($patterns as $pattern) {
                $param = $paramPrefix . $pi++;
                $ors[] = $foldedCol . ' LIKE :' . $param;
                $qb->setParameter($param, '%' . $pattern . '%');
            }
        }

        if ($ors !== []) {
            $qb->andWhere(implode(' OR ', $ors));
        }
    }
}
