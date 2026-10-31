<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain;

/**
 * Traffic source classes recorded in `analytics_sessions.source` and rolled up as the
 * `source` dimension, see {@see Dimension::SOURCE}.
 *
 * The column stays a free string — the classifier may add a class before a migration is
 * written — so the entity never rejects an unknown value.
 */
final class TrafficSource
{
    /** No referrer, no campaign parameters. */
    public const DIRECT = 'direct';

    /** Other site linked in (referrer host present, no recognised search engine). */
    public const REFERRAL = 'referral';

    /** Search engine referrer or `utm_medium=organic`. */
    public const SEARCH = 'search';

    /** Social network referrer. */
    public const SOCIAL = 'social';

    /** `utm_medium=email` / newsletter link. */
    public const EMAIL = 'email';

    /** Paid campaign (`utm_medium` contains `cpc`, `ppc`, `paid`, …). */
    public const PAID = 'paid';

    /** Referrer matched the site's own domains (same-site navigation). */
    public const INTERNAL = 'internal';

    /** @var list<string> */
    public const ALL = [
        self::DIRECT,
        self::REFERRAL,
        self::SEARCH,
        self::SOCIAL,
        self::EMAIL,
        self::PAID,
        self::INTERNAL,
    ];

    public static function isKnown(string $source): bool
    {
        return in_array($source, self::ALL, true);
    }

    private function __construct()
    {
    }
}
