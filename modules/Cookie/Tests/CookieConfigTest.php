<?php

declare(strict_types=1);

namespace Modules\Cookie\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Gereq\Config\Repository;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\StagingStore;
use Gereq\Publish\StagingWriter;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Modules\Cookie\Application\CookieConfig;
use Modules\Cookie\Application\LegacySettingsImporter;
use Modules\Cookie\Publish\CookieSettingsPublishAdapter;
use PHPUnit\Framework\TestCase;

final class CookieConfigTest extends TestCase
{
    public function testNecessaryIsRequiredAndReplayDependsOnStatistics(): void
    {
        [$settings] = $this->settings([]);
        $payload = (new CookieConfig($settings))->payload();
        self::assertTrue($payload['enabled']);
        self::assertSame('cookie-consent', $payload['storage_key']);
        self::assertTrue($payload['categories'][0]['required']);
        self::assertContains('replay', array_column($payload['categories'], 'id'));
        [$settings] = $this->settings([new SettingRecord('cookie.statistics_enabled', '0', 'boolean', 'cookie')]);
        $ids = array_column((new CookieConfig($settings))->payload()['categories'], 'id');
        self::assertNotContains('statistics', $ids);
        self::assertNotContains('replay', $ids);
    }

    public function testCustomAppearanceAndMobileVisibilityArePublished(): void
    {
        $name = str_repeat('a', 32) . '.png';
        [$settings] = $this->settings([
            new SettingRecord('cookie.style', 'banner', 'string', 'cookie'),
            new SettingRecord('cookie.icon', '🛡️', 'string', 'cookie'),
            new SettingRecord('cookie.image', $name, 'string', 'cookie'),
            new SettingRecord('cookie.show_on_mobile', '0', 'boolean', 'cookie'),
        ]);
        $payload = (new CookieConfig($settings))->payload();
        self::assertSame('banner', $payload['style']);
        self::assertSame('🛡️', $payload['icon']);
        self::assertSame('/api/public/cookie/image/' . $name, $payload['image_url']);
        self::assertFalse($payload['show_on_mobile']);
    }

    public function testUnsafeAppearanceAndPolicyValuesCannotReachWidget(): void
    {
        [$settings] = $this->settings([
            new SettingRecord('cookie.accent', 'red;display:none', 'string', 'cookie'),
            new SettingRecord('cookie.position', 'arbitrary-css', 'string', 'cookie'),
            new SettingRecord('cookie.policy_path', '//external.example/tracker', 'string', 'cookie'),
            new SettingRecord('cookie.storage_key', 'invalid key', 'string', 'cookie'),
            new SettingRecord('cookie.style', 'unsafe', 'string', 'cookie'),
            new SettingRecord('cookie.image', '../../secret.png', 'string', 'cookie'),
        ]);
        $payload = (new CookieConfig($settings))->payload();
        self::assertSame('#cdea63', $payload['appearance']['accent']);
        self::assertSame('right', $payload['position']);
        self::assertSame('/ochrana-osobnich-udaju/', $payload['policy_path']);
        self::assertSame('cookie-consent', $payload['storage_key']);
        self::assertSame('card', $payload['style']);
        self::assertSame('', $payload['image_url']);
    }

    public function testDraftIsPreviewedAndPublicChangesOnlyAfterApplyingEntry(): void
    {
        $entry = new ContentStagingEntry('cookie.settings', 'setting', 'cookie.title', 'set', ['key' => 'cookie.title', 'value' => 'Draft title', 'type' => 'string', 'module' => 'cookie'], 'Cookie');
        [$settings, $context] = $this->settings([new SettingRecord('cookie.title', 'Live title', 'string', 'cookie')], [$entry]);
        $config = new CookieConfig($settings);
        self::assertSame('Live title', $config->payload()['title']);
        $context->useStaging();
        self::assertSame('Draft title', $config->payload()['title']);
        $context->useProduction();
        self::assertSame('Live title', $config->payload()['title']);
        $context->useStaging();
        $adapter = new CookieSettingsPublishAdapter($settings);
        $adapter->apply($entry);
        self::assertSame('Draft title', $settings->getLive('cookie.title'));
        self::assertSame('cookie-consent', $config->storageKey());
    }

    public function testLegacyImportIsIdempotentAndPreservesExistingCookieSettings(): void
    {
        [$settings] = $this->settings([
            new SettingRecord('webplus.cookies_title', 'Legacy title', 'string', 'webplus'),
            new SettingRecord('webplus.cookies_enabled', '0', 'boolean', 'webplus'),
            new SettingRecord('cookie.title', 'Own title', 'string', 'cookie'),
        ]);
        $importer = new LegacySettingsImporter($settings);
        self::assertSame(1, $importer->copy());
        self::assertSame('Own title', $settings->getLive('cookie.title'));
        self::assertFalse($settings->getLive('cookie.enabled'));
        self::assertSame('Legacy title', $settings->getLive('webplus.cookies_title'));
        self::assertSame(0, $importer->copy());
    }

    public function testTranslationCatalogsAndSettingsLabelsMatch(): void
    {
        $cs = require dirname(__DIR__) . '/Resources/translations/messages.cs.php';
        $en = require dirname(__DIR__) . '/Resources/translations/messages.en.php';
        self::assertSame(array_keys($cs), array_keys($en));
        foreach (array_keys(CookieConfig::definitions()) as $key) {
            self::assertArrayHasKey($key, $cs);
            self::assertArrayHasKey($key, $en);
        }
    }

    /**
     * @param list<SettingRecord> $records
     * @param list<ContentStagingEntry> $entries
     * @return array{Settings, ContentReadContext}
     */
    private function settings(array $records, array $entries = []): array
    {
        $byKey = [];
        foreach ($records as $record) {
            $byKey[$record->keyName()] = $record;
        }
        $em = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findAll')->willReturn($records);
        $em->method('getRepository')->willReturn($repository);
        $em->method('find')->willReturnCallback(static fn (string $class, string $key): ?SettingRecord => $byKey[$key] ?? null);
        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn($entries);
        $builder = $this->createMock(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'setParameter'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        $builder->method('getQuery')->willReturn($query);
        $em->method('createQueryBuilder')->willReturn($builder);
        $registry = new PublishableRegistry();
        $context = new ContentReadContext();
        $settings = new Settings($em, new Repository([]), $context, $registry, new StagingWriter(new StagingStore($em), $registry));
        foreach (CookieConfig::definitions() as $key => $definition) {
            $settings->define($key, $definition['type'], $key, $definition['default'], 'cookie');
        }
        $registry->register('cookie.settings', 'Cookie', new CookieSettingsPublishAdapter($settings), 'cookie', 55);

        return [$settings, $context];
    }
}
