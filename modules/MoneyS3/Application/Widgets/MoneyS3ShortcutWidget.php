<?php

declare(strict_types=1);

namespace Modules\MoneyS3\Application\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class MoneyS3ShortcutWidget extends TwigDashboardWidget
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
        return 'moneys3.shortcut';
    }

    public function title(): string
    {
        return $this->translator->trans('nav.moneys3');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_SHORTCUT;
    }

    public function permission(): ?string
    {
        return 'moneys3.view';
    }

    public function order(): int
    {
        return 79;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/shortcut.html.twig';
    }

    protected function data(): array
    {
        return [
            'href' => $this->router->url('admin.moneys3.settings'),
            'icon' => 'database',
            'label' => $this->translator->trans('nav.moneys3'),
            'hint' => $this->translator->trans('admin.dashboard.hint.moneys3'),
        ];
    }
}
