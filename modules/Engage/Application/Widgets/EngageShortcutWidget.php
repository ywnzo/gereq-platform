<?php

declare(strict_types=1);

namespace Modules\Engage\Application\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class EngageShortcutWidget extends TwigDashboardWidget
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
        return 'engage.shortcut';
    }

    public function title(): string
    {
        return $this->translator->trans('engage.widget.shortcut');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_SHORTCUT;
    }

    public function permission(): ?string
    {
        return 'engage.view';
    }

    public function order(): int
    {
        return 48;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/shortcut.html.twig';
    }

    protected function data(): array
    {
        return [
            'url' => $this->router->url('admin.engage.hub'),
            'label' => $this->translator->trans('engage.widget.shortcut'),
        ];
    }
}
