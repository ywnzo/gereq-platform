<?php

declare(strict_types=1);

namespace Gereq\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Minimal 5-field cron matcher (minute hour day-of-month month day-of-week).
 * Supports "*", lists, ranges and step values (star-slash-N). Enough for Schedule helpers
 * without a package.
 */
final class CronExpression
{
    private readonly string $expression;

    /** @var list<string> */
    private readonly array $parts;

    public function __construct(string $expression)
    {
        $expression = trim(preg_replace('/\s+/', ' ', $expression) ?? $expression);
        $parts = explode(' ', $expression);
        if (count($parts) !== 5) {
            throw new InvalidArgumentException('Cron expression must have 5 fields: ' . $expression);
        }

        $this->expression = $expression;
        $this->parts = $parts;
    }

    public function expression(): string
    {
        return $this->expression;
    }

    public function isDue(DateTimeImmutable $at): bool
    {
        return $this->fieldMatches($this->parts[0], (int) $at->format('i'), 0, 59)
            && $this->fieldMatches($this->parts[1], (int) $at->format('G'), 0, 23)
            && $this->fieldMatches($this->parts[2], (int) $at->format('j'), 1, 31)
            && $this->fieldMatches($this->parts[3], (int) $at->format('n'), 1, 12)
            && $this->fieldMatches($this->parts[4], (int) $at->format('w'), 0, 6);
    }

    public function nextRun(DateTimeImmutable $after, DateTimeZone $tz): ?DateTimeImmutable
    {
        $cursor = $after->setTimezone($tz)->modify('+1 minute');
        $cursor = $cursor->setTime((int) $cursor->format('G'), (int) $cursor->format('i'));

        // Cap search at ~1 year of minutes.
        for ($i = 0; $i < 366 * 24 * 60; $i++) {
            if ($this->isDue($cursor)) {
                return $cursor;
            }
            $cursor = $cursor->modify('+1 minute');
        }

        return null;
    }

    private function fieldMatches(string $field, int $value, int $min, int $max): bool
    {
        foreach (explode(',', $field) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $step = 1;
            if (str_contains($segment, '/')) {
                [$segment, $stepRaw] = explode('/', $segment, 2);
                $step = max(1, (int) $stepRaw);
            }

            if ($segment === '*') {
                if (($value - $min) % $step === 0) {
                    return true;
                }
                continue;
            }

            if (str_contains($segment, '-')) {
                [$fromRaw, $toRaw] = explode('-', $segment, 2);
                $from = max($min, (int) $fromRaw);
                $to = min($max, (int) $toRaw);
                if ($value >= $from && $value <= $to && ($value - $from) % $step === 0) {
                    return true;
                }
                continue;
            }

            $exact = (int) $segment;
            if ($value === $exact) {
                return true;
            }
            if ($step > 1 && $value >= $exact && ($value - $exact) % $step === 0 && $value <= $max) {
                return true;
            }
        }

        return false;
    }
}
