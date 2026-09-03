<?php

declare(strict_types=1);

namespace Gereq\Admin\Dashboard;

use Gereq\User\Entity\User;

final class Dashboard
{
    /** @var list<DashboardWidget> */
    private array $widgets = [];

    public function addWidget(DashboardWidget $widget): void
    {
        $this->widgets[] = $widget;
    }

    /**
     * @return list<DashboardWidget>
     */
    public function widgets(?User $user = null): array
    {
        $widgets = $this->widgets;

        if ($user !== null) {
            $widgets = array_values(array_filter(
                $widgets,
                static fn (DashboardWidget $widget): bool => $widget->permission() === null || $user->can($widget->permission())
            ));
        }

        usort($widgets, static fn (DashboardWidget $a, DashboardWidget $b): int => $a->order() <=> $b->order());

        return $widgets;
    }
}
