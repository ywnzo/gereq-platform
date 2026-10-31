<?php

declare(strict_types=1);

namespace Modules\Analytics\Application;

use Modules\Analytics\Domain\ConsentDecision;

/**
 * Consent gate (README "Consent contract", layer 2).
 *
 * Collection requires **both** the first-party `grq_consent` cookie and the matching consent
 * assertion inside the beacon payload. Missing or mismatched consent is a silent no-op at the
 * call site — nothing is written and no error is leaked to the storefront.
 */
final class ConsentGate
{
    public const COOKIE = 'grq_consent';

    public const DECISION_GRANTED = ConsentDecision::GRANTED;

    public const DECISION_DENIED = ConsentDecision::DENIED;

    public const DECISION_REVOKED = ConsentDecision::REVOKED;

    /** Supported decision values for `POST /api/public/analytics/consent`. */
    public const DECISIONS = ConsentDecision::ALL;

    /**
     * Parse the consent assertion out of the cookie / payload value.
     *
     * @return array<string, mixed>|null
     */
    public function assertion(?string $raw): ?array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Same as {@see assertion()} for a value that may already be a decoded array.
     *
     * @return array<string, mixed>|null
     */
    public function normalizeAssertion(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            return $this->assertion($value);
        }

        return null;
    }

    /** @param array<string, mixed> $assertion */
    public function categoryGranted(array $assertion, string $category): bool
    {
        $value = $assertion[$category] ?? null;

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * Cookie assertion AND payload assertion must both grant the configured category.
     */
    public function granted(?string $rawCookie, mixed $payloadAssertion, string $category): bool
    {
        $cookie = $this->assertion($rawCookie);
        $payload = $this->normalizeAssertion($payloadAssertion);
        if ($cookie === null || $payload === null) {
            return false;
        }

        if (!$this->categoryGranted($cookie, $category) || !$this->categoryGranted($payload, $category)) {
            return false;
        }

        return $this->versionsMatch($cookie, $payload);
    }

    /**
     * Map the requested consent decision, falling back to the cookie state.
     */
    public function decision(mixed $raw, string $category, ?string $rawCookie = null): string
    {
        if (is_string($raw)) {
            $normalized = mb_strtolower(trim($raw));
            if (in_array($normalized, self::DECISIONS, true)) {
                return $normalized;
            }
        }

        $cookie = $this->assertion($rawCookie);
        if ($cookie !== null && $this->categoryGranted($cookie, $category)) {
            return self::DECISION_GRANTED;
        }

        return self::DECISION_DENIED;
    }

    /**
     * Consent versions must match when both sides declare one (a v1 cookie cannot assert v2 consent).
     *
     * @param array<string, mixed> $cookie
     * @param array<string, mixed> $payload
     */
    private function versionsMatch(array $cookie, array $payload): bool
    {
        $cookieVersion = $cookie['v'] ?? null;
        $payloadVersion = $payload['v'] ?? null;

        if ($cookieVersion === null || $payloadVersion === null) {
            return true;
        }

        return (string) $cookieVersion === (string) $payloadVersion;
    }
}
