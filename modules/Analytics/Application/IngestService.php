<?php

declare(strict_types=1);

namespace Modules\Analytics\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Validation\ValidationException;
use Gereq\Validation\ValidationRegistry;
use Modules\Analytics\Application\Repositories\SiteRepository;
use Modules\Analytics\Application\Repositories\TrafficRepository;
use Modules\Analytics\Domain\TrafficSource;

/**
 * Single orchestration entry point for one beacon.
 *
 * Order is frozen by `modules/Analytics/README.md`:
 * validate → consent gate → bot/internal classification → resolve site → pseudonymise visitor →
 * resolve/create the open session → persist pageview + events → update counters.
 *
 * A beacon only creates a pageview row when it carries a `pageview` event; the tracker flushes
 * engagement / click batches on their own, and those are attached to the running session's newest
 * pageview instead. Every suppression path is a silent no-op: the storefront must never receive an
 * error, a stack trace or a hint about why a hit was dropped (debug log only).
 *
 * A site resolved by its public tracker key is additionally bound to the request host(s), see
 * {@see self::resolveSite()}.
 */
final class IngestService
{
    public const REASON_OK = 'ok';

    public const REASON_INVALID = 'invalid';

    public const REASON_CONSENT = 'consent';

    public const REASON_BOT = 'bot';

    public const REASON_INTERNAL = 'internal';

    public const REASON_UNKNOWN_SITE = 'unknown_site';

    /**
     * Internal suppression reason for the debug log only: a key-resolved site whose configured
     * domains do not cover the request host(s). Callers still see {@see self::REASON_UNKNOWN_SITE},
     * so a foreign host stays indistinguishable from an unknown key.
     */
    public const REASON_FOREIGN_HOST = 'foreign_host';

    public const REASON_NO_VISITOR = 'no_visitor';

    public const REASON_NO_SESSION = 'no_session';

    public const REASON_ERROR = 'error';

    /** Longest stored path; longer URLs are truncated. */
    private const MAX_PATH_LENGTH = 255;

    /** A client timestamp further away than this is ignored in favour of server time. */
    private const MAX_CLOCK_SKEW_SECONDS = 86400;

    /** Length of `analytics_sessions.source`. */
    private const MAX_SOURCE_LENGTH = 20;

    /** @var list<string> Hosts that count as organic search sources. */
    private const SEARCH_HOSTS = [
        'google.', 'bing.', 'seznam.', 'duckduckgo.', 'yandex.', 'baidu.', 'ecosia.',
        'brave.', 'qwant.', 'startpage.', 'centrum.', 'search.',
    ];

    /** @var list<string> Hosts that count as social sources. */
    private const SOCIAL_HOSTS = [
        'facebook.', 'instagram.', 'tiktok.', 'twitter.', 'x.com', 'linkedin.',
        'pinterest.', 'youtube.', 'reddit.', 'threads.', 'snapchat.', 'whatsapp.',
    ];

    /** @var list<string> UTM parameters kept (as a bounded map) on the session. */
    private const UTM_KEYS = ['source', 'medium', 'campaign', 'term', 'content', 'id'];

    /** Engagement event name emitted by the tracker. */
    private const EVENT_PAGEVIEW = 'pageview';

    private const EVENT_ENGAGEMENT = 'engagement';

