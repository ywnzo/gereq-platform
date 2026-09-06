<?php

declare(strict_types=1);

namespace Modules\Currency\Application;

/**
 * Výsledek CurrencyBuilder::make().
 *
 * - raw         = [symbol, částka] nezaokrouhlená (po převodu kurzem)
 * - rawRounded  = [symbol, částka] zaokrouhlená na decimal places měny
 * - localized   = zobrazení dle konvence měny („1 234,56 Kč“, „$ 12.50“, …)
 */
final class MoneyValue implements \JsonSerializable, \Stringable
{
    /**
     * @param array{0: string, 1: float} $raw
     * @param array{0: string, 1: float|int} $rawRounded
     */
    public function __construct(
        public readonly array $raw,
        public readonly array $rawRounded,
        public readonly string $localized,
        public readonly string $code,
        public readonly string $symbol,
        public readonly ?string $sourceCode = null,
        public readonly ?float $sourceAmount = null,
        public readonly ?string $rate = null,
    ) {
    }

    public function amount(): float|int
    {
        return $this->rawRounded[1];
    }

    public function amountExact(): float
    {
        return $this->raw[1];
    }

    public function isEmpty(): bool
    {
        return $this->localized === '—';
    }

    public function __toString(): string
    {
        return $this->localized;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'raw' => $this->raw,
            'raw_rounded' => $this->rawRounded,
            'localized' => $this->localized,
            'code' => $this->code,
            'symbol' => $this->symbol,
            'source_code' => $this->sourceCode,
            'source_amount' => $this->sourceAmount,
            'rate' => $this->rate,
        ];
    }

    /** @return array{0: string, 1: float} */
    public function toRaw(): array
    {
        return $this->raw;
    }

    /** @return array{0: string, 1: float|int} */
    public function toRawRounded(): array
    {
        return $this->rawRounded;
    }
}
