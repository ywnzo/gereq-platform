<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * Pseudonymous visitor (`analytics_visitors`): one row per `visitor_hash` per site.
 *
 * The raw client id never reaches the platform — it lives only in the visitor's own cookie and
 * is reduced to `hash_hmac('sha256', $rawId, $site->secret())` by the ingest service. There is
 * deliberately no ORM association to {@see Site}: ingest stays a plain insert and purging a site
 * is an explicit delete per table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'analytics_visitors')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_visitors_site_hash', columns: ['site_id', 'visitor_hash'])]
#[ORM\Index(name: 'idx_analytics_visitors_site_last_seen', columns: ['site_id', 'last_seen_at'])]
class Visitor
{
    /** Value written to `hash_version`; bump when the hashing recipe changes. */
    public const HASH_VERSION = 1;

    /** Length of the hexadecimal HMAC-SHA256 `visitor_hash`. */
    public const HASH_LENGTH = 64;

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'site_id', length: 36)]
    private string $siteId;

    #[ORM\Column(name: 'visitor_hash', length: 64)]
    private string $visitorHash;

    #[ORM\Column(name: 'hash_version')]
    private int $hashVersion;

    #[ORM\Column(name: 'first_seen_at')]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(name: 'last_seen_at')]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(name: 'session_count')]
    private int $sessionCount = 0;

    #[ORM\Column(name: 'pageview_count')]
    private int $pageviewCount = 0;

    #[ORM\Column(name: 'event_count')]
    private int $eventCount = 0;

    #[ORM\Column(name: 'first_referrer_host', length: 190, nullable: true)]
    private ?string $firstReferrerHost = null;

    #[ORM\Column(name: 'landing_path', length: 255, nullable: true)]
    private ?string $landingPath = null;

    #[ORM\Column(name: 'last_path', length: 255, nullable: true)]
    private ?string $lastPath = null;

    #[ORM\Column(name: 'device_type', length: 20)]
    private string $deviceType;

    #[ORM\Column(length: 40)]
    private string $browser;

    #[ORM\Column(length: 40)]
    private string $os;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $language = null;

    #[ORM\Column(name: 'country_code', length: 2, nullable: true)]
    private ?string $countryCode = null;

    private function __construct(
        string $siteId,
        string $visitorHash,
        \DateTimeImmutable $seenAt,
        string $deviceType,
        string $browser,
        string $os,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->siteId = $siteId;
        $this->visitorHash = $visitorHash;
        $this->hashVersion = self::HASH_VERSION;
        $this->firstSeenAt = $seenAt;
        $this->lastSeenAt = $seenAt;
        $this->deviceType = self::clip($deviceType, 20);
        $this->browser = self::clip($browser, 40);
        $this->os = self::clip($os, 40);
    }

    /**
     * First sighting of a visitor hash on a site.
     *
     * @param string $visitorHash Hex HMAC of the raw client id, see {@see Visitor::HASH_LENGTH}.
     * @param \DateTimeImmutable $seenAt Server time of the hit that created the visitor.
     * @param string|null $firstReferrerHost Referrer host of the first hit (`null` for direct).
     * @param string|null $landingPath Path of the first hit.
     *
     * @throws \InvalidArgumentException When the visitor hash is empty.
     */
    public static function create(
        string $siteId,
        string $visitorHash,
        \DateTimeImmutable $seenAt,
        string $deviceType = '',
        string $browser = '',
        string $os = '',
        ?string $language = null,
        ?string $countryCode = null,
        ?string $firstReferrerHost = null,
        ?string $landingPath = null,
        int $hashVersion = self::HASH_VERSION,
    ): self {
        $visitorHash = trim($visitorHash);
        if ($visitorHash === '') {
            throw new \InvalidArgumentException('analytics.error.visitor_hash_required');
        }

        $visitor = new self($siteId, self::clip($visitorHash, 64), $seenAt, $deviceType, $browser, $os);
        $visitor->hashVersion = $hashVersion > 0 ? $hashVersion : self::HASH_VERSION;
        $visitor->language = self::nullable($language, 12);
        $visitor->countryCode = self::normaliseCountryCode($countryCode);
        $visitor->firstReferrerHost = self::nullable($firstReferrerHost, 190);
        $visitor->landingPath = self::path($landingPath);
        $visitor->lastPath = $visitor->landingPath;

        return $visitor;
    }

    /**
     * Record another hit of an existing visitor.
     *
     * @param \DateTimeImmutable $now Server time of the hit (never the client beacon timestamp).
     * @param string $path Page path of the hit; an empty value keeps the previous `last_path`.
     */
    public function touch(\DateTimeImmutable $now, string $path): void
    {
        $this->lastSeenAt = $now;

        $path = self::clip($path, 255);
        if ($path !== '') {
            $this->lastPath = $path;
        }
    }

    /** Refresh the reduced user-agent hints; empty/null values keep the stored ones. */
    public function updateHints(
        string $deviceType = '',
        string $browser = '',
        string $os = '',
        ?string $language = null,
        ?string $countryCode = null,
    ): void {
        if (trim($deviceType) !== '') {
            $this->deviceType = self::clip($deviceType, 20);
        }
        if (trim($browser) !== '') {
            $this->browser = self::clip($browser, 40);
        }
        if (trim($os) !== '') {
            $this->os = self::clip($os, 40);
        }
        if (($language = self::nullable($language, 12)) !== null) {
            $this->language = $language;
        }
        if (($countryCode = self::normaliseCountryCode($countryCode)) !== null) {
            $this->countryCode = $countryCode;
        }
    }

    public function setFirstReferrerHost(?string $referrerHost): void
    {
        $this->firstReferrerHost = self::nullable($referrerHost, 190);
    }

    public function setLandingPath(?string $path): void
    {
        $this->landingPath = self::path($path);
    }

    public function countPageview(): void
    {
        $this->pageviewCount++;
    }

    public function countEvent(): void
    {
        $this->eventCount++;
    }

    public function countSession(): void
    {
        $this->sessionCount++;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function siteId(): string
    {
        return $this->siteId;
    }

    public function visitorHash(): string
    {
        return $this->visitorHash;
    }

    public function hashVersion(): int
    {
        return $this->hashVersion;
    }

    public function firstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function lastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function sessionCount(): int
    {
        return $this->sessionCount;
    }

    public function pageviewCount(): int
    {
        return $this->pageviewCount;
    }

    public function eventCount(): int
    {
        return $this->eventCount;
    }

    public function firstReferrerHost(): ?string
    {
        return $this->firstReferrerHost;
    }

    public function landingPath(): ?string
    {
        return $this->landingPath;
    }

    public function lastPath(): ?string
    {
        return $this->lastPath;
    }

    public function deviceType(): string
    {
        return $this->deviceType;
    }

    public function browser(): string
    {
        return $this->browser;
    }

    public function os(): string
    {
        return $this->os;
    }

    public function language(): ?string
    {
        return $this->language;
    }

    public function countryCode(): ?string
    {
        return $this->countryCode;
    }

    private static function path(?string $path): ?string
    {
        $path = $path !== null ? self::clip($path, 255) : '';

        return $path !== '' ? $path : null;
    }

    private static function nullable(?string $value, int $max): ?string
    {
        $value = $value !== null ? self::clip($value, $max) : '';

        return $value !== '' ? $value : null;
    }

    /** Country is never resolved, only taken from an inbound header: keep ISO 3166-1 alpha-2 or nothing. */
    private static function normaliseCountryCode(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }

    /** Keep a stored string within its column capacity so collection cannot fail on long input. */
    private static function clip(string $value, int $max): string
    {
        $value = trim($value);

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
