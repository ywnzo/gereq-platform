<?php

declare(strict_types=1);

namespace Gereq\Testing;

/**
 * Outcome of one PHPUnit invocation.
 */
final class TestRunResult
{
    /**
     * @param list<string> $command Full command argv that was executed.
     */
    public function __construct(
        public readonly int $exitCode,
        public readonly array $command,
        public readonly string $targetId,
    ) {
    }

    public function ok(): bool
    {
        return $this->exitCode === 0;
    }
}
