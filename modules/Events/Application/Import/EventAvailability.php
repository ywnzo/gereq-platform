<?php

declare(strict_types=1);

namespace Modules\Events\Application\Import;

/**
 * Pulls availability markers (VYPRODÁNO, …) out of titles into capacity,
 * so the catalog can show a badge instead of a “duplicate” title variant.
 */
final class EventAvailability
{
    /** @var list<array{0: string, 1: string}> label => regex (case-insensitive, end-anchored) */
    private const MARKERS = [
        ['Vyprodáno', '/\s*[-–—|:]\s*vyprod[aá]no\.?\s*$/iu'],
        ['Vyprodáno', '/\s*[\(\[\{]\s*vyprod[aá]no\.?\s*[\)\]\}]\s*$/iu'],
        ['Poslední vstupenky', '/\s*[-–—|:]\s*posledn[ií]\s+vstupenky\.?\s*$/iu'],
        ['Zrušeno', '/\s*[-–—|:]\s*zru[sš]eno\.?\s*$/iu'],
        ['Přesunuto', '/\s*[-–—|:]\s*p[rř]esunuto\.?\s*$/iu'],
    ];

    public static function enrich(NormalizedEvent $row): NormalizedEvent
    {
        [$title, $fromTitle] = self::splitTitle($row->title);
        $capacity = $row->capacity;
        if ($fromTitle !== null && $fromTitle !== '') {
            $capacity = $fromTitle;
        }

        if ($title === $row->title && $capacity === $row->capacity) {
            return $row;
        }

        return new NormalizedEvent(
            source: $row->source,
            sourceId: $row->sourceId,
            title: $title,
            region: $row->region,
            place: $row->place,
            startsAt: $row->startsAt,
            endsAt: $row->endsAt,
            category: $row->category,
            description: $row->description,
            longDescription: $row->longDescription,
            price: $row->price,
            organizer: $row->organizer,
            externalUrl: $row->externalUrl,
            imageUrl: $row->imageUrl,
            timeLabel: $row->timeLabel,
            color: $row->color,
            gallery: $row->gallery,
            capacity: $capacity,
        );
    }

    /**
     * @return array{0: string, 1: ?string} clean title, capacity label
     */
    public static function splitTitle(string $title): array
    {
        $clean = trim($title);
        $capacity = null;
        foreach (self::MARKERS as [$label, $pattern]) {
            if (preg_match($pattern, $clean) !== 1) {
                continue;
            }
            $clean = trim((string) preg_replace($pattern, '', $clean));
            $capacity = $label;
            break;
        }
        $clean = self::unwrapQuotes($clean);

        return [$clean !== '' ? $clean : trim($title), $capacity];
    }

    public static function isSoldOut(?string $capacity): bool
    {
        $value = mb_strtolower(trim((string) $capacity));

        return $value !== '' && (str_contains($value, 'vyprod') || str_contains($value, 'sold out'));
    }

    private static function unwrapQuotes(string $title): string
    {
        $title = trim($title);
        if (preg_match('/^[\"„“«](.+)[\"“”»]$/u', $title, $m) === 1) {
            return trim($m[1]);
        }

        return $title;
    }
}
