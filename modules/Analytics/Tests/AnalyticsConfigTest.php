<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Gereq\Config\Repository;
use Gereq\Module\CapabilityRegistry;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\StagingStore;
use Gereq\Publish\StagingWriter;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Modules\Analytics\Application\AnalyticsConfig;
use PHPUnit\Framework\TestCase;

/**
 * Single reader of the `analytics.*` settings (README "Settings"); per-site overrides always win
 * over the global settings.
 */
final class AnalyticsConfigTest extends TestCase
{
    public function testCookieCapabilityOverridesLegacyConsentSettings(): void
    {
        $capabilities = new CapabilityRegistry();
        $capabilities->register('cookie', 'cookie', new class {
            public function storageKey(): string
            {
                return 'custom-consent';
            }
        });
        $config = $this->config([
            new SettingRecord(AnalyticsConfig::CONSENT_CATEGORY, 'marketing', 'string'),
            new SettingRecord(AnalyticsConfig::CONSENT_STORAGE_KEY, 'legacy-consent', 'string'),
        ], $capabilities);
        self::assertSame('statistics', $config->consentCategory());
        self::assertSame('custom-consent', $config->consentStorageKey());
    }

    public function testDefaultsMatchTheReadmeTable(): void
    {
        $config = $this->config();

        self::assertTrue($config->trackerEnabled());
        self::assertSame('statistics', $config->consentCategory());
        self::assertSame('cookie-consent', $config->consentStorageKey());
        self::assertTrue($config->respectDnt());
        self::assertSame(30, $config->sessionIdleMinutes());
        self::assertSame(50, $config->beaconMaxEvents());
        self::assertFalse($config->keepBotHits());
        self::assertTrue($config->purgeOnRevocation());
        self::assertSame(90, $config->retentionRawDays());
        self::assertSame(395, $config->retentionVisitorDays());
        self::assertSame(1095, $config->retentionRollupDays());
        self::assertSame(5000, $config->purgeBatchSize());
        self::assertSame([], $config->internalCidrs());
        self::assertSame([], $config->excludedIps());
        self::assertSame([], $config->excludedPaths());
    }

    public function testSettingDefinitionsMatchTheReadmeInventory(): void
    {
        self::assertSame([
            'analytics.tracker_enabled' => ['type' => 'boolean', 'default' => true],
            'analytics.consent_category' => ['type' => 'string', 'default' => 'statistics'],
            'analytics.consent_storage_key' => ['type' => 'string', 'default' => 'cookie-consent'],
            'analytics.respect_dnt' => ['type' => 'boolean', 'default' => true],
            'analytics.session_idle_minutes' => ['type' => 'integer', 'default' => 30],
            'analytics.beacon_max_events' => ['type' => 'integer', 'default' => 50],
            'analytics.keep_bot_hits' => ['type' => 'boolean', 'default' => false],
            'analytics.internal_ips' => ['type' => 'string', 'default' => ''],
            'analytics.purge_on_revocation' => ['type' => 'boolean', 'default' => true],
            'analytics.retention_raw_days' => ['type' => 'integer', 'default' => 90],
            'analytics.retention_visitor_days' => ['type' => 'integer', 'default' => 395],
            'analytics.retention_rollup_days' => ['type' => 'integer', 'default' => 1095],
            'analytics.purge_batch_size' => ['type' => 'integer', 'default' => 5000],
        ], AnalyticsConfig::settingDefinitions());
    }

    public function testPerSiteRetentionOverrideWinsOverGlobal(): void
    {
        $config = $this->config([new SettingRecord('analytics.retention_raw_days', '180', SettingRecord::TYPE_INTEGER)]);

        self::assertSame(180, $config->retentionRawDays());
        self::assertSame(7, $config->retentionRawDays(['retention_override_days' => 7]));
        self::assertSame(7, $config->retentionRawDays(['retention_override_days' => '7']));
        // Empty / missing override falls back to the global value.
        self::assertSame(180, $config->retentionRawDays(['retention_override_days' => null]));
        self::assertSame(180, $config->retentionRawDays(['retention_override_days' => '']));
        self::assertSame(180, $config->retentionRawDays([]));
        // Zero and negatives are clamped, never a zero-day retention.
        self::assertSame(1, $config->retentionRawDays(['retention_override_days' => 0]));
        self::assertSame(1, $config->retentionRawDays(['retention_override_days' => -5]));
    }

    public function testPerSiteKeepBotHitsOverrideWinsInBothDirections(): void
    {
        $globalOn = $this->config([new SettingRecord('analytics.keep_bot_hits', '1', SettingRecord::TYPE_BOOLEAN)]);
        self::assertTrue($globalOn->keepBotHits());
        self::assertFalse($globalOn->keepBotHits(['keep_bot_hits' => false]));
        self::assertTrue($globalOn->keepBotHits(['keep_bot_hits' => true]));

        $globalOff = $this->config();
        self::assertFalse($globalOff->keepBotHits());
        self::assertTrue($globalOff->keepBotHits(['keep_bot_hits' => true]));
        self::assertFalse($globalOff->keepBotHits(['keep_bot_hits' => false]));
        self::assertFalse($globalOff->keepBotHits(['enabled' => true]));
    }

    public function testPerSiteRespectDntOverrideWinsInBothDirections(): void
    {
        $globalOn = $this->config();
        self::assertTrue($globalOn->respectDnt());
        self::assertFalse($globalOn->respectDnt(['respect_dnt' => false]));
        self::assertTrue($globalOn->respectDnt(['respect_dnt' => true]));
        self::assertTrue($globalOn->respectDnt(['enabled' => true]));

        $globalOff = $this->config([new SettingRecord('analytics.respect_dnt', '0', SettingRecord::TYPE_BOOLEAN)]);
        self::assertFalse($globalOff->respectDnt());
        self::assertTrue($globalOff->respectDnt(['respect_dnt' => true]));
    }

