<?php

declare(strict_types=1);

namespace Gereq\Testing;

/**
 * Kind of runnable test target discovered by the testing engine.
 */
enum TestTargetKind: string
{
    case All = 'all';
    case Core = 'core';
    case File = 'file';
    case Modules = 'modules';
    case Module = 'module';
    case Integrations = 'integrations';
    case Integration = 'integration';
}
