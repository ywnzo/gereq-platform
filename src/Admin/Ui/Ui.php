<?php

declare(strict_types=1);

namespace Gereq\Admin\Ui;

use Twig\Environment;

/**
 * Kernel UI renderer — Athena Panel-style helpers for admin HTML fragments.
 *
 * Use from PHP: ui()->drawer(...), ui()->badge(...)
 * Use from Twig: {{ ui_render('badge', {label: 'TOP', tone: 'signal'}) }}
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
