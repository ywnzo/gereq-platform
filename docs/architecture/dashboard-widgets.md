# Dashboard widgets

The admin dashboard (`/admin`, `Gereq\Admin\Http\DashboardController`, template `resources/views/admin/dashboard.html.twig`) is assembled from widgets that core and modules register on `Gereq\Admin\Dashboard\Dashboard` during `boot()`. A widget is any `DashboardWidget` implementation; most extend `TwigDashboardWidget` and reuse one of the shared templates in `resources/views/admin/dashboard/widgets/`. The layout (which widgets are enabled, their order and width) is persisted per installation in the `admin.dashboard.layout` setting and rendered as a masonry grid.

## `Dashboard` service

`src/Admin/Dashboard/Dashboard.php`:

| Member | Purpose |
| --- | --- |
| `addWidget(DashboardWidget)` | register (call from `boot()`) |
| `catalog(user)` | every visible widget with effective `order`, `enabled`, `width`, `permission` — used by the layout editor |
| `widgets(user, type = null)` | enabled widgets sorted by layout order (falls back to `order()`), optionally filtered by type |
| `gridItems(user)` | rendered `panel` widgets (`{id, width, order, html}`) plus a synthetic `core.shortcuts` cell (`SHORTCUTS_ID`) containing all `shortcut` widgets, default width `third`, order 900 |
| `grouped(user)` | `{metric: [], panel: [], hero: [], shortcut: []}` |
| `saveLayout(items)` | normalises `[{id, enabled, order, width}]` and stores it as JSON setting `admin.dashboard.layout` (module `core`) |
| `resolveWidth(id, default)` | width from layout or default |
| constants | `LAYOUT_SETTING = 'admin.dashboard.layout'`, `WIDTH_FULL|TWO_THIRDS|HALF|THIRD` = `full|two_thirds|half|third`, `SHORTCUTS_ID = 'core.shortcuts'` |

Permission filtering happens in `registered(user)`: a widget is dropped when `permission()` is non-null and `!$user->can(permission)`. Everything downstream (catalog, grid, grouped) only sees widgets the current user may view.

## `DashboardWidget` interface

`src/Admin/Dashboard/DashboardWidget.php`:

```php
interface DashboardWidget
{
    public const TYPE_METRIC = 'metric';
    public const TYPE_PANEL = 'panel';
    public const TYPE_HERO = 'hero';
    public const TYPE_SHORTCUT = 'shortcut';

    public function id(): string;          // unique, e.g. 'viewings.upcoming'
    public function title(): string;       // shown in the layout editor
    public function type(): string;        // one of the TYPE_* constants
    public function render(): string;      // HTML
    public function permission(): ?string; // null = everyone
    public function order(): int;          // default sort key
}
```

| Type | Where it renders | Default width | Typical template |
| --- | --- | --- | --- |
| `hero` | top hero strip (`dash-hero__actions`) | — | `hero_action`, `hero_search` |
| `metric` | KPI row (`section.metrics`) | — | `metric` |
| `panel` | masonry grid | `half` | `list_panel`, `chart_panel`, `score_panel` |
| `shortcut` | grouped inside the `core.shortcuts` grid cell | cell: `third` | `shortcut` |

`TwigDashboardWidget` (`src/Admin/Dashboard/TwigDashboardWidget.php`) takes a Twig `Environment` and implements `render()` as `twig->render(template(), data())`; subclasses implement `template()` and `data()` and override `type()` (defaults to `metric`) and `permission()` (defaults to `null`) as needed.

## Shared templates

All in `resources/views/admin/dashboard/widgets/`. Variables are the keys returned by `data()`.

### `metric.html.twig`

| Variable | Meaning |
| --- | --- |
| `label`, `value` | KPI label and value |
| `hint` | optional secondary text |
| `href` | optional; card becomes a link |

### `list_panel.html.twig`

| Variable | Meaning |
| --- | --- |
| `title`, `subtitle` | header |
| `href`, `link_label` | optional "open" button (default `common.open`) |
| `items` | `list<{title, href?, icon?, meta?, badge?: {label, tone?: ok|warn|danger|muted|info|signal}, aside?, aside_hint?}>` |
| `stats` | optional `list<{label, value}>` summary under the list |
| `empty_message` | shown when `items` is empty (default `empty.generic`) |

### `chart_panel.html.twig`

Renders a `<canvas>` driven by the `gereqChart` Alpine component (`resources/js/ui/chart.ts`, Chart.js).

| Variable | Meaning |
| --- | --- |
| `title`, `subtitle`, `href`, `link_label` | header |
| `empty`, `empty_message` | show empty state instead of the chart |
| `config` | JSON-encoded into `data-config`: `{type: 'line'|'bar'|'doughnut', labels: string[], datasets: [{label, data: number[], kind?: 'line'|'bar'|'doughnut', color?: string, colors?: string[], fill?: bool}]}` |
| `stats` | optional `list<{label, value}>` under the chart |

### `score_panel.html.twig`

| Variable | Meaning |
| --- | --- |
| `title`, `subtitle`, `href`, `link_label` | header |
| `score` (int), `color` (CSS colour), `grade` (label) | ring/score |
| `checks` | `list<{label, value, tone: ok|warn|danger}>` |
| `recommendations` | `list<string>` |

### `shortcut.html.twig`

`icon` (default `dot`), `label`, `hint`, `href`.

