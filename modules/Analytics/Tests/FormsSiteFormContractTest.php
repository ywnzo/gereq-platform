<?php

declare(strict_types=1);

namespace Modules\Analytics\Tests;

use Gereq\Admin\Form\FormGenerator;
use Gereq\Admin\Form\Forms;
use Gereq\Admin\Form\GeneratedField;
use Modules\Analytics\Domain\Site;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the sites admin form contract.
 *
 * `Forms::fields()` reads the #[FormGenerator] / #[FormField] attribute metadata only (no
 * container, no database, no `:memory:` artifact), so this pins exactly the snake_case field list
 * and control types the controller and the site form template rely on.
 */
final class FormsSiteFormContractTest extends TestCase
{
    public function testSiteFormExposesExactlyTheSixEditableColumns(): void
    {
        $forms = (new \ReflectionClass(Forms::class))->newInstanceWithoutConstructor();
        $fields = $forms->fields(Site::class, ['form' => 'analytics.site.save']);

        self::assertSame(
            ['name', 'timezone', 'enabled', 'keep_bot_hits', 'respect_dnt', 'retention_override_days'],
            array_map(static fn (GeneratedField $field): string => $field->name, $fields),
        );
        self::assertSame(
            ['text', 'text', 'switch', 'switch', 'switch', 'number'],
            array_map(static fn (GeneratedField $field): string => $field->type, $fields),
        );
        self::assertSame(
            [
                'analytics.sites.field.name',
                'analytics.sites.field.timezone',
                'analytics.sites.field.enabled',
                'analytics.sites.field.keep_bot_hits',
                'analytics.sites.field.respect_dnt',
                'analytics.sites.field.retention_override_days',
            ],
            array_map(static fn (GeneratedField $field): string => $field->label, $fields),
        );
    }

    public function testOnlyTheNameFieldIsRequiredAndAdminOnlyColumnsAreExcluded(): void
    {
        $forms = (new \ReflectionClass(Forms::class))->newInstanceWithoutConstructor();
        $fields = $forms->fields(Site::class, ['form' => 'analytics.site.save']);

        $required = array_values(array_map(
            static fn (GeneratedField $field): string => $field->name,
            array_filter($fields, static fn (GeneratedField $field): bool => $field->required),
        ));
        self::assertSame(['name'], $required);

        $names = array_map(static fn (GeneratedField $field): string => $field->name, $fields);
        foreach (['id', 'key', 'secret', 'created_at', 'updated_at', 'domains', 'excluded_paths', 'excluded_ips'] as $adminOnly) {
            self::assertNotContains($adminOnly, $names, $adminOnly . ' must not be an editable form field');
        }
    }

    public function testSiteEntityDeclaresTheExpectedFormGeneratorBinding(): void
    {
        $attributes = (new \ReflectionClass(Site::class))->getAttributes(FormGenerator::class);
        self::assertCount(1, $attributes);

        $generator = $attributes[0]->newInstance();
        self::assertSame('analytics.site.save', $generator->form);
        self::assertContains('secret', $generator->exclude);
        self::assertContains('key', $generator->exclude);
    }
}
