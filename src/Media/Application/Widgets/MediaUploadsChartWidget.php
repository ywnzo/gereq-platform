<?php

declare(strict_types=1);

namespace Gereq\Media\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\ChartSeries;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Gereq\Media\Domain\MediaAsset;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Files uploaded in the last N days. */
final class MediaUploadsChartWidget extends TwigDashboardWidget
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
        return 'media.uploads.chart';
    }

    public function title(): string
    {
        return $this->translator->trans('media.widget.uploads.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_PANEL;
    }

    public function permission(): ?string
    {
        return 'media.view';
    }

    public function order(): int
    {
        return 30;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/chart_panel.html.twig';
    }

    protected function data(): array
    {
        $days = ChartSeries::dayLabels(self::DAYS);
        $since = new \DateTimeImmutable($days[0] . ' 00:00:00');

        /** @var list<MediaAsset> $assets */
        $assets = $this->em->getRepository(MediaAsset::class)->createQueryBuilder('m')
            ->where('m.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();

        $counts = ChartSeries::countsPerDay(
            $days,
            array_map(static fn (MediaAsset $a): \DateTimeImmutable => $a->createdAt(), $assets),
        );
        $bytes = 0;
        foreach ($assets as $asset) {
            $bytes += $asset->size();
        }
        $total = array_sum($counts);

        return [
            'title' => $this->translator->trans('media.widget.uploads.title'),
            'subtitle' => $this->translator->trans('media.widget.uploads.subtitle', ['%days%' => (string) self::DAYS]),
            'href' => $this->router->url('admin.media.index'),
            'link_label' => $this->translator->trans('nav.media'),
            'wide' => true,
            'empty' => $total === 0,
            'empty_message' => $this->translator->trans('empty.generic'),
            'stats' => [
                ['label' => $this->translator->trans('media.widget.uploads.count'), 'value' => (string) $total],
                ['label' => $this->translator->trans('common.size'), 'value' => MediaStatsWidget::formatBytes($bytes)],
            ],
            'config' => [
                'type' => 'bar',
                'labels' => ChartSeries::shortLabels($days),
                'datasets' => [[
                    'label' => $this->translator->trans('media.widget.uploads.title'),
                    'data' => $counts,
                    'kind' => 'bar',
                    'color' => '#a78bfa',
                ]],
            ],
        ];
    }
}
