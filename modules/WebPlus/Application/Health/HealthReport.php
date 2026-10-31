<?php

declare(strict_types=1);

namespace Modules\WebPlus\Application\Health;

/** Web health result 0–100. */
final class HealthReport
{
    /**
     * @param list<HealthCheck> $checks
     * @param list<string> $recommendations
     */
    public function __construct(
        public readonly int $score,
        public readonly string $grade,
        public readonly string $frontendUrl,
        public readonly array $checks,
        public readonly array $recommendations,
        public readonly \DateTimeImmutable $checkedAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'grade' => $this->grade,
            'frontend_url' => $this->frontendUrl,
            'checked_at' => $this->checkedAt->format(\DateTimeInterface::ATOM),
            'checks' => array_map(static fn (HealthCheck $c): array => $c->toArray(), $this->checks),
            'recommendations' => $this->recommendations,
            'color' => self::scoreColor($this->score),
        ];
    }

    /** Score CSS color: 100% green → 0% red. */
    public static function scoreColor(int $score): string
    {
        $score = max(0, min(100, $score));
        // Hue: 0 = red, 120 = green (legacy comma syntax — more reliable in admin CSS)
        $hue = (int) round(($score / 100) * 120);

        return sprintf('hsl(%d, 78%%, 45%%)', $hue);
    }

    public static function gradeFor(int $score): string
    {
        return match (true) {
            $score >= 90 => 'excellent',
            $score >= 75 => 'good',
            $score >= 50 => 'fair',
            $score >= 25 => 'poor',
            default => 'critical',
        };
    }
}
