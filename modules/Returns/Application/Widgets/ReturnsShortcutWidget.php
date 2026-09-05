<?php

declare(strict_types=1);

namespace Modules\Returns\Application\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ReturnsShortcutWidget extends TwigDashboardWidget
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
        return 'returns.shortcut';
    }

    public function title(): string
    {
        return $this->translator->trans('nav.returns');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_SHORTCUT;
    }

    public function permission(): ?string
    {
        return 'returns.view';
    }

    public function order(): int
    {
        return 33;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/shortcut.html.twig';
    }

    protected function data(): array
    {
        return [
            'href' => $this->router->url('admin.returns.index'),
            'icon' => 'undo',
            'label' => $this->translator->trans('nav.returns'),
            'hint' => $this->translator->trans('admin.dashboard.hint.returns'),
        ];
    }
}
