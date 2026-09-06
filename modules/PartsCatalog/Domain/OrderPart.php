<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'partscatalog_order_parts')]
#[ORM\Index(name: 'idx_pc_order_parts_order', columns: ['order_id'])]
class OrderPart
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'order_id', length: 36)]
    private string $orderId;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $oem = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $brand = null;

    #[ORM\Column(name: 'name_cs', length: 255)]
    private string $nameCs;

    #[ORM\Column(name: 'name_original', length: 255, nullable: true)]
    private ?string $nameOriginal = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $qty = '1';

    #[ORM\Column(name: 'unit_price', type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $unitPrice = null;

    #[ORM\Column(length: 32)]
    private string $source;

    #[ORM\Column(name: 'external_id', length: 80, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $orderId,
        string $nameCs,
        string $source = '',
        ?string $oem = null,
        ?string $brand = null,
        ?string $nameOriginal = null,
        string $qty = '1',
        ?string $externalId = null,
        ?string $unitPrice = null,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->orderId = $orderId;
        $this->nameCs = trim($nameCs);
        $this->source = trim($source);
        $this->oem = $oem !== null && $oem !== '' ? trim($oem) : null;
        $this->brand = $brand !== null && $brand !== '' ? trim($brand) : null;
        $this->nameOriginal = $nameOriginal !== null && $nameOriginal !== '' ? trim($nameOriginal) : null;
        $this->qty = $qty !== '' ? $qty : '1';
        $this->externalId = $externalId;
        $this->unitPrice = self::normalizePrice($unitPrice);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function oem(): ?string
    {
        return $this->oem;
    }

    public function brand(): ?string
    {
        return $this->brand;
    }

    public function nameCs(): string
    {
        return $this->nameCs;
    }

    public function nameOriginal(): ?string
    {
        return $this->nameOriginal;
    }

    public function qty(): string
    {
        return $this->qty;
    }

    public function unitPrice(): ?string
    {
        return $this->unitPrice;
    }

    /** Celková cena řádku = qty × unit_price. */
    public function lineTotal(): ?string
    {
        if ($this->unitPrice === null) {
            return null;
        }
        $qty = (float) $this->qty;
        $unit = (float) $this->unitPrice;
        if ($qty <= 0) {
            return null;
        }

        return number_format($qty * $unit, 2, '.', '');
    }

    public function source(): string
    {
        return $this->source;
    }

    public function externalId(): ?string
    {
        return $this->externalId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private static function normalizePrice(?string $unitPrice): ?string
    {
        if ($unitPrice === null) {
            return null;
        }
        $raw = trim(str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], $unitPrice));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        return number_format((float) $raw, 2, '.', '');
    }
}
