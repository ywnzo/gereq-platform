<?php

declare(strict_types=1);

namespace Modules\Currency\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Event\EventBus;
use Modules\Currency\Domain\Currency;
use Modules\Currency\Domain\ExchangeRate;

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
        $codes = array_map(static fn (Currency $c): string => $c->code(), $currencies);

        $quotes = $this->provider->fetch($codes, $asOf);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $pairs = [];
        $validAt = null;

        foreach ($quotes as $quote) {
            if ($this->em->find(Currency::class, $quote->baseCode) === null) {
                $skipped++;
                continue;
            }
            if ($this->em->find(Currency::class, $quote->quoteCode) === null) {
                $skipped++;
                continue;
            }

            $validAt ??= $quote->validAt;
            $existing = $this->findSameDay($quote);
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
                $created++;
                $action = 'created';
            }

            $this->em->flush();
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

    private function findSameDay(RateQuote $quote): ?ExchangeRate
    {
        $dayStart = $quote->validAt->setTime(0, 0);
        $dayEnd = $dayStart->modify('+1 day');

        $qb = $this->em->getRepository(ExchangeRate::class)->createQueryBuilder('r')
            ->where('r.baseCode = :base')
            ->andWhere('r.quoteCode = :quote')
            ->andWhere('r.provider = :provider')
            ->andWhere('r.validAt >= :from')
            ->andWhere('r.validAt < :to')
            ->setParameter('base', $quote->baseCode)
            ->setParameter('quote', $quote->quoteCode)
            ->setParameter('provider', $quote->provider)
            ->setParameter('from', $dayStart)
            ->setParameter('to', $dayEnd)
            ->orderBy('r.validAt', 'DESC')
            ->setMaxResults(1);

        $row = $qb->getQuery()->getOneOrNullResult();

        return $row instanceof ExchangeRate ? $row : null;
    }
}
