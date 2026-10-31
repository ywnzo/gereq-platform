<?php

declare(strict_types=1);

namespace Modules\Analytics\Application;

use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\Module\CapabilityRegistry;

/**
 * Single reader of the `analytics.*` settings.
 *
 * Every controller, job, hook and the tracker config goes through this service; per-site overrides
 * (site columns) always win over the global settings. Site rows are plain arrays keyed by the
 * database column names documented in `modules/Analytics/README.md`.
 */
final class AnalyticsConfig
{
    public const TRACKER_ENABLED = 'analytics.tracker_enabled';
    public const CONSENT_CATEGORY = 'analytics.consent_category';
    public const CONSENT_STORAGE_KEY = 'analytics.consent_storage_key';
    public const RESPECT_DNT = 'analytics.respect_dnt';
    public const SESSION_IDLE_MINUTES = 'analytics.session_idle_minutes';
    public const BEACON_MAX_EVENTS = 'analytics.beacon_max_events';
    public const KEEP_BOT_HITS = 'analytics.keep_bot_hits';
    public const INTERNAL_IPS = 'analytics.internal_ips';
    public const PURGE_ON_REVOCATION = 'analytics.purge_on_revocation';
    public const RETENTION_RAW_DAYS = 'analytics.retention_raw_days';
    public const RETENTION_VISITOR_DAYS = 'analytics.retention_visitor_days';
    public const RETENTION_ROLLUP_DAYS = 'analytics.retention_rollup_days';
    public const PURGE_BATCH_SIZE = 'analytics.purge_batch_size';

    public const SECTION_TRACKING = 'tracking';
    public const SECTION_CONSENT = 'consent';
    public const SECTION_RETENTION = 'retention';

    public const MODULE_ID = 'analytics';

    /** Absolute upper bound for the delete batch, whatever the setting says. */
    private const MAX_PURGE_BATCH = 50_000;

    public function __construct(
        private readonly Settings $settings,
        private readonly ?CapabilityRegistry $capabilities = null,
    ) {
    }

    /**
     * Settings declared in `register()` — key => type + default.
     *
     * @return array<string, array{type: string, default: mixed}>
     */
    public static function settingDefinitions(): array
    {
        return [
            self::TRACKER_ENABLED => ['type' => SettingRecord::TYPE_BOOLEAN, 'default' => true],
            self::CONSENT_CATEGORY => ['type' => SettingRecord::TYPE_STRING, 'default' => 'statistics'],
            self::CONSENT_STORAGE_KEY => ['type' => SettingRecord::TYPE_STRING, 'default' => 'cookie-consent'],
            self::RESPECT_DNT => ['type' => SettingRecord::TYPE_BOOLEAN, 'default' => true],
            self::SESSION_IDLE_MINUTES => ['type' => SettingRecord::TYPE_INTEGER, 'default' => 30],
            self::BEACON_MAX_EVENTS => ['type' => SettingRecord::TYPE_INTEGER, 'default' => 50],
            self::KEEP_BOT_HITS => ['type' => SettingRecord::TYPE_BOOLEAN, 'default' => false],
            self::INTERNAL_IPS => ['type' => SettingRecord::TYPE_STRING, 'default' => ''],
            self::PURGE_ON_REVOCATION => ['type' => SettingRecord::TYPE_BOOLEAN, 'default' => true],
            self::RETENTION_RAW_DAYS => ['type' => SettingRecord::TYPE_INTEGER, 'default' => 90],
            self::RETENTION_VISITOR_DAYS => ['type' => SettingRecord::TYPE_INTEGER, 'default' => 395],
            self::RETENTION_ROLLUP_DAYS => ['type' => SettingRecord::TYPE_INTEGER, 'default' => 1095],
            self::PURGE_BATCH_SIZE => ['type' => SettingRecord::TYPE_INTEGER, 'default' => 5000],
        ];
    }

    /** `analytics.retention_raw_days` → `analytics.settings.retention_raw_days`. */
    public static function labelKey(string $key): string
    {
        return 'analytics.settings.' . self::shortKey($key);
    }

    /** `analytics.retention_raw_days` → `retention_raw_days`. */
    public static function shortKey(string $key): string
    {
        return str_starts_with($key, self::MODULE_ID . '.')
            ? substr($key, strlen(self::MODULE_ID) + 1)
            : $key;
    }

    /** Admin settings section a key belongs to. */
    public static function sectionOf(string $key): string
    {
        return match ($key) {
            self::TRACKER_ENABLED,
            self::RESPECT_DNT,
            self::SESSION_IDLE_MINUTES,
            self::BEACON_MAX_EVENTS,
            self::KEEP_BOT_HITS,
            self::INTERNAL_IPS => self::SECTION_TRACKING,
            self::CONSENT_CATEGORY,
            self::CONSENT_STORAGE_KEY,
            self::PURGE_ON_REVOCATION => self::SECTION_CONSENT,
            default => self::SECTION_RETENTION,
        };
    }

