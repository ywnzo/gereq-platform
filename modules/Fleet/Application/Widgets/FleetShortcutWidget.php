<?php

declare(strict_types=1);

namespace Modules\Fleet\Application\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class FleetShortcutWidget extends TwigDashboardWidget
{
    public function __construct(
        Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly Router $router,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'fleet.shortcut';
    }

    public function title(): string
    {
        return $this->translator->trans('nav.fleet');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_SHORTCUT;
    }

    public function permission(): ?string
    {
        return 'fleet.view';
    }

    public function order(): int
    {
        return 35;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/shortcut.html.twig';
    }

    protected function data(): array
    {
        return [
            'href' => $this->router->url('admin.fleet.index'),
            'icon' => 'car',
            'label' => $this->translator->trans('nav.fleet'),
            'hint' => $this->translator->trans('admin.dashboard.hint.fleet'),
        ];
    }
}
