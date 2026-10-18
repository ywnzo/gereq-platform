<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Admin\Ui;

use Gereq\Admin\Ui\Icons;
use Gereq\Admin\Ui\Table\DataTableRenderer;
use Gereq\Admin\Ui\Ui;
use Gereq\Testing\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class SelectComponentTest extends TestCase
{
    public function testSingleSelectRendersTriggerAndOptions(): void
    {
        $html = $this->makeUi()->select([
            'name' => 'status',
            'label' => 'Status',
            'value' => 'active',
            'options' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'off', 'label' => 'Off'],
            ],
            'form' => null,
        ]);

        self::assertStringContainsString('g-select__trigger', $html);
        self::assertStringContainsString('data-value="active"', $html);
        self::assertStringContainsString('is-selected', $html);
        self::assertStringContainsString('g-select__check', $html);
        self::assertStringContainsString('name="status"', $html);
    }

    public function testSearchableSelectRendersSearchField(): void
    {
        $html = $this->makeUi()->select([
            'name' => 'city',
            'label' => 'City',
            'searchable' => true,
            'options' => [
                ['value' => 'prg', 'label' => 'Praha'],
            ],
            'form' => null,
        ]);

        self::assertStringContainsString('g-select--search', $html);
        self::assertStringContainsString('g-select__search-input', $html);
        self::assertStringContainsString('data-searchable="1"', $html);
    }

    public function testMultiSelectRendersChipsAndArrayInputs(): void
    {
        $html = $this->makeUi()->select([
            'name' => 'tags',
            'label' => 'Tags',
            'multiple' => true,
            'searchable' => true,
            'clearable' => true,
            'value' => ['a', 'b'],
            'options' => [
                ['value' => 'a', 'label' => 'Alpha'],
                ['value' => 'b', 'label' => 'Beta'],
                ['value' => 'c', 'label' => 'Gamma'],
            ],
            'form' => null,
        ]);

        self::assertStringContainsString('g-select--multi', $html);
        self::assertStringContainsString('data-multiple="1"', $html);
        self::assertStringContainsString('g-select__chip', $html);
        self::assertStringContainsString('data-select-clear', $html);
        // Two selected values → two named inputs (validate input has no name).
        self::assertSame(2, preg_match_all('/name="tags\[]"/', $html) - substr_count($html, 'data-input-name="tags[]"'));
    }

    public function testClearableSingleSelectRendersClearControl(): void
    {
        $html = $this->makeUi()->select([
            'name' => 'status',
            'value' => 'on',
            'clearable' => true,
            'options' => [
                ['value' => 'on', 'label' => 'On'],
            ],
            'form' => null,
        ]);

        self::assertStringContainsString('g-select--clearable', $html);
        self::assertStringContainsString('data-select-clear', $html);
    }

    public function testNativeBackedSelectUsesTopLayerMenu(): void
    {
        $html = $this->makeUi()->select(['native' => true]);

        self::assertStringContainsString('data-select-native', $html);
        self::assertStringContainsString('popover="manual"', $html);
        self::assertStringContainsString('x-effect="syncNativeRoot()"', $html);
    }

    private function makeUi(): Ui
    {
        $views = dirname(__DIR__, 4) . '/resources/views';
        $loader = new FilesystemLoader($views);
        $twig = new Environment($loader, ['cache' => false, 'strict_variables' => false]);
        $twig->addFunction(new \Twig\TwigFunction('icon', static fn (string $name): string => '<span class="g-icon">' . $name . '</span>', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('old', static fn (string $k, mixed $d = null): mixed => $d));
        $twig->addFunction(new \Twig\TwigFunction('error', static fn (): ?string => null));
        $twig->addFunction(new \Twig\TwigFunction('field_required', static fn (): bool => false));
        $twig->addFilter(new \Twig\TwigFilter('trans', static fn (string $id): string => $id));

        return new Ui($twig, new Icons(), new DataTableRenderer($twig));
    }
}
