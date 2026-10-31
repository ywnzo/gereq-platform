<?php

declare(strict_types=1);

namespace Modules\Analytics\Application;

use Gereq\Admin\Dashboard\ChartSeries;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the chart config arrays consumed by the shared `chart_panel` widget and the
 * `gereqChart` Alpine factory (`data-config`), so admin pages and dashboard widgets
 * render analytics series the same way.
 *
 * Series / dataset labels use the documented `analytics.title.*` keys; where a metric has no
 * dedicated key the machine `key` is kept alongside the label for the template to map.
 */
final class ChartConfig
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Visitors / pageviews / events over the days present in the series.
     *
     * @param list<array{day: string, visitors: int, pageviews: int, events: int, sessions: int}> $series
     * @return array<string, mixed>
     */
    public function trend(array $series, string $type = 'line'): array
    {
        $days = array_map(static fn (array $row): string => (string) $row['day'], $series);

        return [
            'type' => $type,
            'labels' => $this->shortLabels($days),
            'datasets' => [
                [
                    'key' => 'visitors',
                    'label' => $this->translator->trans('analytics.kpi.visitors'),
                    'data' => array_map(static fn (array $row): int => (int) $row['visitors'], $series),
                    'kind' => $type,
                    'color' => '#47c2ff',
                ],
                [
                    'key' => 'pageviews',
                    'label' => $this->translator->trans('analytics.kpi.pageviews'),
                    'data' => array_map(static fn (array $row): int => (int) $row['pageviews'], $series),
                    'kind' => $type,
                    'color' => '#34d399',
                ],
                [
                    'key' => 'events',
                    'label' => $this->translator->trans('analytics.kpi.events'),
                    'data' => array_map(static fn (array $row): int => (int) $row['events'], $series),
                    'kind' => $type,
                    'color' => '#f59e0b',
                ],
            ],
        ];
    }

    /**
     * One-metric bar chart for a ranked dimension table.
     *
     * @param list<array<string, mixed>> $rows Rows with at least `value` and the `$metric` key
     * @return array<string, mixed>
     */
    public function ranking(array $rows, string $metric, string $label, string $color = '#47c2ff'): array
    {
        return [
            'type' => 'bar',
            'labels' => array_map(static fn (array $row): string => (string) ($row['value'] ?? ''), $rows),
            'datasets' => [
                [
                    'key' => $metric,
                    'label' => $label,
                    'data' => array_map(static fn (array $row): int => (int) ($row[$metric] ?? 0), $rows),
                    'kind' => 'bar',
                    'color' => $color,
                ],
            ],
        ];
    }

    /**
     * @param list<string> $days
     * @return list<string>
     */
    private function shortLabels(array $days): array
    {
        $days = array_values(array_filter($days, static fn (string $day): bool => $day !== ''));
        if ($days === []) {
            return [];
        }

        return ChartSeries::shortLabels($days);
    }
}
