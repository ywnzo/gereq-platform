<?php

declare(strict_types=1);

namespace Gereq\Search;

/**
 * Expand a query into typo-tolerant variants (edit distance ~1 + keyboard neighbors).
 */
final class TypoExpander
{
    /** QWERTZ / common CZ-adjacent keys */
    private const NEIGHBORS = [
        'a' => 'sqwyz',
        'b' => 'vghn',
        'c' => 'xdfv',
        'd' => 'erfcxs',
        'e' => 'rdsw34',
        'f' => 'rtgvcd',
        'g' => 'tyhbvf',
        'h' => 'yujnbg',
        'i' => 'uojk89',
        'j' => 'uikmnh',
        'k' => 'iolmj',
        'l' => 'opk',
        'm' => 'njk',
        'n' => 'bhjm',
        'o' => 'iplk90',
        'p' => 'ol0',
        'q' => 'waa',
        'r' => 'tfde45',
        's' => 'awedx',
        't' => 'ygfr56',
        'u' => 'ijhy78',
        'v' => 'cfgb',
        'w' => 'qeas',
        'x' => 'zsdc',
        'y' => 'tghu',
        'z' => 'asx',
    ];

    public function __construct(
        private readonly TextNormalizer $normalizer = new TextNormalizer(),
        private readonly int $maxVariants = 14,
        private readonly int $minTokenLength = 3,
    ) {
    }

    /**
     * @return list<string> unique folded patterns (without % wildcards)
     */
    public function expand(string $query): array
    {
        $folded = $this->normalizer->fold($query);
        if ($folded === '') {
            return [];
        }

        $out = [$folded];
        $tokens = $this->normalizer->tokens($query);

        foreach ($tokens as $token) {
            if (mb_strlen($token, 'UTF-8') < $this->minTokenLength) {
                continue;
            }
            foreach ($this->edits($token) as $edit) {
                if ($edit === '' || $edit === $token) {
                    continue;
                }
                // Single-token query → just the edit; multi-token → replace that token in phrase
                if (count($tokens) === 1) {
                    $out[] = $edit;
                } else {
                    $out[] = trim(str_replace($token, $edit, $folded));
                }
                if (count($out) >= $this->maxVariants) {
                    break 2;
                }
            }
        }

        $unique = [];
        foreach ($out as $item) {
            $item = trim($item);
            if ($item !== '') {
                $unique[$item] = $item;
            }
        }

        return array_values(array_slice($unique, 0, $this->maxVariants));
    }

    /** @return list<string> */
    private function edits(string $token): array
    {
        $chars = $this->chars($token);
        $len = count($chars);
        $edits = [];

        // deletions
        for ($i = 0; $i < $len; $i++) {
            $edits[] = implode('', array_merge(array_slice($chars, 0, $i), array_slice($chars, $i + 1)));
        }

        // adjacent transpositions
        for ($i = 0; $i < $len - 1; $i++) {
            $copy = $chars;
            [$copy[$i], $copy[$i + 1]] = [$copy[$i + 1], $copy[$i]];
            $edits[] = implode('', $copy);
        }

        // keyboard substitutions
        for ($i = 0; $i < $len; $i++) {
            $ch = $chars[$i];
            $neighbors = self::NEIGHBORS[$ch] ?? '';
            $nlen = mb_strlen($neighbors, 'UTF-8');
            for ($j = 0; $j < $nlen; $j++) {
                $rep = mb_substr($neighbors, $j, 1, 'UTF-8');
                $copy = $chars;
                $copy[$i] = $rep;
                $edits[] = implode('', $copy);
            }
        }

        return $edits;
    }

    /** @return list<string> */
    private function chars(string $value): array
    {
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        return $chars === false ? [] : $chars;
    }
}
