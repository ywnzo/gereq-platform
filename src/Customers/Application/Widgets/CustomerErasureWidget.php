<?php

declare(strict_types=1);

namespace Gereq\Customers\Application\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Gereq\Customers\Application\CustomerCounters;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CustomerErasureWidget extends TwigDashboardWidget
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
        return 'customers.erasure';
    }

    public function title(): string
    {
        return $this->translator->trans('customers.widget.erasure');
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
        return 41;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/metric.html.twig';
    }

    protected function data(): array
    {
        $count = $this->counters->erasure();

        return [
            'label' => $this->translator->trans('customers.widget.erasure'),
            'value' => (string) $count,
            'hint' => $this->translator->trans('customers.widget.erasure.hint'),
            'href' => $this->router->url('admin.customers.index') . '?erasure=1',
        ];
    }
}
