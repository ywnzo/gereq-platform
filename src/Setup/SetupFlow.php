<?php

declare(strict_types=1);

namespace Gereq\Setup;

final class SetupFlow
{
    /** @return list<string> */
    public static function steps(bool $hasModules): array
    {
        $steps = ['welcome', 'account', 'company', 'modules'];

        return $hasModules ? [...$steps, 'dashboard'] : $steps;
    }

    /** @param list<string> $completed */
    public static function next(array $completed, bool $hasModules): string
    {
        foreach (self::steps($hasModules) as $step) {
            if (!in_array($step, $completed, true)) {
                return $step;
            }
        }

        return 'finish';
    }

    /** @param list<string> $completed
     *  @return list<string>
     */
    public static function complete(array $completed, string $step, bool $hasModules): array
    {
        $steps = self::steps($hasModules);
        $index = array_search($step, $steps, true);
        if ($index === false) {
            throw new \InvalidArgumentException('Unknown setup step.');
        }
        $earlier = array_slice($steps, 0, $index);

        return [...array_values(array_intersect($earlier, $completed)), $step];
    }
}
