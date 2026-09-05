<?php

declare(strict_types=1);

namespace Modules\Currency\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Currency\Domain\Currency;
use Modules\Currency\Domain\ExchangeRate;

final class CurrencyConverter
{
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

        $inverse = $this->latestRate($to, $from);
        if ($inverse instanceof ExchangeRate) {
            $rate = (float) $inverse->rate();
            if ($rate <= 0) {
                return null;
            }

            return [1 / $rate, $inverse->provider() . ':inverse', $inverse->validAt()];
        }

        // Cross přes CZK (ČNB ukládá X/CZK)
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
        $qb = $this->em->getRepository(ExchangeRate::class)->createQueryBuilder('r')
            ->where('r.baseCode = :base')
            ->andWhere('r.quoteCode = :quote')
            ->setParameter('base', $base)
            ->setParameter('quote', $quote)
            ->orderBy('r.validAt', 'DESC')
            ->addOrderBy('r.createdAt', 'DESC')
            ->setMaxResults(1);

        $row = $qb->getQuery()->getOneOrNullResult();

        return $row instanceof ExchangeRate ? $row : null;
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
