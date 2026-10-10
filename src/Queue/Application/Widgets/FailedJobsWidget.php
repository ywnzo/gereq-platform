<?php

declare(strict_types=1);

namespace Gereq\Queue\Application\Widgets;

use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Gereq\Queue\Application\QueueHealthChecker;
use Gereq\Queue\Application\QueueHealthStatus;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Most recent failed jobs, so the dashboard answers "what did not run and why"
 * without going to the CLI. Uses the shared list panel.
 */
final class FailedJobsWidget extends TwigDashboardWidget
{
    private const LIMIT = 8;
    private const ERROR_CHARS = 160;

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
        return 'queues.failed_recent';
    }

    public function title(): string
    {
        return $this->translator->trans('queues.widget.recent.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_PANEL;
    }

    public function permission(): ?string
    {
        return 'queues.view';
    }

    public function order(): int
    {
        return 20;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/list_panel.html.twig';
    }

    protected function data(): array
    {
        $items = [];

        foreach ($this->health->recent(self::LIMIT) as $failed) {
            $attempts = $failed->attempts();

            $items[] = [
                'title' => $failed->job() !== ''
                    ? $failed->job()
                    : $this->translator->trans('queues.failed.unreadable'),
                'meta' => $this->firstLine($failed->exception()),
                'icon' => 'alert',
                'href' => $this->router->url('admin.queues.show', ['id' => $failed->id()]),
                'aside' => $failed->failedAt()->format('j. n. H:i'),
                'badge' => [
                    'label' => $this->translator->trans('queues.failed.attempts', ['%count%' => (string) $attempts]),
                    'tone' => $attempts >= QueueHealthStatus::FAIL_AT ? 'danger' : 'warn',
                ],
            ];
        }

        return [
            'title' => $this->translator->trans('queues.widget.recent.title'),
            'subtitle' => $this->translator->trans('queues.widget.recent.subtitle'),
            'href' => $this->router->url('admin.queues.index'),
            'link_label' => $this->translator->trans('queues.action.all'),
            'items' => $items,
            'empty_message' => $this->translator->trans('queues.empty'),
        ];
    }

    private function firstLine(string $exception): string
    {
        $line = trim(explode("\n", $exception)[0] ?? $exception);

        return mb_substr($line, 0, self::ERROR_CHARS);
    }
}
