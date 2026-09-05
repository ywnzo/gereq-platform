<?php

declare(strict_types=1);

namespace Modules\Currency\Application;

interface RateProviderInterface
{
    public function id(): string;

    public function label(): string;

    /**
     * @param list<string> $neededCodes ISO kódy měn, které platforma má (např. EUR, USD)
     * @return list<RateQuote>
     */
    public function fetch(array $neededCodes, ?\DateTimeImmutable $asOf = null): array;
}
