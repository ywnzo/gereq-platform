<?php

declare(strict_types=1);

namespace Modules\ProductDiscounts\Application\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ProductDiscountsShortcutWidget extends TwigDashboardWidget
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
        return 'product_discounts.shortcut';
    }

    public function title(): string
    {
        return $this->translator->trans('nav.product_discounts');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_SHORTCUT;
    }

    public function permission(): ?string
    {
        return 'product_discounts.view';
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
            'href' => $this->router->url('admin.product_discounts.index'),
            'icon' => 'tag',
            'label' => $this->translator->trans('nav.product_discounts'),
            'hint' => $this->translator->trans('admin.dashboard.hint.product_discounts'),
        ];
    }
}
