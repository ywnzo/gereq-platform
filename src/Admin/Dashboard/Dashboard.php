<?php

declare(strict_types=1);

namespace Gereq\Admin\Dashboard;

use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\User\Entity\User;
use Symfony\Contracts\Translation\TranslatorInterface;

final class Dashboard
{
    public const LAYOUT_SETTING = 'admin.dashboard.layout';

    public const WIDTH_FULL = 'full';
    public const WIDTH_TWO_THIRDS = 'two_thirds';
    public const WIDTH_HALF = 'half';
    public const WIDTH_THIRD = 'third';

    public const SHORTCUTS_ID = 'core.shortcuts';

    /** @var list<DashboardWidget> */
    private array $widgets = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function addWidget(DashboardWidget $widget): void
    {
        $this->widgets[] = $widget;
    }

    /**
     * @return list<array{id: string, title: string, type: string, order: int, enabled: bool, width: string, permission: ?string}>
     */
    public function catalog(?User $user = null): array
    {
        $layout = $this->layoutIndex();
        $items = [];

        foreach ($this->registered($user) as $widget) {
            $id = $widget->id();
            $pref = $layout[$id] ?? null;
            $items[] = [
                'id' => $id,
                'title' => $widget->title(),
                'type' => $widget->type(),
                'order' => (int) ($pref['order'] ?? $widget->order()),
                'enabled' => $pref === null ? true : (bool) ($pref['enabled'] ?? true),
                'width' => $this->normalizeWidth($pref['width'] ?? $this->defaultWidthFor($widget)),
                'permission' => $widget->permission(),
            ];
        }

        $hasShortcutWidgets = false;
        foreach ($this->registered($user) as $widget) {
            if ($widget->type() === DashboardWidget::TYPE_SHORTCUT) {
                $hasShortcutWidgets = true;
                break;
            }
        }
        if ($hasShortcutWidgets) {
            $pref = $layout[self::SHORTCUTS_ID] ?? null;
            $items[] = [
                'id' => self::SHORTCUTS_ID,
                'title' => $this->translator->trans('admin.dashboard.shortcuts'),
                'type' => DashboardWidget::TYPE_PANEL,
                'order' => (int) ($pref['order'] ?? 900),
                'enabled' => $pref === null ? true : (bool) ($pref['enabled'] ?? true),
                'width' => $this->normalizeWidth($pref['width'] ?? self::WIDTH_THIRD),
                'permission' => null,
            ];
        }

        usort($items, static fn (array $a, array $b): int => $a['order'] <=> $b['order'] ?: strcmp($a['id'], $b['id']));

        return $items;
    }

    /**
     * @return list<DashboardWidget>
     */
    public function widgets(?User $user = null, ?string $type = null): array
    {
        $widgets = $this->registered($user);
        $layout = $this->layoutIndex();

        if ($layout !== []) {
            $widgets = array_values(array_filter(
                $widgets,
                static function (DashboardWidget $widget) use ($layout): bool {
                    $pref = $layout[$widget->id()] ?? null;
                    if ($pref === null) {
                        return true;
                    }

                    return (bool) ($pref['enabled'] ?? true);
                }
            ));

            usort($widgets, static function (DashboardWidget $a, DashboardWidget $b) use ($layout): int {
                $oa = (int) ($layout[$a->id()]['order'] ?? $a->order());
                $ob = (int) ($layout[$b->id()]['order'] ?? $b->order());

                return $oa <=> $ob ?: strcmp($a->id(), $b->id());
            });
        } else {
            usort($widgets, static fn (DashboardWidget $a, DashboardWidget $b): int => $a->order() <=> $b->order());
        }

        if ($type !== null) {
            $widgets = array_values(array_filter(
                $widgets,
                static fn (DashboardWidget $widget): bool => $widget->type() === $type
            ));
        }

        return $widgets;
    }

