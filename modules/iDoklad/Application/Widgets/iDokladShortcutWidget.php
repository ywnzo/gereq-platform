<?php

declare(strict_types=1);

namespace Modules\iDoklad\Application\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class iDokladShortcutWidget extends TwigDashboardWidget
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
        return 'idoklad.shortcut';
    }

    public function title(): string
    {
        return $this->translator->trans('nav.idoklad');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_SHORTCUT;
    }

    public function permission(): ?string
    {
        return 'idoklad.view';
    }

    public function order(): int
    {
        return 78;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/shortcut.html.twig';
    }

    protected function data(): array
    {
        return [
            'href' => $this->router->url('admin.idoklad.documents'),
            'icon' => 'receipt',
            'label' => $this->translator->trans('nav.idoklad.documents'),
            'hint' => $this->translator->trans('admin.dashboard.hint.idoklad'),
        ];
    }
}
