<?php

declare(strict_types=1);

namespace Modules\Analytics\Application;

/**
 * Admin report range: either an explicit `from`/`to` pair or one of the presets
 * (`1d`, `7d`, `30d`, `90d`, `365d`). Ranges are inclusive on both ends.
 */
final readonly class ReportRange
{
    /** Presets accepted in the `range` query parameter. */
    public const PRESETS = ['1d', '7d', '30d', '90d', '365d'];

    /** Upper bound so a hand-crafted range cannot scan years of raw rows. */
    public const MAX_DAYS = 400;

    public function __construct(
        public string $from,
        public string $to,
        public int $days,
        public string $preset,
    ) {
    }

    public static function resolve(
        ?string $range = null,
        ?string $from = null,
        ?string $to = null,
        int $defaultDays = 30,
    ): self {
        $fromDate = self::parse($from);
        $toDate = self::parse($to);

        if ($fromDate !== null && $toDate !== null && $fromDate <= $toDate) {
            $days = min(self::MAX_DAYS, (int) $fromDate->diff($toDate)->days + 1);

            return new self($fromDate->format('Y-m-d'), $toDate->format('Y-m-d'), $days, $days . 'd');
        }

        $preset = is_string($range) ? mb_strtolower(trim($range)) : '';
        $days = self::daysFromRequest($range) ?? max(1, min(self::MAX_DAYS, $defaultDays));

        $end = new \DateTimeImmutable('today');
        $start = $end->modify('-' . ($days - 1) . ' days');

        return new self($start->format('Y-m-d'), $end->format('Y-m-d'), $days, $preset !== '' ? $preset : $days . 'd');
    }

    /**
     * `range` may be a preset (`7d`) or any `<n>d` value, so the toolbar filters round-trip.
     */
    private static function daysFromRequest(?string $range): ?int
    {
        if ($range === null || preg_match('/\A(\d{1,3})d?\z/', mb_strtolower(trim($range)), $matches) !== 1) {
            return null;
        }

        return max(1, min(self::MAX_DAYS, (int) $matches[1]));
    }

    /**
     * Render data for the report templates. `key` is the value the filter / tab links echo back
     * (`analytics.range.*` presets and the `range_days()` macro both read it).
     *
     * @return array{key: string, from: string, to: string, days: int, preset: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->preset,
            'from' => $this->from,
            'to' => $this->to,
            'days' => $this->days,
            'preset' => $this->preset,
        ];
    }

    private static function parse(?string $day): ?\DateTimeImmutable
    {
        $day = trim((string) $day);
        if ($day === '' || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $day) !== 1) {
            return null;
        }

        try {
            return new \DateTimeImmutable($day . ' 00:00:00');
        } catch (\Exception) {
            return null;
        }
    }
}
