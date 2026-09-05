<?php

declare(strict_types=1);

namespace Modules\Customers\Application\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\Customers\Application\CustomerCounters;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CustomerStatsWidget extends TwigDashboardWidget
{
    public function __construct(
        Environment $twig,
        private readonly CustomerCounters $counters,
        private readonly TranslatorInterface $translator,
        private readonly Router $router,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'customers.stats';
    }

    public function title(): string
    {
        return $this->translator->trans('customers.widget.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_METRIC;
    }

    public function permission(): ?string
    {
        return 'customers.view';
    }

    public function order(): int
    {
        return 40;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/metric.html.twig';
    }

    protected function data(): array
    {
        return [
            'label' => $this->translator->trans('customers.widget.title'),
            'value' => (string) $this->counters->total(),
            'hint' => $this->translator->trans('customers.widget.active', [
                '%count%' => (string) $this->counters->active(),
            ]),
            'href' => $this->router->url('admin.customers.index'),
        ];
    }
}
