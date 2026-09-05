<?php

declare(strict_types=1);

namespace Gereq\Search;

/**
 * Normalize search text: lowercase, strip diacritics, collapse whitespace.
 */
final class TextNormalizer
{
    /** @var array<string, string> */
    private const FOLD = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Å' => 'a',
        'é' => 'e', 'è' => 'e', 'ě' => 'e', 'ë' => 'e', 'ê' => 'e',
        'É' => 'e', 'È' => 'e', 'Ě' => 'e', 'Ë' => 'e', 'Ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'Í' => 'i', 'Ì' => 'i', 'Ï' => 'i', 'Î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'Ó' => 'o', 'Ò' => 'o', 'Ö' => 'o', 'Ô' => 'o', 'Õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ů' => 'u', 'ü' => 'u', 'û' => 'u',
        'Ú' => 'u', 'Ù' => 'u', 'Ů' => 'u', 'Ü' => 'u', 'Û' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'Ý' => 'y',
        'č' => 'c', 'ć' => 'c', 'Ç' => 'c', 'Č' => 'c', 'Ć' => 'c',
        'ď' => 'd', 'Ď' => 'd',
        'ň' => 'n', 'ñ' => 'n', 'Ň' => 'n', 'Ñ' => 'n',
        'ř' => 'r', 'Ř' => 'r',
        'š' => 's', 'ś' => 's', 'Š' => 's', 'Ś' => 's',
        'ť' => 't', 'Ť' => 't',
        'ž' => 'z', 'ź' => 'z', 'ż' => 'z', 'Ž' => 'z', 'Ź' => 'z', 'Ż' => 'z',
        'ł' => 'l', 'Ł' => 'l',
        'ß' => 'ss',
    ];

    public function fold(string $value): string
    {
        $value = strtr($value, self::FOLD);
        if (function_exists('mb_strtolower')) {
            $value = mb_strtolower($value, 'UTF-8');
        } else {
            $value = strtolower($value);
        }
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /** @return list<string> */
    public function tokens(string $value): array
    {
        $folded = $this->fold($value);
        if ($folded === '') {
            return [];
        }
        $parts = preg_split('/[\s,.;:\/\\\\|_+-]+/u', $folded, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : array_values($parts);
    }

    /**
     * Nested SQL LOWER(REPLACE(...)) for Doctrine DQL / SQLite / MySQL.
     * Safe for field refs like "l.title" (no user input).
     */
    public function sqlFoldExpression(string $fieldExpr): string
    {
        $expr = 'LOWER(' . $fieldExpr . ')';
        // Only lowercase map keys needed after LOWER()
        $pairs = [
            'á' => 'a', 'ä' => 'a', 'â' => 'a', 'à' => 'a', 'ã' => 'a',
            'é' => 'e', 'ě' => 'e', 'ë' => 'e', 'ê' => 'e', 'è' => 'e',
            'í' => 'i', 'ï' => 'i', 'î' => 'i', 'ì' => 'i',
            'ó' => 'o', 'ö' => 'o', 'ô' => 'o', 'ò' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'û' => 'u', 'ù' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'č' => 'c', 'ć' => 'c', 'ç' => 'c',
            'ď' => 'd',
            'ň' => 'n', 'ñ' => 'n',
            'ř' => 'r',
            'š' => 's', 'ś' => 's',
            'ť' => 't',
            'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
            'ł' => 'l',
        ];
        foreach ($pairs as $from => $to) {
            $expr = 'REPLACE(' . $expr . ', ' . $this->sqlString($from) . ', ' . $this->sqlString($to) . ')';
        }

        return $expr;
    }

    private function sqlString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