### `hero_action.html.twig`

`href`, `label`, `icon`, `variant` (button class, default `btn--ghost`).

### `hero_search.html.twig`

`label` — a button that opens the command palette (`@click="openPalette"`).

## `ChartSeries` helper

`Gereq\Admin\Dashboard\ChartSeries` (`src/Admin/Dashboard/ChartSeries.php`) builds day-bucketed series for `chart_panel`:

| Method | Returns |
| --- | --- |
| `dayLabels(int $days)` | `Y-m-d` labels, oldest → newest |
| `countsPerDay(array $dayLabels, array $timestamps)` | `list<int>` counts of `DateTimeInterface`s per label |
| `sumsPerDay(array $dayLabels, array $points)` | `list<float>` sums of `[DateTimeInterface, number]` pairs, rounded to 2 decimals |
| `shortLabels(array $dayLabels)` | display labels (`j. n.`) |

## Example: a module widget

From `modules/Viewings/Application/Widgets/UpcomingViewingsWidget.php`:

```php
final class UpcomingViewingsWidget extends TwigDashboardWidget
{
    public function __construct(Environment $twig, private readonly ViewingsProvider $viewings, /* … */)
    {
        parent::__construct($twig);
    }

    public function id(): string { return 'viewings.upcoming'; }
    public function title(): string { return $this->translator->trans('viewings.widget.upcoming.title'); }
    public function type(): string { return DashboardWidget::TYPE_PANEL; }
    public function permission(): ?string { return 'viewings.view'; }
    public function order(): int { return 16; }

    protected function template(): string { return 'admin/dashboard/widgets/list_panel.html.twig'; }

    protected function data(): array
    {
        $items = [];
        foreach ($this->viewings->dashboardViewings(self::LIMIT) as $viewing) {
            $items[] = [
                'title' => $viewing->contactName(),
                'meta' => $listingTitle ?? $viewing->number(),
                'icon' => $viewing->status() === 'requested' ? 'inbox' : 'calendar',
                'href' => $this->router->url('admin.viewings.show', ['id' => $viewing->id()]),
                'aside' => $aside, 'aside_hint' => $asideHint,
                'badge' => ['label' => …, 'tone' => 'ok'|'info'|'warn'],
            ];
        }
        return ['title' => …, 'href' => $this->router->url('admin.viewings.index'), 'items' => $items, /* stats… */];
    }
}
```

Registration in `modules/Viewings/ViewingsModule.php::boot()` — widgets receive services through `$context->get()`:

```php
$context->dashboard()->addWidget(new ViewingsShortcutWidget(
    $context->get(Environment::class), $context->get(TranslatorInterface::class), $context->get(Router::class),
));
$context->dashboard()->addWidget(new UpcomingViewingsWidget(
    $context->get(Environment::class), $context->get(ViewingsProvider::class),
    $context->get(ListingsProvider::class), $context->get(TranslatorInterface::class), $context->get(Router::class),
));
foreach ([ViewingStatsWidget::class, ViewingsTrendChartWidget::class] as $widgetClass) {
    $context->dashboard()->addWidget(new $widgetClass(/* … */));
}
```

`ViewingsTrendChartWidget` shows the `chart_panel` config shape (`type: 'bar'`, `labels: ChartSeries::shortLabels($days)`, datasets with `kind`/`color`).

Register widgets in `boot()`, not `register()`, so that providers from other modules (`ListingsProvider`) are available.

## Layout persistence

- Setting `admin.dashboard.layout` (defined in `CoreRegistrar::settings()` as `TYPE_JSON`, default `[]`): `[{"id": "viewings.upcoming", "enabled": true, "order": 16, "width": "half"}, …]`. Unknown widths normalise to `half`; ids not present in the layout keep `enabled = true`, their own `order()` and the type default width.
- Edited directly on the dashboard by users with `settings.manage`; changes are saved through the dashboard layout route. Setup continues to use the widget catalog editor.
- The layout is global (not per user) and participates in the settings staging mechanism only if registered as a publishable settings key (it is not, so it applies immediately).

## Grid

`resources/views/admin/dashboard.html.twig` renders hero → metrics → `.dash-grid` with one `.dash-cell.dash-cell--{width}` per `gridItems()` entry.

- CSS (`resources/css/kernel/dashboard.css`): `.dash-grid` is a 12-column grid; `.dash-cell--full` spans 12, `--two_thirds` 8, `--half` 6, `--third` 4. `.dash-grid.is-masonry` uses `grid-auto-flow: dense` with small `grid-auto-rows`.
- JS (`resources/js/ui/dashboardGrid.ts`, `registerDashboardGrid()`): measures each cell's first child and sets `grid-row-end: span N` so cards take only their natural height and shorter cards back-fill gaps; disabled below 960 px (`is-masonry` removed, single column). Re-runs on resize and content changes.

## Checklist for a new widget

1. Create `modules/<Id>/Application/Widgets/<Name>Widget.php` extending `TwigDashboardWidget`; pick a `type()` and a shared template.
2. Return a permission your module registers (`<id>.view`) so the widget is hidden for unauthorised users.
3. Register it in `boot()` with `$context->dashboard()->addWidget(...)`.
4. Add translation keys for `title()` and labels in `Resources/translations/messages.{cs,en}.php`.
5. Bump the module version ([versioning rules](module-system.md#versioning-rules)).
