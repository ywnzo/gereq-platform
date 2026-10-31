<?php

declare(strict_types=1);

namespace Modules\Analytics\Application;

use Gereq\Event\EventBus;
use Modules\Analytics\Application\Repositories\SiteRepository;
use Modules\Analytics\Application\Repositories\TrafficRepository;

/**
 * Consent endpoint orchestration (README "Consent contract", layer 3).
 *
 * `decision=revoked` closes the visitor's open session, records an `analytics_consent_events` row
 * and — when `analytics.purge_on_revocation` is on — deletes every row of that visitor hash.
 * An unknown site or a missing visitor id is a silent no-op (204, nothing written).
 *
 * A `granted` / `denied` decision is only recorded when the first-party `grq_consent` assertion
 * cookie backs it up; `revoked` deliberately stays cookie independent (the tracker deletes the
 * cookie before it posts the revocation). The stored category map is bounded, see
 * {@see self::categories()}.
 */
final class ConsentService
{
    /** Max entries stored in `analytics_consent_events.categories`. */
    private const MAX_CATEGORIES = 12;

    /** Max length of a stored category key. */
    private const MAX_CATEGORY_KEY_LENGTH = 32;

    public function __construct(
        private readonly AnalyticsConfig $config,
        private readonly ConsentGate $gate,
        private readonly VisitorPseudonymizer $pseudonymizer,
        private readonly SiteRepository $sites,
        private readonly TrafficRepository $traffic,
        private readonly EventBus $events,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{recorded: bool, decision: string, purged: bool, sessions_closed: int}
     */
    public function record(array $payload, ?string $consentCookie = null, ?string $host = null): array
    {
        try {
            return $this->handle($payload, $consentCookie, $host);
        } catch (\Throwable $exception) {
            // The tracker must never learn why a consent ping failed. Class name only — DBAL
            // messages can carry SQL fragments.
            error_log('[analytics.consent] ' . $exception::class);

            return ['recorded' => false, 'decision' => ConsentGate::DECISION_DENIED, 'purged' => false, 'sessions_closed' => 0];
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{recorded: bool, decision: string, purged: bool, sessions_closed: int}
     */
    private function handle(array $payload, ?string $consentCookie, ?string $host): array
    {
        $category = $this->config->consentCategory();
        $decision = $this->gate->decision($payload['decision'] ?? null, $category, $consentCookie);

        // Integrity gate, before anything is written: the payload is attacker controlled, the
        // cookie is the CMP-owned state. A revocation stays recordable without it.
        if (!$this->assertionBacked($decision, $payload, $consentCookie, $category)) {
            return ['recorded' => false, 'decision' => $decision, 'purged' => false, 'sessions_closed' => 0];
        }

        $site = $this->resolveSite($payload, $host);
        if ($site === null) {
            return ['recorded' => false, 'decision' => $decision, 'purged' => false, 'sessions_closed' => 0];
        }
        $siteId = (string) $site['id'];

        $rawVisitorId = $this->pseudonymizer->rawId($payload['visitor'] ?? null);
        $visitorHash = $rawVisitorId !== null
            ? $this->pseudonymizer->pseudonymize($rawVisitorId, (string) $site['secret'])
            : null;

        $now = new \DateTimeImmutable();
        $sessionsClosed = 0;
        $purged = false;

        if ($visitorHash !== null && $decision === ConsentGate::DECISION_REVOKED) {
            foreach ($this->traffic->visitorIdsByHash($siteId, $visitorHash) as $visitorId) {
                $sessionsClosed += $this->traffic->closeOpenSessions($siteId, $visitorId, $now);
            }
        }

        $this->traffic->insertConsentEvent([
            'site_id' => $siteId,
            'occurred_at' => $now,
            'decision' => $decision,
            'categories' => $this->categories($payload, $consentCookie, $category, $decision),
            'visitor_hash' => $visitorHash,
        ]);

        if ($visitorHash !== null && $decision === ConsentGate::DECISION_REVOKED && $this->config->purgeOnRevocation()) {
            $deleted = $this->traffic->deleteVisitorData($siteId, $visitorHash);
            $purged = array_sum($deleted) > 0;
        }

        if ($decision === ConsentGate::DECISION_REVOKED) {
            $this->events->emit(
                'analytics.consent.revoked',
                ['type' => 'analytics_site', 'id' => $siteId],
                [
                    'site_id' => $siteId,
                    'decision' => $decision,
                    'purged' => $purged,
                    'occurred_at' => $now->format(\DateTimeInterface::ATOM),
                ],
            );
        }

        return ['recorded' => true, 'decision' => $decision, 'purged' => $purged, 'sessions_closed' => $sessionsClosed];
    }

    /**
     * `granted` / `denied` are only recorded when the `grq_consent` assertion cookie backs the
     * decision up: the cookie is the CMP-owned state, the payload is attacker controlled, so the
     * payload alone must not be able to insert consent rows.
     *
     * `revoked` stays cookie independent on purpose — the tracker deletes `grq_consent` before it
     * posts the revocation, so requiring the cookie would make revocation impossible. The resulting
     * row and the optional purge stay scoped to the resolved site and the matching `visitor_hash`.
     *
     * @param array<string, mixed> $payload
     */
    private function assertionBacked(string $decision, array $payload, ?string $consentCookie, string $category): bool
    {
        if ($decision === ConsentGate::DECISION_REVOKED) {
            return true;
        }

        $assertion = $this->gate->assertion($consentCookie);
        if ($assertion === null) {
            return false;
        }

        if ($decision === ConsentGate::DECISION_DENIED) {
            return true;
        }

        // `granted`: the cookie itself must grant the configured category, and a payload assertion
        // the caller sends along must not contradict it.
        $fromPayload = $this->gate->normalizeAssertion($payload['consent'] ?? null);

        return $this->gate->categoryGranted($assertion, $category)
            && ($fromPayload === null || $this->gate->categoryGranted($fromPayload, $category));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function resolveSite(array $payload, ?string $host): ?array
    {
        $key = trim((string) ($payload['site'] ?? ''));
        if ($key !== '') {
            return $this->sites->findEnabledByKey($key);
        }

        return $host !== null ? $this->sites->findEnabledByDomain($host) : null;
    }

    /**
     * Category map stored with the consent event. The tracker deletes `grq_consent` before it
     * posts a revocation, so the payload map wins; the cookie assertion is the fallback and the
     * configured category is the last resort.
     *
     * The map is bounded: at most {@see self::MAX_CATEGORIES} entries, keys of at most
     * {@see self::MAX_CATEGORY_KEY_LENGTH} characters and boolean values only. Anything else is
     * dropped — a malformed category map never rejects the whole ping and never stores oversized
     * data (the beacon body cap is 32 KB).
     *
     * @param array<string, mixed> $payload
     * @return array<string, bool>
     */
    private function categories(array $payload, ?string $consentCookie, string $category, string $decision): array
    {
        $fromPayload = self::booleanMap($payload['categories'] ?? null);
        if ($fromPayload !== []) {
            return $fromPayload;
        }

        $fromCookie = self::booleanMap($this->gate->assertion($consentCookie));
        if ($fromCookie !== []) {
            return $fromCookie;
        }

        return [
            mb_substr($category, 0, self::MAX_CATEGORY_KEY_LENGTH) => $decision === ConsentGate::DECISION_GRANTED,
        ];
    }

    /**
     * Bounded category map: boolean values only, non-empty keys of at most 32 characters, the
     * assertion version marker `v` excluded.
     *
     * @param array<string, mixed>|null $map
     * @return array<string, bool>
     */
    private static function booleanMap(?array $map): array
    {
        $out = [];
        foreach ($map ?? [] as $key => $value) {
            if (count($out) >= self::MAX_CATEGORIES) {
                break;
            }
            if (!is_string($key) || !is_bool($value)) {
                continue;
            }
            $key = trim($key);
            if ($key === '' || $key === 'v' || mb_strlen($key) > self::MAX_CATEGORY_KEY_LENGTH) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
