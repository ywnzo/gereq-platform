<?php

declare(strict_types=1);

namespace Gereq\Admin\Dashboard;

/**
 * Admin dashboard widget. Modules register implementations in boot()
 * via $context->dashboard()->addWidget(...) — convention: Application/Widgets/.
 */
interface DashboardWidget
{
    public const TYPE_METRIC = 'metric';
    public const TYPE_PANEL = 'panel';
    public const TYPE_HERO = 'hero';
    public const TYPE_SHORTCUT = 'shortcut';

    public function id(): string;

    public function title(): string;

    /** metric | panel | hero | shortcut */
    public function type(): string;

    public function render(): string;

    public function permission(): ?string;

    public function order(): int;
}