    public function __construct(
        private readonly ValidationRegistry $validation,
        private readonly AnalyticsConfig $config,
        private readonly ConsentGate $consent,
        private readonly BotClassifier $bots,
        private readonly TrafficClassifier $classifier,
        private readonly VisitorPseudonymizer $pseudonymizer,
        private readonly Sessionizer $sessionizer,
        private readonly UserAgent $agents,
        private readonly SiteRepository $sites,
        private readonly TrafficRepository $traffic,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, mixed> $payload Parsed beacon body (JSON or form encoded)
     * @param string|null $originHost Host of the page that sent the beacon (`Origin`, or `Referer`
     *   as the fallback), when the caller could read one. Used to bind a key-resolved site to its
     *   configured domains.
     * @return array{ok: bool, sid: string|null, suppressed: bool, reason: string}
     */
    public function ingest(
        array $payload,
        ?string $ip = null,
        ?string $userAgent = null,
        ?bool $webdriver = null,
        ?string $consentCookie = null,
        ?string $noTrackCookie = null,
        ?string $host = null,
        ?string $countryCode = null,
        ?string $originHost = null,
    ): array {
        try {
            return $this->handle(
                $payload,
                $ip,
                $userAgent,
                $webdriver,
                $consentCookie,
                $noTrackCookie,
                $host,
                $countryCode,
                $originHost,
            );
        } catch (\Throwable $exception) {
            $this->rollback();
            // Debug level only: a lost beacon is not an error the visitor can act on. Class name
            // only — DBAL messages can carry SQL fragments.
            error_log('[analytics.ingest] ' . $exception::class);

            return self::suppressed(self::REASON_ERROR);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, sid: string|null, suppressed: bool, reason: string}
     */
    private function handle(
        array $payload,
        ?string $ip,
        ?string $userAgent,
        ?bool $webdriver,
        ?string $consentCookie,
        ?string $noTrackCookie,
        ?string $host,
        ?string $countryCode,
        ?string $originHost,
    ): array {
        // 1. validate
        try {
            $this->validation->validate('analytics.collect', $payload);
        } catch (ValidationException) {
            return self::suppressed(self::REASON_INVALID);
        }

        // 2. consent gate — payload assertion AND the first-party cookie must agree
        if (!$this->consent->granted($consentCookie, $payload['consent'] ?? null, $this->config->consentCategory())) {
            return self::suppressed(self::REASON_CONSENT);
        }

        // 3. resolve the site (per-site exclusions and keep_bot_hits need it), then classify
        //    bot / internal traffic. A key-resolved site is bound to the request host(s).
        $site = $this->resolveSite($payload, $host, $originHost);
        if ($site === null) {
            return self::suppressed(self::REASON_UNKNOWN_SITE);
        }
        $siteId = (string) $site['id'];

        $rawPath = $this->rawPath($payload);
        $isBot = $this->bots->isBot($userAgent, $webdriver, BotClassifier::honeypotFrom($payload));
        $isInternal = $this->classifier->isInternal(
            $ip,
            $rawPath,
            $this->config->internalCidrs(),
            $this->config->excludedIps($site),
            $this->config->excludedPaths($site),
            $noTrackCookie,
        );
        if (($isBot || $isInternal) && !$this->config->keepBotHits($site)) {
            $reason = $isBot ? self::REASON_BOT : self::REASON_INTERNAL;
            // Log the normalised path only: the raw `page.url` (query string, fragment) is
            // attacker controlled and must not reach the log.
            $this->debug($reason, $siteId, $this->normalizePath($rawPath));

            return self::suppressed($reason);
        }

        // 4. pseudonymise the client-owned visitor id
        $rawVisitorId = $this->pseudonymizer->rawId($payload['visitor'] ?? null);
        if ($rawVisitorId === null) {
            return self::suppressed(self::REASON_NO_VISITOR);
        }
        $visitorHash = $this->pseudonymizer->pseudonymize($rawVisitorId, (string) $site['secret']);

        $now = new \DateTimeImmutable();
        $parsed = $this->parseBeacon($payload, $now);
        $cookieAssertion = $this->consent->assertion($consentCookie);
        $payloadAssertion = $this->consent->normalizeAssertion($payload['consent'] ?? null);
        $replayGranted = ($cookieAssertion['replay'] ?? false) === true && ($payloadAssertion['replay'] ?? false) === true;
        if (!$replayGranted) {
            $parsed['events'] = array_values(array_filter($parsed['events'], static fn (array $event): bool => $event['name'] !== 'session_replay'));
        }
        $path = $parsed['path'];

        // 5. resolve / create the open session (server-side sessionization)
        $visitor = $this->traffic->findVisitor($siteId, $visitorHash);
        $open = $visitor !== null ? $this->traffic->findOpenSession($siteId, (string) $visitor['id']) : null;
        $startsNewSession = $this->sessionizer->shouldStartNewSession(
            $open['last_activity_at'] ?? null,
            $this->config->sessionIdleMinutes(),
            $now,
        );

        // Engagement / click batches without a pageview can only extend an existing session.
        if (!$parsed['has_pageview'] && $open === null) {
            return self::suppressed(self::REASON_NO_SESSION);
        }

        $agent = $this->agents->describe($userAgent);
        $excludedDevice = $this->classifier->excludedDevice($isBot, $isInternal);
        $deviceType = $excludedDevice !== '' ? $excludedDevice : $agent['device_type'];
        $referrerHost = $this->referrerHost($parsed['referrer'], $host);
        // A non-empty referrer that resolves to no external host is same-site navigation.
        $selfReferrer = $referrerHost === null && trim((string) $parsed['referrer']) !== '';
        $source = $this->sourceFor($referrerHost, $parsed['utm'], $selfReferrer);

        // 6. + 7. persist the pageview + events and update the counters in one transaction
        //         (the ingest equivalent of a single flush() per request).
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            if ($visitor === null) {
                $visitorId = $this->traffic->insertVisitor([
                    'site_id' => $siteId,
                    'visitor_hash' => $visitorHash,
                    'hash_version' => $this->pseudonymizer->hashVersion(),
                    'first_seen_at' => $parsed['occurred_at'],
                    'last_seen_at' => $now,
                    'first_referrer_host' => $referrerHost,
                    'landing_path' => $path,
                    'last_path' => $path,
                    'device_type' => $deviceType,
                    'browser' => $agent['browser'],
                    'os' => $agent['os'],
                    'language' => $parsed['language'],
                    'country_code' => self::normalizeCountry($countryCode),
                ]);
                $startsNewSession = true;
            } else {
                $visitorId = (string) $visitor['id'];
                if ($startsNewSession && $open !== null) {
                    $this->traffic->closeOpenSessions($siteId, $visitorId, $parsed['occurred_at']);
                }
            }

            if ($startsNewSession || $open === null) {
                $sessionId = $this->traffic->insertSession([
                    'site_id' => $siteId,
                    'visitor_id' => $visitorId,
                    'started_at' => $parsed['occurred_at'],
                    'last_activity_at' => $parsed['occurred_at'],
                    'ended_at' => null,
                    'pageview_count' => 0,
                    'event_count' => 0,
                    'duration_seconds' => 0,
                    'is_bounce' => true,
                    'entry_path' => $path,
                    'exit_path' => $path,
                    'entry_referrer_host' => $referrerHost,
                    'source' => $source,
                    'utm' => $parsed['utm'],
                    'device_type' => $deviceType,
                    'browser' => $agent['browser'],
                    'os' => $agent['os'],
                ]);
                $sessionPageviews = 0;
                $sessionEvents = 0;
                $sessionStartedAt = $parsed['occurred_at'];
            } else {
                $sessionId = (string) $open['id'];
                $sessionPageviews = (int) $open['pageview_count'];
                $sessionEvents = (int) $open['event_count'];
                $sessionStartedAt = $open['started_at'] ?? $parsed['occurred_at'];
            }

            $markers = $this->classifier->propsMarkers($isBot, $isInternal);
            $pageviewDelta = 0;

            if ($parsed['has_pageview']) {
                if ($sessionPageviews > 0) {
                    // A newer pageview means the previous one is no longer the exit page.
                    $this->traffic->clearSessionExits($siteId, $sessionId);
                }
                $pageviewId = $this->traffic->insertPageview([
                    'site_id' => $siteId,
                    'session_id' => $sessionId,
                    'visitor_id' => $visitorId,
                    'occurred_at' => $parsed['occurred_at'],
                    'path' => $path,
                    'title' => $parsed['title'],
                    'referrer_host' => $referrerHost,
                    'is_entry' => $sessionPageviews === 0,
                    'is_exit' => true,
                    'engaged_seconds' => (int) round($parsed['engaged_ms'] / 1000),
                    'viewport_width' => $parsed['viewport_width'],
                    'viewport_height' => $parsed['viewport_height'],
                    'language' => $parsed['language'],
                    'props' => array_merge($parsed['pageview_props'], $markers),
                ]);
                $pageviewDelta = 1;
            } else {
                // Attach the batch to the newest pageview of the running session.
                $pageviewId = $this->traffic->findLatestPageviewId($siteId, $sessionId);
                if ($pageviewId !== null && $parsed['engaged_ms'] > 0) {
                    $this->traffic->addPageviewEngagement($pageviewId, (int) round($parsed['engaged_ms'] / 1000));
                }
            }

            $eventCount = 0;
            $eventRows = [];
            foreach ($parsed['events'] as $event) {
                $eventRows[] = [
                    'site_id' => $siteId,
                    'session_id' => $sessionId,
                    'visitor_id' => $visitorId,
                    'pageview_id' => $pageviewId,
                    'name' => $event['name'],
                    'occurred_at' => $event['occurred_at'],
                    'value' => $event['value'],
                    'props' => array_merge($event['props'], $markers),
                ];
                if ($event['name'] !== 'session_replay') {
                    $eventCount++;
                }
            }
            $this->traffic->insertEvents($eventRows);

            $pageviewTotal = $sessionPageviews + $pageviewDelta;
            $eventTotal = $sessionEvents + $eventCount;

            $this->traffic->touchSession(
                $sessionId,
                $now,
                $path,
                $pageviewDelta,
                $eventCount,
                $this->sessionizer->durationSeconds($sessionStartedAt, $now),
                $this->sessionizer->isBounce($pageviewTotal, $eventTotal),
            );
            $this->traffic->touchVisitor(
                $visitorId,
                $now,
                $path,
                $startsNewSession ? 1 : 0,
                $pageviewDelta,
                $eventCount,
            );

            $connection->commit();
        } catch (\Throwable $exception) {
            $this->rollback();
            throw $exception;
        }

        return [
            'ok' => true,
            'sid' => $sessionId,
            'suppressed' => false,
            'reason' => self::REASON_OK,
        ];
    }

    /** @return array{ok: false, sid: null, suppressed: true, reason: string} */
    private static function suppressed(string $reason): array
    {
        return ['ok' => false, 'sid' => null, 'suppressed' => true, 'reason' => $reason];
    }

    /**
     * Resolve the target site, binding a key-resolved site to the request host(s).
     *
     * The tracker key is public, so identifying the site by key alone must not allow writing into
     * another site's data: the request `Host` and the page `Origin`/`Referer` host (when the caller
     * supplies one) both have to belong to the site's configured domains
     * ({@see SiteRepository::hostsMatchSite()}). An empty `domains` list means "no domain
     * restriction configured" and accepts the key on its own. A foreign host is suppressed exactly
     * like an unknown site — no reason ever reaches the storefront.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function resolveSite(array $payload, ?string $host, ?string $originHost): ?array
    {
        $key = trim((string) ($payload['site'] ?? ''));
        if ($key === '') {
            return $host !== null ? $this->sites->findEnabledByDomain($host) : null;
        }

        $site = $this->sites->findEnabledByKey($key);
        if ($site === null) {
            return null;
        }

        if (!SiteRepository::hostsMatchSite($site, [$host, $originHost])) {
            $this->debug(
                self::REASON_FOREIGN_HOST,
                (string) $site['id'],
                $this->normalizePath($this->rawPath($payload)),
            );

            return null;
        }

        return $site;
    }

    /** @param array<string, mixed> $payload */
    private function rawPath(array $payload): string
    {
        $page = is_array($payload['page'] ?? null) ? $payload['page'] : [];

        return (string) ($page['url'] ?? '/');
    }

    /**
     * Normalise the beacon into the persisted pageview / event fields.
     *
     * @param array<string, mixed> $payload
     * @return array{
     *   occurred_at: \DateTimeImmutable,
     *   has_pageview: bool,
     *   path: string,
     *   pageview_props: array<string, mixed>,
     *   engaged_ms: int,
     *   events: list<array{name: string, occurred_at: \DateTimeImmutable, props: array<string, mixed>, value: float|null}>,
     *   referrer: string|null,
     *   title: string|null,
     *   language: string|null,
     *   viewport_width: int|null,
     *   viewport_height: int|null,
     *   utm: array<string, string>
     * }
     */
    private function parseBeacon(array $payload, \DateTimeImmutable $now): array
    {
        $page = is_array($payload['page'] ?? null) ? $payload['page'] : [];
        $viewport = is_array($payload['viewport'] ?? null) ? $payload['viewport'] : [];
        $rawUrl = (string) ($page['url'] ?? '/');

        $occurredAt = $now;
        $hasPageview = false;
        $pageviewProps = [];
        $engagedMs = 0;
        $events = [];
        $rawEvents = is_array($payload['events'] ?? null) ? array_values($payload['events']) : [];
        $limit = $this->config->beaconMaxEvents();

        foreach (array_slice($rawEvents, 0, $limit) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $type = mb_strtolower(trim((string) ($raw['t'] ?? '')));
            $props = is_array($raw['props'] ?? null) ? $raw['props'] : [];
            $timestamp = self::timestamp($raw['ts'] ?? null, $now);

            if ($type === self::EVENT_PAGEVIEW) {
                $hasPageview = true;
                $occurredAt = $timestamp;
                $pageviewProps = $props;
                continue;
            }

            if ($type === self::EVENT_ENGAGEMENT) {
                $engagedMs += self::intProp($props, 'engaged_ms');
            }

            $events[] = [
                'name' => self::eventName($type, $props),
                'occurred_at' => $timestamp,
                'props' => $props,
                'value' => self::eventValue($props),
            ];
        }

        $referrer = isset($page['referrer']) ? (string) $page['referrer'] : null;

        return [
            'occurred_at' => $occurredAt,
            'has_pageview' => $hasPageview,
            'path' => $this->normalizePath($rawUrl),
            'pageview_props' => $pageviewProps,
            'engaged_ms' => $engagedMs,
            'events' => $events,
            'referrer' => $referrer,
            'title' => self::nullableString($page['title'] ?? null, 255),
            'language' => self::nullableString($page['lang'] ?? null, 12),
            'viewport_width' => self::nullableInt($viewport['w'] ?? null),
            'viewport_height' => self::nullableInt($viewport['h'] ?? null),
            'utm' => self::utmFrom($rawUrl),
        ];
    }

    /** Strip scheme/host, query and fragment; reports group by path only. */
    private function normalizePath(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '/';
        }
        if (str_contains($url, '://')) {
            $parsed = parse_url($url);
            $url = is_array($parsed) ? (string) ($parsed['path'] ?? '/') : '/';
        }
        if (str_contains($url, '#')) {
            $url = (string) strstr($url, '#', true);
        }
        if (str_contains($url, '?')) {
            $url = (string) strstr($url, '?', true);
        }
        if ($url === '' || $url[0] !== '/') {
            $url = '/' . $url;
        }
        $url = mb_substr($url, 0, self::MAX_PATH_LENGTH);
        if ($url !== '/' && str_ends_with($url, '/')) {
            $url = rtrim($url, '/');
        }

        return $url === '' ? '/' : $url;
    }

