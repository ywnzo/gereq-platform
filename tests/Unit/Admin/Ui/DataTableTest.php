<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Admin\Ui;

use Gereq\Admin\Ui\Icons;
use Gereq\Admin\Ui\Table\DataTableRenderer;
use Gereq\Admin\Ui\Table\TableColumn;
use Gereq\Admin\Ui\Ui;
use Gereq\Testing\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class DataTableTest extends TestCase
{
    public function testColumnMakeDefaults(): void
    {
        $column = TableColumn::make('email', 'E-mail', ['sortable' => true]);

        self::assertSame('email', $column->key);
        self::assertSame('E-mail', $column->label);
        self::assertTrue($column->sortable);
        self::assertSame('text', $column->cell);
    }

    public function testColumnValueFromArray(): void
    {
        $column = TableColumn::make('name', 'Name');

        self::assertSame('Ada', $column->value(['name' => 'Ada']));
    }

    public function testTableRendersSortableHeaderAndBadgeCell(): void
    {
        $ui = $this->makeUi();
        $html = $ui->table(
            [
                $ui->column('name', 'Name', ['sortable' => true]),
                $ui->column('status', 'Status', [
                    'cell' => 'badge',
                    'tone' => static fn (array $row): string => (string) ($row['status_tone'] ?? 'muted'),
                ]),
            ],
            [
                ['name' => 'Ada', 'status' => 'Active', 'status_tone' => 'ok'],
            ],
            [
                'sort' => 'name',
                'dir' => 'asc',
                'query' => [],
            ],
        );

        self::assertStringContainsString('table-sort', $html);
        self::assertStringContainsString('Ada', $html);
        self::assertStringContainsString('badge--ok', $html);
        self::assertStringContainsString('Active', $html);
    }

    public function testInputPartialRendersField(): void
    {
        $ui = $this->makeUi();
        $html = $ui->input([
            'name' => 'demo',
            'label' => 'Demo',
            'value' => 'hello',
            'form' => null,
        ]);

        self::assertStringContainsString('name="demo"', $html);
        self::assertStringContainsString('hello', $html);
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
        $twig->addFilter(new \Twig\TwigFilter('url_encode', static function (array $qs): string {
            return http_build_query($qs);
        }));

        $icons = new Icons();
        $renderer = new DataTableRenderer($twig);

        return new Ui($twig, $icons, $renderer);
    }
}
