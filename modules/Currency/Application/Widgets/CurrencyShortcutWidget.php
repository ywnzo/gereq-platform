<?php

declare(strict_types=1);

namespace Modules\Currency\Application\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CurrencyShortcutWidget extends TwigDashboardWidget
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
        return 'currency.shortcut';
    }

    public function title(): string
    {
        return $this->translator->trans('nav.currencies');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_SHORTCUT;
    }

    public function permission(): ?string
    {
        return 'currency.view';
    }

    public function order(): int
    {
        return 45;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/shortcut.html.twig';
    }

    protected function data(): array
    {
        return [
            'href' => $this->router->url('admin.currencies.index'),
            'icon' => 'coins',
            'label' => $this->translator->trans('nav.currencies'),
            'hint' => $this->translator->trans('admin.dashboard.hint.currency'),
        ];
    }
}
