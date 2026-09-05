<?php

declare(strict_types=1);

namespace Modules\Customers\Application\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CustomerCreateHeroWidget extends TwigDashboardWidget
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
        return 'customers.create.hero';
    }

    public function title(): string
    {
        return $this->translator->trans('nav.customers.create');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_HERO;
    }

    public function permission(): ?string
    {
        return 'customers.create';
    }

    public function order(): int
    {
        return 30;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/hero_action.html.twig';
    }

    protected function data(): array
    {
        return [
            'href' => $this->router->url('admin.customers.create'),
            'label' => $this->translator->trans('nav.customers.create'),
            'variant' => 'btn--ghost',
        ];
    }
}
