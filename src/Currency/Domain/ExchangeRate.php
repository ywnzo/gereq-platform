<?php

declare(strict_types=1);

namespace Gereq\Currency\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'exchange_rates')]
#[ORM\Index(name: 'idx_exchange_rates_pair', columns: ['base_code', 'quote_code'])]
#[ORM\Index(name: 'idx_exchange_rates_valid_at', columns: ['valid_at'])]
class ExchangeRate
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'base_code', length: 3)]
    private string $baseCode;

    #[ORM\Column(name: 'quote_code', length: 3)]
    private string $quoteCode;

    #[ORM\Column(type: 'decimal', precision: 24, scale: 12)]
    private string $rate;

    #[ORM\Column(length: 64)]
    private string $provider = 'manual';

    #[ORM\Column(name: 'valid_at')]
    private \DateTimeImmutable $validAt;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $baseCode,
        string $quoteCode,
        string $rate,
        ?\DateTimeImmutable $validAt = null,
        string $provider = 'manual',
    ) {
        $base = strtoupper(trim($baseCode));
        $quote = strtoupper(trim($quoteCode));
        if (!preg_match('/^[A-Z]{3}$/', $base) || !preg_match('/^[A-Z]{3}$/', $quote)) {
            throw new \InvalidArgumentException('Kódy měn musí mít 3 písmena.');
        }
        if ($base === $quote) {
            throw new \InvalidArgumentException('Základní a kotovaná měna musí být různé.');
        }
        if (!is_numeric($rate) || (float) $rate <= 0) {
            throw new \InvalidArgumentException('Kurz musí být kladné číslo.');
        }

        $this->id = Uuid::uuid7()->toString();
        $this->baseCode = $base;
        $this->quoteCode = $quote;
        $this->rate = $this->normalizeRate($rate);
        $this->provider = $provider !== '' ? $provider : 'manual';
        $this->validAt = $validAt ?? new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function baseCode(): string
    {
        return $this->baseCode;
    }

    public function quoteCode(): string
    {
        return $this->quoteCode;
    }

    public function rate(): string
    {
        return $this->rate;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function validAt(): \DateTimeImmutable
    {
        return $this->validAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setRate(string $rate): void
    {
        if (!is_numeric($rate) || (float) $rate <= 0) {
            throw new \InvalidArgumentException('Kurz musí být kladné číslo.');
        }
        $this->rate = $this->normalizeRate($rate);
    }

    public function setProvider(string $provider): void
    {
        $this->provider = $provider !== '' ? $provider : 'manual';
    }

    public function setValidAt(\DateTimeImmutable $validAt): void
    {
        $this->validAt = $validAt;
    }

    private function normalizeRate(string $rate): string
    {
        return number_format((float) $rate, 12, '.', '');
    }
}