    private function referrerHost(?string $referrer, ?string $siteHost): ?string
    {
        $referrer = trim((string) $referrer);
        if ($referrer === '') {
            return null;
        }
        $host = str_contains($referrer, '://')
            ? (string) (parse_url($referrer, PHP_URL_HOST) ?? '')
            : (string) (parse_url('https://' . $referrer, PHP_URL_HOST) ?? $referrer);
        $host = SiteRepository::normalizeHost($host);
        if ($host === '') {
            return null;
        }
        // Internal navigation (own host) is not an external source.
        if ($siteHost !== null && $host === SiteRepository::normalizeHost($siteHost)) {
            return null;
        }

        return mb_substr($host, 0, 190);
    }

    /**
     * Traffic source class, see {@see TrafficSource} — the column is a free 20 char string.
     *
     * @param array<string, string> $utm
     */
    private function sourceFor(?string $referrerHost, array $utm, bool $selfReferrer): string
    {
        $medium = mb_strtolower($utm['medium'] ?? '');
        if ($medium !== '' && preg_match('/\b(cpc|ppc|paid|ads?|display|retargeting)\b/', $medium) === 1) {
            return TrafficSource::PAID;
        }
        if ($medium !== '' && (str_contains($medium, 'email') || str_contains($medium, 'newsletter'))) {
            return TrafficSource::EMAIL;
        }
        if ($selfReferrer) {
            return TrafficSource::INTERNAL;
        }
        $campaign = trim($utm['source'] ?? '');
        if ($campaign !== '') {
            return mb_substr($campaign, 0, self::MAX_SOURCE_LENGTH);
        }
        if ($referrerHost === null) {
            return TrafficSource::DIRECT;
        }
        foreach (self::SEARCH_HOSTS as $needle) {
            if (str_contains($referrerHost, $needle)) {
                return TrafficSource::SEARCH;
            }
        }
        foreach (self::SOCIAL_HOSTS as $needle) {
            if (str_contains($referrerHost, $needle)) {
                return TrafficSource::SOCIAL;
            }
        }

        return TrafficSource::REFERRAL;
    }

