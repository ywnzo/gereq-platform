<?php

declare(strict_types=1);

namespace Gereq\Setup;

final class SetupFlow
{
    /** @return list<string> */
    public static function steps(bool $hasModules, string $package = ''): array
    {
        if ($package !== '') {
            return self::shopPackage($package)
                ? ['welcome', 'account', 'company', 'shop', 'done']
                : ['welcome', 'account', 'company', 'done'];
        }
        $steps = ['welcome', 'account', 'company', 'modules'];

        return $hasModules ? [...$steps, 'dashboard'] : $steps;
    }

    /** @param list<string> $completed */
    public static function next(array $completed, bool $hasModules, string $package = ''): string
    {
        foreach (self::steps($hasModules, $package) as $step) {
            if (!in_array($step, $completed, true)) {
                return $step;
            }
        }

        return 'finish';
    }

    /** @param list<string> $completed
     *  @return list<string>
     */
    public static function complete(array $completed, string $step, bool $hasModules, string $package = ''): array
    {
        $steps = self::steps($hasModules, $package);
        $index = array_search($step, $steps, true);
        if ($index === false) {
            throw new \InvalidArgumentException('Unknown setup step.');
        }
        $earlier = array_slice($steps, 0, $index);

        return [...array_values(array_intersect($earlier, $completed)), $step];
    }
    public static function shopPackage(string $package): bool
    {
        return in_array($package, ['shop', 'shop-v1', 'ecommerce', 'ecommerce-v1'], true);
    }
}
