<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain;

/**
 * Dimension keys of `analytics_daily_dimensions.dimension` (phase 1 set).
 *
 * A rollup writes `(day, dimension, value)` buckets; the list is closed on purpose so
 * report filters and `RollupService` cannot drift apart.
 */
final class Dimension
{
    /** Page path bucket. */
    public const PATH = 'path';

    /** Referrer host bucket (`direct` when absent). */
    public const REFERRER = 'referrer';

    /** Device type bucket. */
    public const DEVICE = 'device';

    /** Browser bucket. */
    public const BROWSER = 'browser';

    /** Operating system bucket. */
    public const OS = 'os';

    /** Country bucket (ISO 3166-1 alpha-2, `unknown` when the header is missing). */
    public const COUNTRY = 'country';

    /** Tracked event name bucket. */
    public const EVENT = 'event';

    /** Session entry path bucket. */
    public const ENTRY = 'entry';

    /** Session exit path bucket. */
    public const EXIT = 'exit';

    /** Traffic source bucket, see {@see TrafficSource}. */
    public const SOURCE = 'source';

    /** @var list<string> */
    public const ALL = [
        self::PATH,
        self::REFERRER,
        self::DEVICE,
        self::BROWSER,
        self::OS,
        self::COUNTRY,
        self::EVENT,
        self::ENTRY,
        self::EXIT,
        self::SOURCE,
    ];

    public static function isKnown(string $dimension): bool
    {
        return in_array($dimension, self::ALL, true);
    }

    private function __construct()
    {
    }
}