    public function testDisabledSiteWinsOverTheGlobalTrackerSwitch(): void
    {
        $config = $this->config();
        self::assertTrue($config->trackerEnabled());
        self::assertFalse($config->trackerEnabled(['enabled' => false]));
        self::assertTrue($config->trackerEnabled(['enabled' => true]));

        $globalOff = $this->config([new SettingRecord('analytics.tracker_enabled', '0', SettingRecord::TYPE_BOOLEAN)]);
        self::assertFalse($globalOff->trackerEnabled());
        self::assertFalse($globalOff->trackerEnabled(['enabled' => true]));
    }

    public function testInternalCidrsParseNewlinesAndCommas(): void
    {
        $config = $this->config([new SettingRecord(
            'analytics.internal_ips',
            "10.0.0.0/8\n192.168.0.0/16, 172.16.0.0/12",
            SettingRecord::TYPE_STRING,
        )]);

        self::assertSame(
            ['10.0.0.0/8', '192.168.0.0/16', '172.16.0.0/12'],
            $config->internalCidrs(),
        );
    }

    public function testParseListDropsEmptyEntries(): void
    {
        self::assertSame([], AnalyticsConfig::parseList(''));
        self::assertSame([], AnalyticsConfig::parseList("  \n ,, "));
        self::assertSame(
            ['1.2.3.4', '5.6.7.8', '9.9.9.9'],
            AnalyticsConfig::parseList("1.2.3.4\n5.6.7.8, 9.9.9.9"),
        );
    }

    public function testExcludedIpsAndPathsAcceptStringsOrArrays(): void
    {
        $config = $this->config();

        self::assertSame([], $config->excludedIps());
        self::assertSame([], $config->excludedIps([]));
        self::assertSame(['1.2.3.4', '5.6.7.8'], $config->excludedIps(['excluded_ips' => "1.2.3.4\n5.6.7.8"]));
        self::assertSame(['1.2.3.4', '5.6.7.8'], $config->excludedIps(['excluded_ips' => [' 1.2.3.4 ', '', '5.6.7.8']]));
        self::assertSame(['/admin', '/checkout'], $config->excludedPaths(['excluded_paths' => ['/admin', ' /checkout ']]));
        self::assertSame([], $config->excludedPaths(null));
    }

    public function testNumericSettingsAreClampedToSafeBounds(): void
    {
        $low = $this->config([
            new SettingRecord('analytics.purge_batch_size', '10', SettingRecord::TYPE_INTEGER),
            new SettingRecord('analytics.session_idle_minutes', '0', SettingRecord::TYPE_INTEGER),
            new SettingRecord('analytics.beacon_max_events', '-3', SettingRecord::TYPE_INTEGER),
        ]);
        self::assertSame(100, $low->purgeBatchSize());
        self::assertSame(1, $low->sessionIdleMinutes());
        self::assertSame(1, $low->beaconMaxEvents());

        $high = $this->config([new SettingRecord('analytics.purge_batch_size', '999999', SettingRecord::TYPE_INTEGER)]);
        self::assertSame(50000, $high->purgeBatchSize());
    }

    public function testBlankStringSettingsFallBackToTheirDefaults(): void
    {
        $config = $this->config([
            new SettingRecord('analytics.consent_category', '   ', SettingRecord::TYPE_STRING),
            new SettingRecord('analytics.consent_storage_key', '', SettingRecord::TYPE_STRING),
        ]);

        self::assertSame('statistics', $config->consentCategory());
        self::assertSame('cookie-consent', $config->consentStorageKey());
    }

    public function testKeyHelpers(): void
    {
        self::assertSame('analytics.settings.retention_raw_days', AnalyticsConfig::labelKey('analytics.retention_raw_days'));
        self::assertSame('retention_raw_days', AnalyticsConfig::shortKey('analytics.retention_raw_days'));
        self::assertSame('other', AnalyticsConfig::shortKey('other'));
        self::assertSame('analytics.settings.section.tracking', AnalyticsConfig::sectionLabelKey('tracking'));
        self::assertSame(['tracking', 'consent', 'retention'], AnalyticsConfig::sections());
    }

    public function testEverySettingDefinitionHasAKnownSection(): void
    {
        $sections = AnalyticsConfig::sections();

        foreach (array_keys(AnalyticsConfig::settingDefinitions()) as $key) {
            self::assertContains(AnalyticsConfig::sectionOf($key), $sections, $key);
        }
        self::assertSame('tracking', AnalyticsConfig::sectionOf(AnalyticsConfig::TRACKER_ENABLED));
        self::assertSame('consent', AnalyticsConfig::sectionOf(AnalyticsConfig::CONSENT_CATEGORY));
        self::assertSame('retention', AnalyticsConfig::sectionOf(AnalyticsConfig::PURGE_BATCH_SIZE));
    }

    /**
     * @param list<SettingRecord> $rows
     */
    private function config(array $rows = [], ?CapabilityRegistry $capabilities = null): AnalyticsConfig
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findAll')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $publishables = new PublishableRegistry();

        return new AnalyticsConfig(new Settings(
            $em,
            new Repository([]),
            new ContentReadContext(),
            $publishables,
            new StagingWriter(new StagingStore($em), $publishables),
        ), $capabilities);
    }
}
