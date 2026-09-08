<?php

declare(strict_types=1);

namespace Gereq\Admin\Ui;

use Twig\Environment;

/**
 * Kernel UI renderer — Athena Panel-style helpers for admin HTML fragments.
 *
 * Athena mapping:
 *   $render->contentDiv(true, id, class)  →  ui()->container(cols|class, $body)
 *   $render->contentRow(..., fieldHtml)   →  ui()->cell($html, span)
 *   rowBox two/three/four                 →  form-grid / form-grid--cols-3|4
 *
 * Use from PHP: ui()->grid(3, $html), ui()->container(3, $html)
 * Use from Twig: {{ ui().grid(3, body) }} or {% embed 'ui/components/grid.html.twig' with { cols: 3 } %}
 * Or macros: {% import 'ui/macros.html.twig' as ui %}
 */
final class Ui
{
    public function __construct(
        private readonly Environment $twig,
        private readonly Icons $icons,
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
     * Layout container (Athena `contentDiv`).
     *
     * - int 1–4 → form grid columns (`ui()->grid`)
     * - string   → plain `<div class="…">` wrapper
     *
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
     * Form field grid with 1–4 columns.
     *
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

    /**
     * Span wrapper inside a grid (Athena `rowBox` width helpers).
     *
     * @param int|string $span 1, 2, 3, or 'full'
     */
    public function cell(string $body, int|string $span = 1, ?string $class = null): string
    {
        return $this->component('cell', [
            'body' => $body,
            'span' => $span,
            'class' => $class,
        ]);
    }

    /**
     * Side drawer panel (Athena `drawerPanel`).
     *
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

    /** Button / link that opens a named drawer. */
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
}
