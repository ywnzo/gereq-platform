<?php

declare(strict_types=1);

namespace Modules\Events\Application\Import;

use Modules\Events\Domain\CatalogEvent;

final class CategoryMapper
{
    /**
     * Map free-text / source category labels onto CatalogEvent::CATEGORIES.
     *
     * $labels[0] is treated as the title — kids keywords are only matched there,
     * so ticket boilerplate ("děti a doprovod…") in descriptions cannot force "Pro děti".
     *
     * @param list<string> $labels
     */
    public static function map(array $labels, string $fallback = 'Kultura'): string
    {
        $labels = array_values(array_filter(array_map(
            static fn (mixed $v): string => trim((string) $v),
            $labels,
        )));
        if ($labels === []) {
            return in_array($fallback, CatalogEvent::CATEGORIES, true) ? $fallback : 'Kultura';
        }

        $title = mb_strtolower($labels[0]);
        $hay = mb_strtolower(implode(' ', $labels));

        if (self::hasAny($title, ['pro děti', 'pro deti', 'dětsk', 'detsk', 'pohádk', 'pohadk', 'bublin'])) {
            return 'Pro děti';
        }
        if (self::hasWord($title, ['děti', 'deti', 'rodina', 'rodiny', 'rodinn', 'škola', 'školní', 'skola', 'skolni'])) {
            return 'Pro děti';
        }
        if (self::hasAny($hay, ['sport', 'fotbal', 'hokej', 'turnaj', 'maraton', 'maratón', 'běžeck', 'bezeck'])
            || self::hasWord($hay, ['běh', 'beh'])) {
            return 'Sport';
        }
        if (self::hasAny($hay, ['kino', 'film', 'projekc', 'koncert', 'hudba', 'stand up', 'standup', 'comedy', 'zábav', 'zabav', 'ples', 'party', 'disco', 'rap', 'komedi', 'muzikál', 'muzikal', 'travesti'])) {
            return 'Zábava';
        }
        if (self::hasAny($hay, ['divadl', 'kulturn', 'výstav', 'vystav', 'muze', 'galeri', 'přednáš', 'prednas', 'vernis'])) {
            return 'Kultura';
        }

        return in_array($fallback, CatalogEvent::CATEGORIES, true) ? $fallback : 'Ostatní';
    }

    public static function colorFor(string $category): string
    {
        return match ($category) {
            'Sport' => 'green',
            'Pro děti' => 'orange',
            'Zábava' => 'pink',
            'Kultura' => 'purple',
            default => 'yellow',
        };
    }

    /** @param list<string> $needles */
    private static function hasAny(string $hay, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($hay, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Whole-word / token match — avoids "běh" inside "příběh". */
    private static function hasWord(string $hay, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }
            if (preg_match('/(^|[^\p{L}\p{N}])' . preg_quote($needle, '/') . '($|[^\p{L}\p{N}])/u', $hay) === 1) {
                return true;
            }
        }

        return false;
    }
}