    /** @return array<string, string> */
    private static function utmFrom(string $url): array
    {
        if (!str_contains($url, '?')) {
            return [];
        }
        $query = (string) strstr($url, '?');
        parse_str(ltrim($query, '?'), $params);

        $utm = [];
        foreach (self::UTM_KEYS as $key) {
            $value = $params['utm_' . $key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $utm[$key] = mb_substr(trim($value), 0, 190);
            }
        }

        return $utm;
    }

    /**
     * The tracker batches custom events as `{t: 'event', props: {name, value}}`.
     *
     * @param array<string, mixed> $props
     */
    private static function eventName(string $type, array $props): string
    {
        $name = $type === 'event' ? (string) ($props['name'] ?? '') : $type;
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9_.:-]/', '', $name) ?? '';
        $name = mb_substr($name, 0, 64);

        return $name !== '' ? $name : 'event';
    }

    private static function timestamp(mixed $raw, \DateTimeImmutable $now): \DateTimeImmutable
    {
        if (!is_numeric($raw)) {
            return $now;
        }
        $ts = (int) $raw;
        if ($ts <= 0 || abs($now->getTimestamp() - $ts) > self::MAX_CLOCK_SKEW_SECONDS) {
            return $now;
        }

        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /**
     * `analytics_events.value` is a nullable float.
     *
     * @param array<string, mixed> $props
     */
    private static function eventValue(array $props): ?float
    {
        $value = $props['value'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /** @param array<string, mixed> $props */
    private static function intProp(array $props, string $key): int
    {
        $value = $props[$key] ?? null;

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private static function nullableString(mixed $value, int $maxLength): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : mb_substr($string, 0, $maxLength);
    }

    private static function nullableInt(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 && $int <= 20000 ? $int : null;
    }

    /** `country_code` is only ever filled from an inbound header, never resolved. */
    private static function normalizeCountry(?string $countryCode): ?string
    {
        $code = mb_strtoupper(trim((string) $countryCode));

        return preg_match('/\A[A-Z]{2}\z/', $code) === 1 ? $code : null;
    }

    private function rollback(): void
    {
        try {
            $connection = $this->em->getConnection();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        } catch (\Throwable) {
            // Never surface storage problems to the storefront.
        }
    }

    /**
     * Debug channel of the ingest path.
     *
     * Only the reason, the resolved site id and the **normalised** path are logged: the raw
     * `page.url` may carry a query string / fragment and is attacker controlled.
     */
    private function debug(string $reason, string $siteId, string $path): void
    {
        error_log(sprintf('[analytics.ingest] suppressed=%s site=%s path=%s', $reason, $siteId, $path));
    }
}
