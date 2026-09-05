<?php

declare(strict_types=1);

namespace Gereq\Admin\Dashboard;

/** Pomocník pro denní časové řady v dashboard grafech. */
final class ChartSeries
{
    /**
     * @return list<string> Y-m-d labels (oldest → newest)
     */
    public static function dayLabels(int $days): array
    {
        $days = max(1, $days);
        $labels = [];
        $start = new \DateTimeImmutable('today');
        for ($i = $days - 1; $i >= 0; $i--) {
            $labels[] = $start->modify("-{$i} days")->format('Y-m-d');
        }

        return $labels;
    }

    /**
     * @param list<string> $dayLabels
     * @param list<\DateTimeInterface> $timestamps
     * @return list<int>
     */
    public static function countsPerDay(array $dayLabels, array $timestamps): array
    {
        $bucket = array_fill_keys($dayLabels, 0);
        foreach ($timestamps as $ts) {
            $key = $ts->format('Y-m-d');
            if (isset($bucket[$key])) {
                $bucket[$key]++;
            }
        }

        return array_values($bucket);
    }

    /**
     * @param list<string> $dayLabels
     * @param list<array{0: \DateTimeInterface, 1: float|int|string}> $points
     * @return list<float>
     */
    public static function sumsPerDay(array $dayLabels, array $points): array
    {
        $bucket = array_fill_keys($dayLabels, 0.0);
        foreach ($points as [$ts, $amount]) {
            $key = $ts->format('Y-m-d');
            if (isset($bucket[$key])) {
                $bucket[$key] += (float) $amount;
            }
        }

        return array_map(static fn (float $v): float => round($v, 2), array_values($bucket));
    }

    /**
     * Short day labels for charts (d. m.).
     *
     * @param list<string> $dayLabels
     * @return list<string>
     */
    public static function shortLabels(array $dayLabels): array
    {
        return array_map(static function (string $day): string {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $day);

            return $dt instanceof \DateTimeImmutable ? $dt->format('j. n.') : $day;
        }, $dayLabels);
    }
}
