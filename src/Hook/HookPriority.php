<?php

declare(strict_types=1);

namespace Gereq\Hook;

final class HookPriority
{
    public const EARLY = 10;
    public const NORMAL = 20;
    public const LATE = 50;
    public const LAST = 100;

    private function __construct()
    {
    }
}
