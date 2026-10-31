<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

/**
 * Tracked site (`analytics_sites`): one row per storefront the tracker is injected into.
 *
 * The site owns the collection policy (enabled switch, DNT/bot handling, exclusions) and the
 * HMAC secret the pseudonymiser hashes the raw visitor id with. Domains are stored as a plain
 * list so host resolution stays a map lookup without an extra table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'analytics_sites')]
#[ORM\UniqueConstraint(name: 'uniq_analytics_sites_key', columns: ['key'])]
#[FormGenerator(
    form: 'analytics.site.save',
    cols: 2,
    exclude: ['id', 'key', 'secret', 'createdAt', 'updatedAt', 'domains', 'excludedPaths', 'excludedIps'],
)]
class Site
{
    /** Prefix of the public tracker key (`grq_an_…`); the key is generated once and never rotated. */
    public const KEY_PREFIX = 'grq_an_';

    /** Length of the hexadecimal HMAC secret (`bin2hex(random_bytes(32))`). */
    public const SECRET_LENGTH = 64;

    /** Default per-site timezone used to bucket rollup days; mirrors `config/app.php`. */
    public const DEFAULT_TIMEZONE = 'Europe/Prague';

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 190)]
    #[FormField(label: 'analytics.sites.field.name', required: true, order: 10)]
    private string $name;

    #[ORM\Column(length: 64)]
    private string $key;

    /** @var list<string> Lowercase host names, `*.example.com` matches subdomains. */
    #[ORM\Column(type: 'json')]
    private array $domains;

    #[ORM\Column]
    #[FormField(label: 'analytics.sites.field.enabled', type: 'switch', order: 30)]
    private bool $enabled;

    #[ORM\Column(length: 64)]
    #[FormField(label: 'analytics.sites.field.timezone', options: ['maxlength' => 64], order: 20)]
    private string $timezone;

    #[ORM\Column(length: 64)]
    private string $secret;

    #[ORM\Column(name: 'retention_override_days', nullable: true)]
    #[FormField(label: 'analytics.sites.field.retention_override_days', type: 'number', order: 60)]
    private ?int $retentionOverrideDays = null;

    #[ORM\Column(name: 'keep_bot_hits')]
    #[FormField(label: 'analytics.sites.field.keep_bot_hits', type: 'switch', order: 40)]
    private bool $keepBotHits;

    #[ORM\Column(name: 'respect_dnt')]
    #[FormField(label: 'analytics.sites.field.respect_dnt', type: 'switch', order: 50)]
    private bool $respectDnt;

    /** @var list<string> Path prefixes that are never collected. */
    #[ORM\Column(name: 'excluded_paths', type: 'json')]
    private array $excludedPaths;

    /** @var list<string> Per-site CIDR entries treated as internal traffic. */
    #[ORM\Column(name: 'excluded_ips', type: 'json')]
    private array $excludedIps;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /** @param list<string> $domains */
    private function __construct(string $name, array $domains)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = self::clip($name, 190);
        $this->key = self::KEY_PREFIX . bin2hex(random_bytes(16));
        $this->domains = self::normaliseDomains($domains);
        $this->enabled = true;
        $this->timezone = self::DEFAULT_TIMEZONE;
        $this->secret = self::generateSecret();
        $this->retentionOverrideDays = null;
        $this->keepBotHits = false;
        $this->respectDnt = true;
        $this->excludedPaths = [];
        $this->excludedIps = [];
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * Create a site with an already generated `key` and `secret`.
     *
     * @param list<string> $domains Host names (optionally with scheme/path, normalised on storage).
     *
     * @throws \InvalidArgumentException When the name is empty.
     */
    public static function create(string $name, array $domains): self
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('analytics.error.site_name_required');
        }

        return new self($name, $domains);
    }

    /**
     * Replace the HMAC secret. All previously stored `visitor_hash` values of this site become
     * unmatchable — that is the intended "forget everyone" lever.
     *
     * @return string The new secret, shown once in the admin UI.
     */
    public function rotateSecret(): string
    {
        $this->secret = self::generateSecret();
        $this->touch();

        return $this->secret;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function key(): string
    {
        return $this->key;
    }

    /** @return list<string> */
    public function domains(): array
    {
        return $this->domains;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function secret(): string
    {
        return $this->secret;
    }

    public function retentionOverrideDays(): ?int
    {
        return $this->retentionOverrideDays;
    }

    public function keepBotHits(): bool
    {
        return $this->keepBotHits;
    }

    public function respectDnt(): bool
    {
        return $this->respectDnt;
    }

    /** @return list<string> */
    public function excludedPaths(): array
    {
        return $this->excludedPaths;
    }

    /** @return list<string> */
    public function excludedIps(): array
    {
        return $this->excludedIps;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** True when the given request host (or referrer host) belongs to this site. */
    public function matchesDomain(string $host): bool
    {
        $host = self::normaliseHost($host);
        if ($host === '') {
            return false;
        }

        foreach ($this->domains as $domain) {
            if ($domain === $host) {
                return true;
            }
            // `*.example.com` matches subdomains only, never the bare domain.
            if (str_starts_with($domain, '*.') && str_ends_with($host, substr($domain, 1))) {
                return true;
            }
        }

        return false;
    }

    public function setName(string $name): void
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('analytics.error.site_name_required');
        }

        $this->name = self::clip($name, 190);
        $this->touch();
    }

    /** @param list<string> $domains */
    public function setDomains(array $domains): void
    {
        $this->domains = self::normaliseDomains($domains);
        $this->touch();
    }

    public function addDomain(string $domain): void
    {
        $domain = self::normaliseHost($domain);
        if ($domain === '' || in_array($domain, $this->domains, true)) {
            return;
        }

        $this->domains[] = $domain;
        $this->touch();
    }

    public function removeDomain(string $domain): void
    {
        $domain = self::normaliseHost($domain);
        $kept = array_values(array_filter($this->domains, static fn (string $item): bool => $item !== $domain));
        if ($kept === $this->domains) {
            return;
        }

        $this->domains = $kept;
        $this->touch();
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->touch();
    }

    public function setTimezone(string $timezone): void
    {
        $timezone = trim($timezone);
        if ($timezone === '') {
            $timezone = self::DEFAULT_TIMEZONE;
        }

        try {
            new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('analytics.error.invalid_timezone', 0, $e);
        }

        $this->timezone = self::clip($timezone, 64);
        $this->touch();
    }

    public function setRetentionOverrideDays(?int $days): void
    {
        $this->retentionOverrideDays = $days !== null && $days > 0 ? $days : null;
        $this->touch();
    }

    public function setKeepBotHits(bool $keepBotHits): void
    {
        $this->keepBotHits = $keepBotHits;
        $this->touch();
    }

    public function setRespectDnt(bool $respectDnt): void
    {
        $this->respectDnt = $respectDnt;
        $this->touch();
    }

    /** @param list<string> $paths */
    public function setExcludedPaths(array $paths): void
    {
        $this->excludedPaths = self::cleanList($paths);
        $this->touch();
    }

    /** @param list<string> $ips */
    public function setExcludedIps(array $ips): void
    {
        $this->excludedIps = self::cleanList($ips);
        $this->touch();
    }

    private static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Normalise host entries so request hosts and referrer hosts compare as plain strings:
     * lowercase, no scheme/path/port, trailing empties dropped.
     *
     * @param list<string> $domains
     * @return list<string>
     */
    private static function normaliseDomains(array $domains): array
    {
        $out = [];
        foreach ($domains as $domain) {
            $host = self::normaliseHost($domain);
            if ($host !== '' && !in_array($host, $out, true)) {
                $out[] = $host;
            }
        }

        return $out;
    }

    private static function normaliseHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }

        $wildcard = str_starts_with($host, '*.');
        if ($wildcard) {
            $host = substr($host, 2);
        }

        $parsed = parse_url(str_contains($host, '://') ? $host : '//' . $host, PHP_URL_HOST);
        if (!is_string($parsed) || $parsed === '') {
            return '';
        }

        return $wildcard ? '*.' . $parsed : $parsed;
    }

    /**
     * Trim, drop empties and duplicates while keeping the given order.
     *
     * @param list<string> $values
     * @return list<string>
     */
    private static function cleanList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value !== '' && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /** Keep a stored string within its column capacity so collection cannot fail on long input. */
    private static function clip(string $value, int $max): string
    {
        $value = trim($value);

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
