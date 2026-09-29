<?php

declare(strict_types=1);

namespace Gereq\Admin\Ui;

use Gereq\Admin\Ui\Table\DataTableRenderer;
use Gereq\Admin\Ui\Table\TableColumn;
use Twig\Environment;

/**
 * Kernel UI renderer — Athena Panel-style helpers for admin HTML fragments.
 *
 * Use from PHP: ui()->table($columns, $rows, $opts), ui()->input([...])
 * Use from Twig: {{ ui().badge('TOP', 'signal') }} / {{ ui_render('data_table', …) }}
 */
final class Ui
{
    public function __construct(
        private readonly Environment $twig,
        private readonly Icons $icons,
        private readonly DataTableRenderer $tables,
    ) {
    }

    /**
     * Render a named UI component template (`resources/views/ui/components/{name}.html.twig`).
     *
     * @param array<string, mixed> $data
     */
    public function component(string $name, array $data = []): string
    {
        $name = trim($name, '/');
        if ($name === '' || str_contains($name, '..')) {
            throw new \InvalidArgumentException('Invalid UI component name.');
        }

        return $this->twig->render('ui/components/' . $name . '.html.twig', $data);
    }

    /**
     * Render any Twig view under resources/views (e.g. ui/input.html.twig).
     *
     * @param array<string, mixed> $data
     */
    public function partial(string $template, array $data = []): string
    {
        $template = trim($template);
        if ($template === '' || str_contains($template, '..')) {
            throw new \InvalidArgumentException('Invalid UI partial name.');
        }
        if (!str_ends_with($template, '.html.twig')) {
            $template .= '.html.twig';
        }

        return $this->twig->render($template, $data);
    }

    /**
     * @param array{id?: string|null, class?: string|null, compact?: bool} $options
     */
    public function container(int|string $colsOrClass, string $body = '', array $options = []): string
    {
        if (is_int($colsOrClass)) {
            return $this->grid($colsOrClass, $body, $options);
        }

        $class = trim($colsOrClass . ' ' . (string) ($options['class'] ?? ''));

        return $this->component('container', [
            'body' => $body,
            'id' => $options['id'] ?? null,
            'class' => $class !== '' ? $class : null,
        ]);
    }

    /**
     * @param array{id?: string|null, class?: string|null, compact?: bool} $options
     */
    public function grid(int $cols, string $body = '', array $options = []): string
    {
        $cols = max(1, min(4, $cols));

        return $this->component('grid', [
            'cols' => $cols,
            'body' => $body,
            'id' => $options['id'] ?? null,
            'class' => $options['class'] ?? null,
            'compact' => (bool) ($options['compact'] ?? false),
        ]);
    }

    /** @param int|string $span 1, 2, 3, or 'full' */
    public function cell(string $body, int|string $span = 1, ?string $class = null): string
    {
        return $this->component('cell', [
            'body' => $body,
            'span' => $span,
            'class' => $class,
        ]);
    }

    /**
     * @param array{
     *     footer?: string|null,
     *     wide?: bool,
     *     open?: bool,
     *     class?: string|null,
     *     host?: bool
     * } $options
     */
    public function drawer(
        string $id,
        string $body,
        ?string $title = null,
        array $options = [],
    ): string {
        return $this->component('drawer', [
            'id' => $id,
            'title' => $title,
            'body' => $body,
            'footer' => $options['footer'] ?? null,
            'wide' => (bool) ($options['wide'] ?? false),
            'open' => (bool) ($options['open'] ?? false),
            'class' => $options['class'] ?? null,
            'host' => (bool) ($options['host'] ?? false),
        ]);
    }

    public function drawerTrigger(
        string $drawerId,
        string $label,
        string $variant = 'ghost',
        ?string $icon = null,
        ?string $class = null,
    ): string {
        return $this->component('drawer_trigger', [
            'drawer_id' => $drawerId,
            'label' => $label,
            'variant' => $variant,
            'icon' => $icon,
            'class' => $class,
        ]);
    }

    public function badge(string $label, string $tone = 'muted', ?string $class = null): string
    {
        return $this->component('badge', [
            'label' => $label,
            'tone' => $tone,
            'class' => $class,
        ]);
    }

    public function emptyState(string $title, ?string $hint = null, ?string $actionHtml = null): string
    {
        return $this->component('empty', [
            'title' => $title,
            'hint' => $hint,
            'action' => $actionHtml,
        ]);
    }

    /**
     * @param array{meta?: string|null, actions?: string|null} $options
     */
    public function pageHeader(string $title, array $options = []): string
    {
        return $this->component('page_header', [
            'title' => $title,
            'meta' => $options['meta'] ?? null,
            'actions' => $options['actions'] ?? null,
        ]);
    }

    public function icon(string $name, string $class = ''): string
    {
        return $this->icons->svg($name, $class);
    }

    /* —— Form controls (wrap existing ui/*.html.twig partials) —— */

    /** @param array<string, mixed> $data */
    public function input(array $data): string
    {
        return $this->partial('ui/input', $data);
    }

    /** @param array<string, mixed> $data */
    public function select(array $data): string
    {
        return $this->partial('ui/select', $data);
    }

    /** @param array<string, mixed> $data */
    public function textarea(array $data): string
    {
        return $this->partial('ui/textarea', $data);
    }

    /** @param array<string, mixed> $data */
    public function checkbox(array $data): string
    {
        return $this->partial('ui/checkbox', $data);
    }

    /** @param array<string, mixed> $data */
    public function radio(array $data): string
    {
        return $this->partial('ui/radio', $data);
    }

    /** @param array<string, mixed> $data */
    public function switch(array $data): string
    {
        return $this->partial('ui/switch', $data);
    }

    /** @param array<string, mixed> $data */
    public function file(array $data): string
    {
        return $this->partial('ui/file', $data);
    }

    /** @param array<string, mixed> $data */
    public function editor(array $data): string
    {
        return $this->partial('ui/editor', $data);
    }

    /* —— Tables —— */

    /**
     * @param array{
     *     sortable?: bool,
     *     cell?: 'text'|'badge'|'html'|'user'|'mute'|'num',
     *     class?: string|null,
     *     numeric?: bool,
     *     render?: callable(mixed): string,
     *     tone?: callable(mixed): string,
     *     meta?: array<string, mixed>
     * } $options
     */
    public function column(string $key, string $label, array $options = []): TableColumn
    {
        return TableColumn::make($key, $label, $options);
    }

    /**
     * @param list<TableColumn> $columns
     * @param iterable<mixed> $rows
     * @param array<string, mixed> $options
     */
    public function table(array $columns, iterable $rows, array $options = []): string
    {
        return $this->tables->render($columns, $rows, $options);
    }

    /**
     * @param array<string, mixed> $data label, column, sort, dir, query?, search?, class?
     */
    public function sortTh(array $data): string
    {
        return $this->partial('ui/sort_th', $data);
    }

    /**
     * @param array<string, mixed> $data total, page, pages, perPage, search?, sort?, dir?, list_query?
     */
    public function tableFooter(array $data): string
    {
        return $this->partial('ui/table_footer', $data);
    }
}
