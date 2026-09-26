<?php

declare(strict_types=1);

namespace Gereq\Testing;

/**
 * Options forwarded to the PHPUnit process.
 */
final class TestRunOptions
{
    /**
     * @param list<string> $extraArgs Extra argv tokens after the path list (e.g. --filter).
     */
    public function __construct(
        public readonly ?string $filter = null,
        public readonly bool $stopOnFailure = false,
        public readonly bool $testdox = false,
        public readonly array $extraArgs = [],
    ) {
    }
}
