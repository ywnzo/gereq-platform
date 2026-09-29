<?php

declare(strict_types=1);

namespace Gereq\Currency\Application;

interface RateProviderInterface
{
    public function id(): string;

    public function label(): string;

    /**
     * @param list<string> $neededCodes ISO currency codes the platform has (e.g. EUR, USD)
     * @return list<RateQuote>
     */
    public function fetch(array $neededCodes, ?\DateTimeImmutable $asOf = null): array;
}
