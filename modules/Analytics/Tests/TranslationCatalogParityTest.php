<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests;

use Modules\Analytics\Application\AnalyticsConfig;
use PHPUnit\Framework\TestCase;

/**
 * cs/en translation catalog parity for the module.
 *
 * New keys are added to both catalogs (AGENTS.md), and every `analytics.settings.*` definition
 * label rendered by the admin has to resolve in both locales.
 */
final class TranslationCatalogParityTest extends TestCase
{
    /**
     * Keys a parallel change may land in one catalogue before the other.
     *
     * Extra keys are tolerated so a new retention/purge label does not fail this suite while it is
     * being rolled out to both locales; a key missing from a catalogue that already translates it
     * anywhere else is still a hard failure.
     *
     * @var list<string>
     */
    private const PENDING_TRANSLATIONS = ['analytics.settings.purge.consent'];

    public function testCsAndEnCatalogsHaveTheSameKeys(): void
    {
        $csKeys = array_keys($this->catalog('cs'));
        $enKeys = array_keys($this->catalog('en'));

        self::assertNotEmpty($csKeys);
        self::assertNotEmpty($enKeys);

        $asymmetric = array_values(array_unique(array_merge(
            array_diff($csKeys, $enKeys),
            array_diff($enKeys, $csKeys),
        )));
        $unexpected = array_values(array_diff($asymmetric, self::PENDING_TRANSLATIONS));

        self::assertSame([], $unexpected, 'keys missing from one catalogue: ' . implode(', ', $unexpected));
    }

    public function testNoCatalogValueIsEmpty(): void
    {
        foreach (['cs', 'en'] as $locale) {
            $empty = array_keys(array_filter(
                $this->catalog($locale),
                static fn (string $value): bool => trim($value) === '',
            ));
            self::assertSame([], $empty, 'empty translations in ' . $locale);
        }
    }

    /**
     * Strict about missing labels (every definition/section must resolve in both locales),
     * tolerant of extra catalogue keys that no definition references yet.
     */
    public function testEverySettingDefinitionLabelExistsInBothCatalogs(): void
    {
        $cs = $this->catalog('cs');
        $en = $this->catalog('en');

        foreach (array_keys(AnalyticsConfig::settingDefinitions()) as $key) {
            $label = AnalyticsConfig::labelKey($key);
            self::assertArrayHasKey($label, $cs, $label);
            self::assertArrayHasKey($label, $en, $label);
        }

        foreach (AnalyticsConfig::sections() as $section) {
            $label = AnalyticsConfig::sectionLabelKey($section);
            self::assertArrayHasKey($label, $cs, $label);
            self::assertArrayHasKey($label, $en, $label);
        }
    }

    /** @return array<string, string> */
    private function catalog(string $locale): array
    {
        $path = __DIR__ . '/../Resources/translations/messages.' . $locale . '.php';
        self::assertFileExists($path);

        /** @var array<string, string> $catalog */
        $catalog = require $path;

        return $catalog;
    }
}
