<?php

declare(strict_types=1);

namespace Modules\Events\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\ChartSeries;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\Events\Domain\CatalogEvent;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** New catalog entries vs. approvals over the last N days. */
final class EventsCreatedChartWidget extends TwigDashboardWidget
{
    private const DAYS = 30;

    public function __construct(
        Environment $twig,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly Router $router,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'events.created.chart';
    }

    public function title(): string
    {
        return $this->translator->trans('events.widget.created.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_PANEL;
    }

    public function permission(): ?string
    {
        return 'events.view';
    }

    public function order(): int
    {
        return 33;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/chart_panel.html.twig';
    }

    protected function data(): array
    {
        $days = ChartSeries::dayLabels(self::DAYS);
        $since = new \DateTimeImmutable($days[0] . ' 00:00:00');

        /** @var list<CatalogEvent> $events */
        $events = $this->em->getRepository(CatalogEvent::class)->createQueryBuilder('e')
            ->where('e.createdAt >= :since OR e.approvedAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();

        $created = ChartSeries::countsPerDay(
            $days,
            array_map(static fn (CatalogEvent $event): \DateTimeImmutable => $event->createdAt(), $events),
        );
        $published = ChartSeries::countsPerDay(
            $days,
            array_values(array_filter(array_map(
                static fn (CatalogEvent $event): ?\DateTimeImmutable => $event->approvedAt(),
                $events,
            ))),
        );

        $totalCreated = array_sum($created);
        $totalPublished = array_sum($published);

        return [
            'title' => $this->translator->trans('events.widget.created.title'),
            'subtitle' => $this->translator->trans('events.widget.created.subtitle', ['%days%' => (string) self::DAYS]),
            'href' => $this->router->url('admin.events.index'),
            'link_label' => $this->translator->trans('events.title'),
            'empty' => $totalCreated === 0 && $totalPublished === 0,
            'empty_message' => $this->translator->trans('empty.generic'),
            'stats' => [
                ['label' => $this->translator->trans('events.widget.created.added'), 'value' => (string) $totalCreated],
                ['label' => $this->translator->trans('events.widget.created.published'), 'value' => (string) $totalPublished],
            ],
            'config' => [
                'type' => 'bar',
                'labels' => ChartSeries::shortLabels($days),
                'datasets' => [
                    [
                        'label' => $this->translator->trans('events.widget.created.added'),
                        'data' => $created,
                        'kind' => 'bar',
                        'color' => '#a78bfa',
                    ],
                    [
                        'label' => $this->translator->trans('events.widget.created.published'),
                        'data' => $published,
                        'kind' => 'bar',
                        'color' => '#34d399',
                    ],
                ],
            ],
        ];
    }
}
