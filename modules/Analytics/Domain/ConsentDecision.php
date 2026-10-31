<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain;

/**
 * Consent decisions recorded in `analytics_consent_events.decision`.
 *
 * The tracker only ever sends one of these; the column stays a plain string so new
 * decisions do not require a migration.
 */
final class ConsentDecision
{
    /** Stats consent was granted (CMP category accepted). */
    public const GRANTED = 'granted';

    /** Stats consent was refused; nothing is collected for the visit. */
    public const DENIED = 'denied';

    /** A previously granted consent was withdrawn; collection stops immediately. */
    public const REVOKED = 'revoked';

    /** @var list<string> */
    public const ALL = [self::GRANTED, self::DENIED, self::REVOKED];

    public static function isKnown(string $decision): bool
    {
        return in_array($decision, self::ALL, true);
    }

    private function __construct()
    {
    }
}
