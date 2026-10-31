<?php

declare(strict_types=1);

namespace Modules\Analytics\Application;

/**
 * Pseudonymises the client-owned visitor id into the stored `visitor_hash`.
 *
 * `visitor_hash = hash_hmac('sha256', $rawVisitorId, $site->secret())` — the raw id only ever
 * lives in the visitor's own browser storage / cookie (README "Pseudonymity rules").
 */
final class VisitorPseudonymizer
{
    public const HASH_VERSION = 1;

    public const VISITOR_COOKIE = 'grq_vid';

    /** Raw ids outside this range are rejected instead of stored as garbage hashes. */
    private const MIN_RAW_LENGTH = 8;

    private const MAX_RAW_LENGTH = 128;

    public function pseudonymize(string $rawVisitorId, string $siteSecret): string
    {
        return hash_hmac('sha256', $rawVisitorId, $siteSecret);
    }

    /**
     * Normalise the client supplied raw visitor id; null when it is missing or implausible.
     */
    public function rawId(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '' || strlen($raw) < self::MIN_RAW_LENGTH || strlen($raw) > self::MAX_RAW_LENGTH) {
            return null;
        }
        if (preg_match('/\A[A-Za-z0-9._~-]+\z/', $raw) !== 1) {
            return null;
        }

        return $raw;
    }

    public function hashVersion(): int
    {
        return self::HASH_VERSION;
    }
}
