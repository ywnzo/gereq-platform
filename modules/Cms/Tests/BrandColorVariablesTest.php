<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Gereq\Brand\Branding;
use Gereq\Config\Repository;
use Gereq\Publish\Adapter\BrandingPublishAdapter;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\Entity\ContentStagingEntry;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\StagingStore;
use Gereq\Publish\StagingWriter;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\Tests\Support\PassthroughTranslator;
use Modules\Cms\Application\BrandColorVariables;
use Modules\Cms\Application\CmsProvider;
use Modules\Cms\Domain\Page;
use Modules\Cms\Http\PageController;
use PHPUnit\Framework\TestCase;

final class BrandColorVariablesTest extends TestCase
{
    public function testEditorAndPublicRenderingUseTheirOwnPaletteAndKeepLegacyTokens(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__) . '/Domain'], true);
        $config->enableNativeLazyObjects(true);
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new SchemaTool($em))->createSchema([
            $em->getClassMetadata(SettingRecord::class),
            $em->getClassMetadata(ContentStagingEntry::class),
        ]);
        $production = $this->settings($em, false);
        $production->setLive('brand.color_ink', '#123456');
        $production->setLive('brand.color_cream', '#fedcba');
        $production->setLive('brand.palette', $this->palette('#123456'), SettingRecord::TYPE_JSON, 'core');
        $staging = $this->settings($em, true);
        $staging->set('brand.palette', $this->palette('#abcdef'), SettingRecord::TYPE_JSON, 'core');
        $em->clear();

        $page = new Page('example', 'Example');
        $controllerReflection = new \ReflectionClass(PageController::class);
        $controller = $controllerReflection->newInstanceWithoutConstructor();
        $editorSettings = $this->settings($em, true);
        $controllerReflection->getProperty('settings')->setValue($controller, $editorSettings);
        $controllerReflection->getProperty('translator')->setValue($controller, new PassthroughTranslator());
        $editor = $controllerReflection->getMethod('builderVariables')->invoke($controller, $page);
        $colorPaths = array_column(array_values(array_filter($editor['catalog'], static fn (array $item): bool => $item['kind'] === 'color')), 'path');
        self::assertSame([
            'brand.primary', 'brand.secondary', 'brand.accent',
            'brand.light_background', 'brand.light_surface', 'brand.light_header',
            'brand.dark_background', 'brand.dark_surface', 'brand.dark_header',
        ], $colorPaths);
        foreach ($this->palette('#abcdef') as $key => $value) {
            if ($key !== 'storefront_mode') {
                self::assertSame($value, $editor['values']['brand.' . $key]);
            }
        }
        self::assertSame('#ffffff', $editor['values']['brand.text']);
        $brandingReflection = new \ReflectionClass(Branding::class);
        $cssVariables = $brandingReflection->getMethod('publicCssDefaults')->invoke(new Branding($editorSettings), []);
        $cssValues = array_column($cssVariables, 'value', 'name');
        self::assertSame('#abcdef', $cssValues['--color-secondary']);
        self::assertSame('#ffffff', $cssValues['--color-on-surface']);
        self::assertArrayHasKey('--color-on-primary', $cssValues);
        self::assertArrayHasKey('--color-on-secondary', $cssValues);
        self::assertArrayHasKey('--color-on-accent', $cssValues);

        $providerReflection = new \ReflectionClass(CmsProvider::class);
        $provider = $providerReflection->newInstanceWithoutConstructor();
        $providerReflection->getProperty('settings')->setValue($provider, $this->settings($em, false));
        $publicValues = $providerReflection->getMethod('variableMap')->invoke($provider, $page);
        foreach ($this->palette('#123456') as $key => $value) {
            if ($key !== 'storefront_mode') {
                self::assertSame($value, $publicValues['brand.' . $key]);
            }
        }
        self::assertSame('#123456', $publicValues['brand.ink']);
        self::assertSame('#fedcba', $publicValues['brand.cream']);
        $expanded = $providerReflection->getMethod('expandPayloadVariables')->invoke($provider, [
            'label' => '{{brand.secondary}} / {{brand.dark_header}} / {{brand.ink}}',
        ], $publicValues);
        self::assertSame('#123456 / #333333 / #123456', $expanded['label']);
    }

    public function testThemeChangeStagesWithoutOverwritingPaletteAndPublishes(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__) . '/Domain'], true);
        $config->enableNativeLazyObjects(true);
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new SchemaTool($em))->createSchema([
            $em->getClassMetadata(SettingRecord::class),
            $em->getClassMetadata(ContentStagingEntry::class),
        ]);
        $production = $this->settings($em, false);
        $palette = $this->palette('#123456');
        $production->setLive('brand.palette', $palette, SettingRecord::TYPE_JSON, 'core');
        $staging = $this->settings($em, true);
        BrandColorVariables::saveStorefrontMode($staging, 'light');
        self::assertSame('dark', $production->get('brand.palette')['storefront_mode']);
        $expected = $palette;
        $expected['storefront_mode'] = 'light';
        self::assertSame($expected, $staging->get('brand.palette'));
        $entry = $em->getRepository(ContentStagingEntry::class)->findOneBy(['resourceKey' => 'brand.palette']);
        self::assertInstanceOf(ContentStagingEntry::class, $entry);
        (new BrandingPublishAdapter($production))->apply($entry);
        self::assertSame($expected, $production->get('brand.palette'));
    }

    public function testLegacySettingsStillResolveWithoutPalette(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__) . '/Domain'], true);
        $config->enableNativeLazyObjects(true);
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(SettingRecord::class)]);
        $settings = $this->settings($em, false);
        $settings->setLive('brand.color_signal', '#aabbcc');
        $settings->setLive('brand.site_primary', '#123456');
        $settings->setLive('brand.color_ink', '#000');
        $values = BrandColorVariables::resolve($settings);
        self::assertSame('#aabbcc', $values['brand.signal']);
        self::assertSame('#000', $values['brand.ink']);
        self::assertSame('#123456', $values['brand.primary']);
        self::assertSame('#123456', $values['brand.secondary']);
        self::assertSame('#000000', $values['brand.dark_background']);
    }

    private function settings(EntityManager $em, bool $staging): Settings
    {
        $context = new ContentReadContext();
        if ($staging) {
            $context->useStaging();
        }
        $registry = new PublishableRegistry();
        $settings = new Settings($em, new Repository([]), $context, $registry, new StagingWriter(new StagingStore($em), $registry));
        $registry->register('branding', 'Branding', new BrandingPublishAdapter($settings));
        return $settings;
    }

    /** @return array<string, string> */
    private function palette(string $secondary): array
    {
        return [
            'primary' => '#cc0000', 'secondary' => $secondary, 'accent' => '#008800',
            'light_background' => '#ffffff', 'light_surface' => '#eeeeee', 'light_header' => '#dddddd',
            'dark_background' => '#111111', 'dark_surface' => '#222222', 'dark_header' => '#333333',
            'storefront_mode' => 'dark',
        ];
    }
}
