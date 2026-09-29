<?php

declare(strict_types=1);

namespace Gereq\Currency\Application;

/**
 * Kurz: kolik jednotek $quoteCode za 1 jednotku $baseCode.
 */
final class RateQuote
{
    public function __construct(
        public readonly string $baseCode,
        public readonly string $quoteCode,
        public readonly string $rate,
        public readonly \DateTimeImmutable $validAt,
        public readonly string $provider,
        public readonly string $sourceLabel = '',
    ) {
    }
}
