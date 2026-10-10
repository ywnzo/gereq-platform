<?php

declare(strict_types=1);

namespace Gereq\Currency\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Event\EventBus;
use Gereq\Currency\Domain\Currency;
use Gereq\Currency\Domain\ExchangeRate;

final class SyncExchangeRates
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RateProviderInterface $provider,
        private readonly EventBus $events,
    ) {
    }

    /**
     * @return array{
     *   provider: string,
     *   label: string,
     *   created: int,
     *   updated: int,
     *   skipped: int,
     *   valid_at: ?string,
     *   pairs: list<array{base: string, quote: string, rate: string, action: string}>
     * }
     */
    public function sync(?\DateTimeImmutable $asOf = null): array
    {
        /** @var list<Currency> $currencies */
        $currencies = $this->em->getRepository(Currency::class)->findBy(['isActive' => true]);
        $knownCodes = [];
        foreach ($currencies as $currency) {
            $knownCodes[$currency->code()] = true;
        }
        $codes = array_keys($knownCodes);

        $quotes = $this->provider->fetch($codes, $asOf);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $pairs = [];
        $validAt = null;
        $existingByPairDay = $this->loadExistingByPairDay($quotes);

        foreach ($quotes as $quote) {
            if (!isset($knownCodes[$quote->baseCode], $knownCodes[$quote->quoteCode])) {
                $skipped++;
                continue;
            }

            $validAt ??= $quote->validAt;
            $dayKey = $this->pairDayKey($quote->baseCode, $quote->quoteCode, $quote->validAt);
            $existing = $existingByPairDay[$dayKey] ?? null;
            if ($existing instanceof ExchangeRate) {
                $sameRate = abs((float) $existing->rate() - (float) $quote->rate) < 0.000000000001;
                if ($sameRate && $existing->provider() === $quote->provider) {
                    $skipped++;
                    $pairs[] = [
                        'base' => $quote->baseCode,
                        'quote' => $quote->quoteCode,
                        'rate' => $quote->rate,
                        'action' => 'unchanged',
                    ];
                    continue;
                }

                $existing->setRate($quote->rate);
                $existing->setProvider($quote->provider);
                $existing->setValidAt($quote->validAt);
                $updated++;
                $action = 'updated';
                $rate = $existing;
            } else {
                $rate = new ExchangeRate(
                    $quote->baseCode,
                    $quote->quoteCode,
                    $quote->rate,
                    $quote->validAt,
                    $quote->provider,
                );
                $this->em->persist($rate);
                $existingByPairDay[$dayKey] = $rate;
                $created++;
                $action = 'created';
            }

            $payload = [
                'id' => $rate->id(),
                'base_code' => $rate->baseCode(),
                'quote_code' => $rate->quoteCode(),
                'rate' => $rate->rate(),
                'provider' => $rate->provider(),
                'valid_at' => $rate->validAt()->format(\DateTimeInterface::ATOM),
            ];
            $this->events->emit(
                $action === 'created' ? 'exchange_rate.created' : 'exchange_rate.updated',
                ['type' => 'exchange_rate', 'id' => $rate->id()],
                $payload,
            );
            $pairs[] = [
                'base' => $quote->baseCode,
                'quote' => $quote->quoteCode,
                'rate' => $quote->rate,
                'action' => $action,
            ];
        }

        if ($created > 0 || $updated > 0) {
            $this->em->flush();
        }

        return [
            'provider' => $this->provider->id(),
            'label' => $this->provider->label(),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'valid_at' => $validAt?->format(\DateTimeInterface::ATOM),
            'pairs' => $pairs,
        ];
    }

    /**
     * Prefetch same-day rates for all quote pairs in one query (avoids N+1 in sync loop).
     *
     * @param list<RateQuote> $quotes
     * @return array<string, ExchangeRate>
     */
    private function loadExistingByPairDay(array $quotes): array
    {
        if ($quotes === []) {
            return [];
        }

        $provider = $quotes[0]->provider;
        $minDay = $quotes[0]->validAt->setTime(0, 0);
        $maxDay = $minDay;
        foreach ($quotes as $quote) {
            $day = $quote->validAt->setTime(0, 0);
            if ($day < $minDay) {
                $minDay = $day;
            }
            if ($day > $maxDay) {
                $maxDay = $day;
            }
        }

        /** @var list<ExchangeRate> $rows */
        $rows = $this->em->getRepository(ExchangeRate::class)->createQueryBuilder('r')
            ->where('r.provider = :provider')
            ->andWhere('r.validAt >= :from')
            ->andWhere('r.validAt < :to')
            ->setParameter('provider', $provider)
            ->setParameter('from', $minDay)
            ->setParameter('to', $maxDay->modify('+1 day'))
            ->orderBy('r.validAt', 'DESC')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $key = $this->pairDayKey($row->baseCode(), $row->quoteCode(), $row->validAt());
            // Ordered by validAt DESC — keep the latest row per pair/day.
            if (!isset($map[$key])) {
                $map[$key] = $row;
            }
        }

        return $map;
    }

    private function pairDayKey(string $base, string $quote, \DateTimeImmutable $validAt): string
    {
        return strtoupper($base) . '|' . strtoupper($quote) . '|' . $validAt->format('Y-m-d');
    }
}
