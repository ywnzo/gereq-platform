<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Gereq\Config\Repository;
use Gereq\Http\Router;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\StagingStore;
use Gereq\Publish\StagingWriter;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Modules\Analytics\Application\AnalyticsConfig;
use Modules\Analytics\Application\TrackerConfig;
use PHPUnit\Framework\TestCase;

/**
 * Public, cacheable tracker bootstrap (README "Public surface"): only the public subset leaves
 * the server, and the inline snippet must emit exactly the `data-*` contract tracker.js reads.
 */
final class TrackerConfigTest extends TestCase
{
    public function testPayloadExposesOnlyTheDocumentedPublicKeys(): void
    {
        $tracker = $this->tracker();
        $payload = $tracker->payload(['key' => 'grq_an_test', 'secret' => 'deadbeefdeadbeef', 'enabled' => true]);

        self::assertSame(
            ['enabled', 'endpoint', 'site', 'consent', 'tracker_url', 'beacon_max_events', 'respect_dnt'],
            array_keys($payload),
        );
        self::assertSame('grq_an_test', $payload['site']);
        self::assertSame(['storage_key' => 'cookie-consent', 'category' => 'statistics'], $payload['consent']);
        self::assertSame(50, $payload['beacon_max_events']);
        self::assertTrue($payload['respect_dnt']);
        self::assertTrue($payload['enabled']);

        $adminOnly = [
            'secret', 'keep_bot_hits', 'session_idle_minutes', 'internal_ips',
            'retention_raw_days', 'retention_visitor_days', 'retention_rollup_days',
            'purge_batch_size', 'purge_on_revocation', 'consent_storage_key',
        ];
        foreach ($adminOnly as $key) {
            self::assertArrayNotHasKey($key, $payload, $key . ' must never leave the server');
        }
        self::assertStringNotContainsString('deadbeefdeadbeef', (string) json_encode($payload));
    }

    public function testEndpointAndTrackerUrlUseThePublicRoutes(): void
    {
        $tracker = $this->tracker();
        $payload = $tracker->payload(['key' => 'grq_an_test']);

        self::assertStringEndsWith('/api/public/analytics/collect', (string) $payload['endpoint']);
        self::assertStringEndsWith(
            '/api/public/analytics/tracker.js?v=' . TrackerConfig::TRACKER_VERSION,
            $tracker->trackerUrl(),
        );
        self::assertSame((new \Modules\Analytics\AnalyticsModule())->version(), TrackerConfig::TRACKER_VERSION);
    }

    public function testSnippetEmitsEveryAttributeTheTrackerReads(): void
    {
        $snippet = $this->tracker()->snippet(['key' => 'grq_an_test', 'enabled' => true, 'respect_dnt' => true]);

        $trackerJs = file_get_contents(__DIR__ . '/../Resources/public/tracker.js');
        self::assertNotFalse($trackerJs);
        preg_match_all("/'(data-[a-z-]+)'/", $trackerJs, $matches);
        $attributes = array_values(array_unique($matches[1]));
        self::assertNotEmpty($attributes, 'tracker.js reads no data-* attributes');
        foreach ($attributes as $attribute) {
            self::assertStringContainsString(
                $attribute . '=',
                $snippet,
                'tracker.js reads ' . $attribute . ' but the snippet does not emit it',
            );
        }
        foreach ([
            'data-site', 'data-endpoint', 'data-consent-endpoint', 'data-config',
            'data-consent-key', 'data-consent-category', 'data-max-events', 'data-respect-dnt',
        ] as $attribute) {
            self::assertStringContainsString($attribute . '="', $snippet, $attribute);
        }

        self::assertMatchesRegularExpression('/^<script defer src="[^"]+"/', $snippet);
        self::assertStringContainsString('data-analytics="gereq"', $snippet);
        self::assertStringContainsString('data-site="grq_an_test"', $snippet);
        self::assertStringContainsString('data-consent-key="cookie-consent"', $snippet);
        self::assertStringContainsString('data-consent-category="statistics"', $snippet);
        self::assertStringContainsString('data-max-events="50"', $snippet);
        self::assertStringContainsString('data-respect-dnt="1"', $snippet);
        self::assertStringContainsString('/api/public/analytics/collect"', $snippet);
        self::assertStringContainsString('/api/public/analytics/consent"', $snippet);
        self::assertStringContainsString('/api/public/analytics/config"', $snippet);
    }

    public function testSnippetNeverContainsTheSiteSecret(): void
    {
        $secret = str_repeat('ab', 32);
        $snippet = $this->tracker()->snippet(['key' => 'grq_an_test', 'secret' => $secret]);

        self::assertStringNotContainsString($secret, $snippet);
        self::assertStringNotContainsString('secret', $snippet);
    }

    public function testPerSiteOverridesAreReflected(): void
    {
        $tracker = $this->tracker();
        $site = ['key' => 'grq_an_test', 'enabled' => false, 'respect_dnt' => false];

        $payload = $tracker->payload($site);
        self::assertFalse($payload['enabled']);
        self::assertFalse($payload['respect_dnt']);

        $snippet = $tracker->snippet($site);
        self::assertStringContainsString('data-respect-dnt="0"', $snippet);
        self::assertStringContainsString(
            'data-respect-dnt="1"',
            $tracker->snippet(['key' => 'grq_an_test', 'respect_dnt' => true]),
        );
    }

    public function testConfigDrivenValuesAreReflected(): void
    {
        $tracker = $this->tracker([
            new SettingRecord('analytics.beacon_max_events', '12', SettingRecord::TYPE_INTEGER),
            new SettingRecord('analytics.consent_category', 'analytics', SettingRecord::TYPE_STRING),
            new SettingRecord('analytics.consent_storage_key', 'cmp-consent', SettingRecord::TYPE_STRING),
        ]);

        $payload = $tracker->payload(['key' => 'grq_an_test']);
        self::assertSame(12, $payload['beacon_max_events']);
        self::assertSame(['storage_key' => 'cmp-consent', 'category' => 'analytics'], $payload['consent']);

        $snippet = $tracker->snippet(['key' => 'grq_an_test']);
        self::assertStringContainsString('data-max-events="12"', $snippet);
        self::assertStringContainsString('data-consent-key="cmp-consent"', $snippet);
        self::assertStringContainsString('data-consent-category="analytics"', $snippet);
    }

    public function testGlobalTrackerSwitchDisablesThePublicPayload(): void
    {
        $tracker = $this->tracker([
            new SettingRecord('analytics.tracker_enabled', '0', SettingRecord::TYPE_BOOLEAN),
        ]);

        self::assertFalse($tracker->payload(['key' => 'grq_an_test', 'enabled' => true])['enabled']);
    }

    /**
     * @param list<SettingRecord> $rows
     */
    private function tracker(array $rows = []): TrackerConfig
    {
        $router = new Router();
        $handler = static fn (): null => null;
        $router->post('/api/public/analytics/collect', $handler)->name('api.public.analytics.collect');
        $router->post('/api/public/analytics/consent', $handler)->name('api.public.analytics.consent');
        $router->get('/api/public/analytics/config', $handler)->name('api.public.analytics.config');
        $router->get('/api/public/analytics/tracker.js', $handler)->name('api.public.analytics.tracker');

        return new TrackerConfig($this->config($rows), $router);
    }

    /**
     * @param list<SettingRecord> $rows
     */
    private function config(array $rows = []): AnalyticsConfig
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
        ));
    }
}
