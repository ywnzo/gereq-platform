<?php

declare(strict_types=1);

namespace Modules\Emailing\Application;

/**
 * IMAP KEYWORDS (RFC 3501 flag-keyword) — uživatelské štítky mimo systémové flagy.
 */
final class ImapKeyword
{
    public const PINNED = '$Pinned';

    private const SYSTEM = [
        'seen', 'answered', 'flagged', 'deleted', 'draft', 'recent',
    ];

    public static function isSystem(string $flag): bool
    {
        $n = strtolower(ltrim($flag, '\\'));

        return in_array($n, self::SYSTEM, true);
    }

    public static function normalize(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $raw = ltrim($raw, '\\');
        if (self::isSystem($raw)) {
            return null;
        }
        // atom: A–Z a–z 0–9 $ _ - .
        $keyword = preg_replace('/[^A-Za-z0-9$_.-]+/', '_', $raw) ?? '';
        $keyword = trim($keyword, '_');
        if ($keyword === '' || strlen($keyword) > 80) {
            return null;
        }

        return $keyword;
    }

    public static function fromLabelName(string $name): ?string
    {
        return self::normalize($name);
    }

    /**
     * @param iterable<mixed> $flags
     * @return list<string>
     */
    public static function extractFromFlags(iterable $flags): array
    {
        $out = [];
        foreach ($flags as $flag) {
            $n = self::normalize((string) $flag);
            if ($n === null) {
                continue;
            }
            $out[] = $n;
        }

        return array_values(array_unique($out));
    }
}
