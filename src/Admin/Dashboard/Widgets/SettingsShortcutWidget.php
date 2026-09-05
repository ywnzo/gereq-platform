<?php

declare(strict_types=1);

namespace Gereq\Admin\Dashboard\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SettingsShortcutWidget extends TwigDashboardWidget
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
        return 'core.settings.shortcut';
    }

    public function title(): string
    {
        return $this->translator->trans('nav.settings');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_SHORTCUT;
    }

    public function permission(): ?string
    {
        return 'settings.manage';
    }

    public function order(): int
    {
        return 90;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/shortcut.html.twig';
    }

    protected function data(): array
    {
        return [
            'href' => $this->router->url('admin.settings.index'),
            'icon' => 'settings',
            'label' => $this->translator->trans('nav.settings'),
            'hint' => $this->translator->trans('admin.dashboard.hint.settings'),
        ];
    }
}
