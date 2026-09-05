<?php

declare(strict_types=1);

namespace Modules\CarService\Application\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CarServiceShortcutWidget extends TwigDashboardWidget
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
        return 'carservice.shortcut';
    }

    public function title(): string
    {
        return $this->translator->trans('nav.carservice');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_SHORTCUT;
    }

    public function permission(): ?string
    {
        return 'carservice.view';
    }

    public function order(): int
    {
        return 36;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/shortcut.html.twig';
    }

    protected function data(): array
    {
        return [
            'href' => $this->router->url('admin.carservice.index'),
            'icon' => 'build',
            'label' => $this->translator->trans('nav.carservice'),
            'hint' => $this->translator->trans('admin.dashboard.hint.carservice'),
        ];
    }
}
