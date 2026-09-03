<?php

declare(strict_types=1);

namespace Gereq\Admin\Dashboard;

interface DashboardWidget
{
    public function id(): string;

    public function title(): string;

    public function render(): string;

    public function permission(): ?string;

    public function order(): int;
}
