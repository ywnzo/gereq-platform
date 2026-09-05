<?php

declare(strict_types=1);

namespace Modules\Shop\Application\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ShopShortcutWidget extends TwigDashboardWidget
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
        return 'shop.shortcut';
    }

    public function title(): string
    {
        return $this->translator->trans('nav.shop');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_SHORTCUT;
    }

    public function permission(): ?string
    {
        return 'shop.view';
    }

    public function order(): int
    {
        return 28;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/shortcut.html.twig';
    }

    protected function data(): array
    {
        return [
            'href' => $this->router->url('admin.shop.carts.index'),
            'icon' => 'bag',
            'label' => $this->translator->trans('nav.shop'),
            'hint' => $this->translator->trans('admin.dashboard.hint.shop'),
        ];
    }
}
