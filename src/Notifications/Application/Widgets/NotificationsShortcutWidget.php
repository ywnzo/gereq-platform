<?php

declare(strict_types=1);

namespace Gereq\Notifications\Application\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class NotificationsShortcutWidget extends TwigDashboardWidget
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
        return 'notifications.shortcut';
    }

    public function title(): string
    {
        return $this->translator->trans('nav.notifications');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_SHORTCUT;
    }

    public function permission(): ?string
    {
        return 'notifications.view';
    }

    public function order(): int
    {
        return 60;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/shortcut.html.twig';
    }

    protected function data(): array
    {
        return [
            'href' => $this->router->url('admin.notifications.index'),
            'icon' => 'bell',
            'label' => $this->translator->trans('nav.notifications'),
            'hint' => $this->translator->trans('admin.dashboard.hint.notifications'),
        ];
    }
}