    /**
     * Panel widgets + optional shortcuts shell, sorted for the grid.
     *
     * @return list<array{id: string, width: string, html: string}>
     */
    public function gridItems(?User $user = null): array
    {
        $layout = $this->layoutIndex();
        $items = [];

        foreach ($this->widgets($user, DashboardWidget::TYPE_PANEL) as $widget) {
            $items[] = [
                'id' => $widget->id(),
                'width' => $this->resolveWidth($widget->id(), $this->defaultWidthFor($widget)),
                'order' => (int) ($layout[$widget->id()]['order'] ?? $widget->order()),
                'html' => $widget->render(),
            ];
        }

        $shortcuts = $this->widgets($user, DashboardWidget::TYPE_SHORTCUT);
        $shortcutPref = $layout[self::SHORTCUTS_ID] ?? null;
        $shortcutsEnabled = $shortcutPref === null ? true : (bool) ($shortcutPref['enabled'] ?? true);
        if ($shortcuts !== [] && $shortcutsEnabled) {
            $items[] = [
                'id' => self::SHORTCUTS_ID,
                'width' => $this->resolveWidth(self::SHORTCUTS_ID, self::WIDTH_THIRD),
                'order' => (int) ($shortcutPref['order'] ?? 900),
                'html' => '', // rendered in Twig
                'shortcuts' => $shortcuts,
            ];
        }

        usort($items, static fn (array $a, array $b): int => $a['order'] <=> $b['order'] ?: strcmp($a['id'], $b['id']));

        return $items;
    }

    /**
     * @return array{metric: list<DashboardWidget>, panel: list<DashboardWidget>, hero: list<DashboardWidget>, shortcut: list<DashboardWidget>}
     */
    public function grouped(?User $user = null): array
    {
        $grouped = [
            DashboardWidget::TYPE_METRIC => [],
            DashboardWidget::TYPE_PANEL => [],
            DashboardWidget::TYPE_HERO => [],
            DashboardWidget::TYPE_SHORTCUT => [],
        ];

        foreach ($this->widgets($user) as $widget) {
            $type = $widget->type();
            if (!isset($grouped[$type])) {
                $grouped[$type] = [];
            }
            $grouped[$type][] = $widget;
        }

        return $grouped;
    }

    /**
     * @param list<array{id?: string, enabled?: bool|int|string, order?: int|string, width?: string}> $items
     */
    public function saveLayout(array $items): void
    {
        $normalized = [];
        foreach ($items as $item) {
            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $normalized[] = [
                'id' => $id,
                'enabled' => filter_var($item['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'order' => (int) ($item['order'] ?? 0),
                'width' => $this->normalizeWidth($item['width'] ?? self::WIDTH_HALF),
            ];
        }

        usort($normalized, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $this->settings->set(self::LAYOUT_SETTING, $normalized, SettingRecord::TYPE_JSON, 'core');
    }

    public function resolveWidth(string $id, string $default = self::WIDTH_HALF): string
    {
        $pref = $this->layoutIndex()[$id] ?? null;
        if ($pref === null) {
            return $this->normalizeWidth($default);
        }

        return $this->normalizeWidth($pref['width'] ?? $default);
    }

    /**
     * @return list<DashboardWidget>
     */
    private function registered(?User $user): array
    {
        $widgets = $this->widgets;

        if ($user !== null) {
            $widgets = array_values(array_filter(
                $widgets,
                static fn (DashboardWidget $widget): bool => $widget->permission() === null || $user->can($widget->permission())
            ));
        }

        return $widgets;
    }

    private function defaultWidthFor(DashboardWidget $widget): string
    {
        return match ($widget->type()) {
            DashboardWidget::TYPE_PANEL => self::WIDTH_HALF,
            DashboardWidget::TYPE_SHORTCUT => self::WIDTH_THIRD,
            default => self::WIDTH_HALF,
        };
    }

    private function normalizeWidth(mixed $width): string
    {
        $value = is_string($width) ? $width : self::WIDTH_HALF;
        $allowed = [self::WIDTH_FULL, self::WIDTH_TWO_THIRDS, self::WIDTH_HALF, self::WIDTH_THIRD];

        return in_array($value, $allowed, true) ? $value : self::WIDTH_HALF;
    }

    /**
     * @return array<string, array{id: string, enabled: bool, order: int, width: string}>
     */
    private function layoutIndex(): array
    {
        $raw = $this->settings->get(self::LAYOUT_SETTING, []);
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $index = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $index[$id] = [
                'id' => $id,
                'enabled' => (bool) ($item['enabled'] ?? true),
                'order' => (int) ($item['order'] ?? 0),
                'width' => $this->normalizeWidth($item['width'] ?? self::WIDTH_HALF),
            ];
        }

        return $index;
    }
}
