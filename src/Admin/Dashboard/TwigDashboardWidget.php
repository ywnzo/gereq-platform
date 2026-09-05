<?php

declare(strict_types=1);

namespace Gereq\Admin\Dashboard;

use Twig\Environment;

abstract class TwigDashboardWidget implements DashboardWidget
{
    public function __construct(
        protected readonly Environment $twig,
    ) {
    }

    abstract protected function template(): string;

    /** @return array<string, mixed> */
    abstract protected function data(): array;

    public function render(): string
    {
        return $this->twig->render($this->template(), $this->data());
    }

    public function type(): string
    {
        return self::TYPE_METRIC;
    }

    public function permission(): ?string
    {
        return null;
    }
}
