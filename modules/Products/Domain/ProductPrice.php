<?php

declare(strict_types=1);

namespace Modules\Products\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'product_prices')]
class ProductPrice
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'product_id', length: 36)]
    private string $productId;

    #[ORM\Column(name: 'currency_code', length: 3)]
    private string $currencyCode;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 4)]
    private string $amount;

    #[ORM\Column(name: 'valid_from', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validFrom = null;

    #[ORM\Column(name: 'valid_to', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validTo = null;

    public function __construct(string $productId, string $currencyCode, string $amount)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->productId = $productId;
        $this->currencyCode = strtoupper($currencyCode);
        $this->amount = $amount;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function productId(): string
    {
        return $this->productId;
    }

    public function currencyCode(): string
    {
        return $this->currencyCode;
    }

    public function amount(): string
    {
        return $this->amount;
    }

    public function validFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function validTo(): ?\DateTimeImmutable
    {
        return $this->validTo;
    }

    public function setAmount(string $amount): void
    {
        $this->amount = $amount;
    }

    public function setValidFrom(?\DateTimeImmutable $d): void
    {
        $this->validFrom = $d;
    }

    public function setValidTo(?\DateTimeImmutable $d): void
    {
        $this->validTo = $d;
    }

    public function amountFormatted(): string
    {
        return number_format((float) $this->amount, 2, ',', ' ') . ' ' . $this->currencyCode;
    }
}
