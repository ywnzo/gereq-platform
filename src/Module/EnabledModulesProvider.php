<?php

declare(strict_types=1);

namespace Gereq\Module;

/**
 * Supplies enabled module ids (e.g. for conditional frontend assets).
 */
interface EnabledModulesProvider
{
    /** @return list<string> */
    public function enabledIds(): array;
}
