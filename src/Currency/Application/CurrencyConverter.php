<?php

declare(strict_types=1);

namespace Gereq\Currency\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Currency\Domain\Currency;
use Gereq\Currency\Domain\ExchangeRate;

final class CurrencyConverter
{
    /** @var array<string, ExchangeRate|null> */
    private array $latestRateCache = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array{
     *   source: array{amount: int|float|string, currency: string},
     *   target: array{amount: int|float|string, currency: string},
     *   rate: string,
     *   provider: string,
     *   rate_valid_at: string|null
     * }
     */
    public function convert(string|int|float $amount, string $from, string $to): array
    {
        $fromCode = strtoupper(trim($from));
        $toCode = strtoupper(trim($to));
        $sourceCurrency = $this->requireCurrency($fromCode);
        $targetCurrency = $this->requireCurrency($toCode);

        if (!$sourceCurrency->isActive() || !$targetCurrency->isActive()) {
            throw new \RuntimeException('Jedna z měn není aktivní.');
        }

        $numericAmount = $this->normalizeAmount($amount);

        if ($fromCode === $toCode) {
            return [
                'source' => ['amount' => $this->outputAmount($numericAmount, $sourceCurrency->decimalPlaces()), 'currency' => $fromCode],
                'target' => ['amount' => $this->outputAmount($numericAmount, $targetCurrency->decimalPlaces()), 'currency' => $toCode],
                'rate' => $this->formatRate(1.0),
                'provider' => 'identity',
                'rate_valid_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ];
        }

        $found = $this->findRate($fromCode, $toCode);
        if ($found === null) {
            throw new \RuntimeException('Směnný kurz pro daný pár nebyl nalezen. Spusťte synchronizaci ČNB (php gereq currency:rates:sync).');
        }

        [$rateValue, $provider, $validAt] = $found;
        $converted = round($numericAmount * $rateValue, $targetCurrency->decimalPlaces());

        return [
            'source' => ['amount' => $this->outputAmount($numericAmount, $sourceCurrency->decimalPlaces()), 'currency' => $fromCode],
            'target' => ['amount' => $this->outputAmount($converted, $targetCurrency->decimalPlaces()), 'currency' => $toCode],
            'rate' => $this->formatRate($rateValue),
            'provider' => $provider,
            'rate_valid_at' => $validAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array{0: float, 1: string, 2: \DateTimeImmutable}|null */
    private function findRate(string $from, string $to): ?array
    {
        $direct = $this->latestRate($from, $to);
        if ($direct instanceof ExchangeRate) {
            return [(float) $direct->rate(), $direct->provider(), $direct->validAt()];
        }

        // Prefer arithmetic inverse from a warmed/cached swapped pair before another LIMIT 1 query.
        $swappedKey = $to . ':' . $from;
        if (array_key_exists($swappedKey, $this->latestRateCache)) {
            $swapped = $this->latestRateCache[$swappedKey];
            if ($swapped instanceof ExchangeRate) {
                $rate = (float) $swapped->rate();
                if ($rate <= 0) {
                    return null;
                }

                return [1 / $rate, $swapped->provider() . ':inverse', $swapped->validAt()];
            }

            // Swapped pair already known missing — skip inverse fetch.
        } else {
            $inverse = $this->latestRate($to, $from);
            if ($inverse instanceof ExchangeRate) {
                $rate = (float) $inverse->rate();
                if ($rate <= 0) {
                    return null;
                }

                return [1 / $rate, $inverse->provider() . ':inverse', $inverse->validAt()];
            }
        }

        // Cross via CZK (CNB stores X/CZK)
        if ($from !== 'CZK' && $to !== 'CZK') {
            $fromCzk = $this->latestRate($from, 'CZK');
            $toCzk = $this->latestRate($to, 'CZK');
            if ($fromCzk instanceof ExchangeRate && $toCzk instanceof ExchangeRate) {
                $fromRate = (float) $fromCzk->rate();
                $toRate = (float) $toCzk->rate();
                if ($fromRate > 0 && $toRate > 0) {
                    $validAt = $fromCzk->validAt() <= $toCzk->validAt() ? $fromCzk->validAt() : $toCzk->validAt();

                    return [$fromRate / $toRate, 'cnb:cross', $validAt];
                }
            }
        }

        return null;
    }

    private function latestRate(string $base, string $quote): ?ExchangeRate
    {
        $cacheKey = $base . ':' . $quote;
        if (array_key_exists($cacheKey, $this->latestRateCache)) {
            return $this->latestRateCache[$cacheKey];
        }

        $qb = $this->em->getRepository(ExchangeRate::class)->createQueryBuilder('r')
            ->where('r.baseCode = :base')
            ->andWhere('r.quoteCode = :quote')
            ->setParameter('base', $base)
            ->setParameter('quote', $quote)
            ->orderBy('r.validAt', 'DESC')
            ->addOrderBy('r.createdAt', 'DESC')
            ->setMaxResults(1);

        $row = $qb->getQuery()->getOneOrNullResult();

        return $this->latestRateCache[$cacheKey] = $row instanceof ExchangeRate ? $row : null;
    }

    /**
     * Prefetch latest rates for many base→quote pairs (one query).
     *
     * @param list<string> $bases
     */
    public function warmLatestAgainst(string $quote, array $bases): void
    {
        $quote = strtoupper(trim($quote));
        $missing = [];
        foreach ($bases as $base) {
            if (!is_string($base) || $base === '') {
                continue;
            }
            $base = strtoupper(trim($base));
            $key = $base . ':' . $quote;
            if (!array_key_exists($key, $this->latestRateCache)) {
                $missing[$base] = true;
            }
        }
        $basesList = array_keys($missing);
        if ($basesList === []) {
            return;
        }

        try {
            /** @var list<ExchangeRate> $rows */
            $rows = $this->em->getRepository(ExchangeRate::class)->createQueryBuilder('r')
                ->where('r.quoteCode = :quote')
                ->andWhere('r.baseCode IN (:bases)')
                ->setParameter('quote', $quote)
                ->setParameter('bases', $basesList)
                ->orderBy('r.validAt', 'DESC')
                ->addOrderBy('r.createdAt', 'DESC')
                ->getQuery()
                ->getResult();
        } catch (\Throwable) {
            foreach ($basesList as $base) {
                $this->latestRateCache[$base . ':' . $quote] = null;
            }

            return;
        }

        foreach ($rows as $row) {
            if (!$row instanceof ExchangeRate) {
                continue;
            }
            $key = $row->baseCode() . ':' . $row->quoteCode();
            if (!array_key_exists($key, $this->latestRateCache)) {
                $this->latestRateCache[$key] = $row;
            }
        }
        foreach ($basesList as $base) {
            $key = $base . ':' . $quote;
            if (!array_key_exists($key, $this->latestRateCache)) {
                $this->latestRateCache[$key] = null;
            }
            // Mark swapped pair so findRate() does not issue another LIMIT 1 for the inverse.
            $invKey = $quote . ':' . $base;
            if (
                !array_key_exists($invKey, $this->latestRateCache)
                && !($this->latestRateCache[$key] instanceof ExchangeRate)
            ) {
                $this->latestRateCache[$invKey] = null;
            }
        }
    }

    private function requireCurrency(string $code): Currency
    {
        $currency = $this->em->find(Currency::class, $code);
        if (!$currency instanceof Currency) {
            throw new \RuntimeException('Měna ' . $code . ' neexistuje.');
        }

        return $currency;
    }

    private function normalizeAmount(string|int|float $amount): float
    {
        if (is_string($amount)) {
            $normalized = str_replace([' ', ','], ['', '.'], trim($amount));
            if (!is_numeric($normalized)) {
                throw new \InvalidArgumentException('Neplatná částka.');
            }
            $amount = (float) $normalized;
        }

        return (float) $amount;
    }

    private function outputAmount(float $amount, int $decimalPlaces = 0): int|float
    {
        $rounded = round($amount, max(0, $decimalPlaces));
        if ($decimalPlaces <= 0 || abs($rounded - round($rounded)) < 0.0000001) {
            return (int) round($rounded);
        }

        return $rounded;
    }

    private function formatRate(float $rate): string
    {
        return number_format($rate, 6, '.', '');
    }
}