    /** `analytics.settings.section.tracking` */
    public static function sectionLabelKey(string $section): string
    {
        return 'analytics.settings.section.' . $section;
    }

    /** @return list<string> */
    public static function sections(): array
    {
        return [self::SECTION_TRACKING, self::SECTION_CONSENT, self::SECTION_RETENTION];
    }

    /**
     * Master switch for injection and the tracker config; per-site `enabled` wins.
     *
     * @param array<string, mixed>|null $site
     */
    public function trackerEnabled(?array $site = null): bool
    {
        if ($site !== null && ($site['enabled'] ?? true) === false) {
            return false;
        }

        return $this->boolSetting(self::TRACKER_ENABLED, true);
    }

    public function consentCategory(): string
    {
        if ($this->capabilities?->has('cookie')) {
            return 'statistics';
        }
        $value = trim($this->stringSetting(self::CONSENT_CATEGORY, 'statistics'));

        return $value !== '' ? $value : 'statistics';
    }

    public function consentStorageKey(): string
    {
        if ($this->capabilities?->has('cookie')) {
            $provider = $this->capabilities->get('cookie');
            if (method_exists($provider, 'storageKey')) {
                return $provider->storageKey();
            }
        }
        $value = trim($this->stringSetting(self::CONSENT_STORAGE_KEY, 'cookie-consent'));

        return $value !== '' ? $value : 'cookie-consent';
    }

    /** @param array<string, mixed>|null $site */
    public function respectDnt(?array $site = null): bool
    {
        if ($site !== null && array_key_exists('respect_dnt', $site)) {
            return (bool) $site['respect_dnt'];
        }

        return $this->boolSetting(self::RESPECT_DNT, true);
    }

    public function sessionIdleMinutes(): int
    {
        return max(1, $this->intSetting(self::SESSION_IDLE_MINUTES, 30));
    }

    public function beaconMaxEvents(): int
    {
        return max(1, $this->intSetting(self::BEACON_MAX_EVENTS, 50));
    }

    /**
     * Whether matched bot / internal traffic is stored (classifiable) instead of dropped.
     *
     * @param array<string, mixed>|null $site
     */
    public function keepBotHits(?array $site = null): bool
    {
        if ($site !== null && array_key_exists('keep_bot_hits', $site)) {
            return (bool) $site['keep_bot_hits'];
        }

        return $this->boolSetting(self::KEEP_BOT_HITS, false);
    }

    public function purgeOnRevocation(): bool
    {
        return $this->boolSetting(self::PURGE_ON_REVOCATION, true);
    }

    /**
     * Global raw-traffic retention in days; a site may override it.
     *
     * @param array<string, mixed>|null $site
     */
    public function retentionRawDays(?array $site = null): int
    {
        $override = $site['retention_override_days'] ?? null;
        if (is_int($override) || (is_string($override) && $override !== '')) {
            return max(1, (int) $override);
        }

        return max(1, $this->intSetting(self::RETENTION_RAW_DAYS, 90));
    }

    public function retentionVisitorDays(): int
    {
        return max(1, $this->intSetting(self::RETENTION_VISITOR_DAYS, 395));
    }

    public function retentionRollupDays(): int
    {
        return max(1, $this->intSetting(self::RETENTION_ROLLUP_DAYS, 1095));
    }

    public function purgeBatchSize(): int
    {
        return min(self::MAX_PURGE_BATCH, max(100, $this->intSetting(self::PURGE_BATCH_SIZE, 5000)));
    }

    /** @return list<string> Global internal CIDR list (comma / newline separated). */
    public function internalCidrs(): array
    {
        return self::parseList($this->stringSetting(self::INTERNAL_IPS, ''));
    }

    /**
     * Per-site excluded CIDR list.
     *
     * @param array<string, mixed>|null $site
     * @return list<string>
     */
    public function excludedIps(?array $site = null): array
    {
        if ($site === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            self::asList($site['excluded_ips'] ?? []),
        )));
    }

    /**
     * Per-site excluded path prefixes.
     *
     * @param array<string, mixed>|null $site
     * @return list<string>
     */
    public function excludedPaths(?array $site = null): array
    {
        if ($site === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            self::asList($site['excluded_paths'] ?? []),
        )));
    }

    /**
     * Split a comma / newline separated setting value into a clean list.
     *
     * @return list<string>
     */
    public static function parseList(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $part): string => trim($part),
            $parts,
        )));
    }

    /** @return list<mixed> */
    private static function asList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (is_string($value) && $value !== '') {
            return self::parseList($value);
        }

        return [];
    }

    private function boolSetting(string $key, bool $default): bool
    {
        $value = $this->settings->get($key, $default);

        return is_bool($value) ? $value : (bool) $value;
    }

    private function intSetting(string $key, int $default): int
    {
        $value = $this->settings->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function stringSetting(string $key, string $default): string
    {
        $value = $this->settings->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }
}
