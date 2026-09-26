<?php

declare(strict_types=1);

namespace Gereq\Testing;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * Base test case for platform and module unit tests.
 * Prefer pure domain assertions; avoid booting Application unless needed.
 *
 * Use {@see QueryAssertions} for N+1 / query-budget checks when a
 * {@see \Gereq\Debug\QueryCollector} is available.
 */
abstract class TestCase extends PhpUnitTestCase
{
    use QueryAssertions;
}
