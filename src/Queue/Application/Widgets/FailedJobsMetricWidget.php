<?php

declare(strict_types=1);

namespace Gereq\Queue\Application\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Gereq\Queue\Application\QueueHealthChecker;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Count of failed jobs as a KPI card. */
final class FailedJobsMetricWidget extends TwigDashboardWidget
{
    public function __construct(
        Environment $twig,
        private readonly QueueHealthChecker $health,
        private readonly TranslatorInterface $translator,
        private readonly Router $router,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'queues.failed';
    }

    public function title(): string
    {
        return $this->translator->trans('queues.widget.failed.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_METRIC;
    }

    public function permission(): ?string
    {
        return 'queues.view';
    }

    public function order(): int
    {
        return 12;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/metric.html.twig';
    }

    protected function data(): array
    {
        $status = $this->health->status();

        return [
            'label' => $this->translator->trans('queues.widget.failed.title'),
            'value' => (string) $status->failedCount,
            'hint' => $status->failedCount > 0
                ? $this->translator->trans('queues.widget.failed.hint_active')
                : $this->translator->trans('queues.widget.failed.hint_ok'),
            'href' => $this->router->url('admin.queues.index'),
        ];
    }
}
