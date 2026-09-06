<?php

declare(strict_types=1);

namespace Modules\CarService\Application;

/**
 * Kroky průvodce dokončením zakázky (bez auto-generování všech PDF najednou).
 */
final class FinishWizardCatalog
{
    public const STEP_QUOTE = 'quote';
    public const STEP_WORKSHEET = 'worksheet';
    public const STEP_COST_SHEET = 'cost_sheet';
    public const STEP_HANDOVER = 'handover';
    public const STEP_IDOKLAD = 'idoklad';

    /**
     * @return list<string>
     */
    public static function steps(bool $hasParts, bool $includeIdoklad): array
    {
        $steps = [];
        if ($hasParts) {
            $steps[] = self::STEP_QUOTE;
        }
        $steps[] = self::STEP_WORKSHEET;
        $steps[] = self::STEP_COST_SHEET;
        $steps[] = self::STEP_HANDOVER;
        if ($includeIdoklad) {
            $steps[] = self::STEP_IDOKLAD;
        }

        return $steps;
    }

    public static function isValid(string $step, array $steps): bool
    {
        return in_array($step, $steps, true);
    }

    public static function next(string $step, array $steps): ?string
    {
        $i = array_search($step, $steps, true);
        if ($i === false) {
            return null;
        }
        $i = (int) $i;

        return $steps[$i + 1] ?? null;
    }

    public static function labelKey(string $step): string
    {
        return 'carservice.finish.step.' . $step;
    }

    public static function hintKey(string $step): string
    {
        return 'carservice.finish.step.' . $step . '.hint';
    }
}
