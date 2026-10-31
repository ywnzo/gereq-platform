<?php

declare(strict_types=1);

namespace Modules\Analytics\Application;

use Modules\Analytics\Application\Repositories\RollupRepository;
use Modules\Analytics\Application\Repositories\SiteRepository;

/**
 * Capability implementation registered as `analytics` (see `module.php` / `register()`).
 *
 * Other modules talk to Analytics through this provider instead of reading `analytics_*` tables.
 * Site descriptors never expose the HMAC `secret`.
 */
final class AnalyticsProvider
{
    public function __construct(
        private readonly AnalyticsConfig $config,
        private readonly TrackerConfig $tracker,
        private readonly SiteRepository $sites,
        private readonly RollupRepository $rollups,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sites(): array
    {
        return array_map(self::publicSite(...), $this->sites->all());
    }

    /** @return array<string, mixed>|null */
    public function siteByHost(string $host): ?array
    {
        $site = $this->sites->findEnabledByDomain($host);

        return $site !== null ? self::publicSite($site) : null;
    }

    /** @return array<string, mixed>|null */
    public function siteByKey(string $key): ?array
    {
        $site = $this->sites->findEnabledByKey($key);

        return $site !== null ? self::publicSite($site) : null;
    }

    /** @param array<string, mixed>|null $site */
    public function trackerEnabled(?array $site = null): bool
    {
        return $this->config->trackerEnabled($site);
    }

    public function trackerUrl(): string
    {
        return $this->tracker->trackerUrl();
    }

    public function consentCategory(): string
    {
        return $this->config->consentCategory();
    }

    /**
     * Aggregated daily stats per day for one site.
     *
     * @return list<array{day: string, visitors: int, new_visitors: int, sessions: int, bounces: int,
     *   pageviews: int, events: int, total_duration_seconds: int}>
     */
    public function dailyStats(string $siteId, string $from, string $to): array
    {
        return $this->rollups->statsRange([$siteId], $from, $to);
    }

    /**
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    public static function publicSite(array $site): array
    {
        unset($site['secret']);

        return $site;
    }
}
