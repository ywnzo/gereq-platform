<?php

declare(strict_types=1);

namespace Modules\Orders\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'order_lines')]
#[ORM\Index(name: 'idx_order_lines_order', columns: ['order_id'])]
class OrderLine
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'order_id', length: 36)]
    private string $orderId;

    #[ORM\Column(name: 'product_id', length: 36, nullable: true)]
    private ?string $productId = null;

    #[ORM\Column(name: 'variant_id', length: 36, nullable: true)]
    private ?string $variantId = null;

    #[ORM\Column(name: 'sku', length: 64, nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(length: 190)]
    private string $title;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 4)]
    private string $qty = '1.0000';

    #[ORM\Column(name: 'unit_price', type: 'decimal', precision: 14, scale: 2)]
    private string $unitPrice = '0.00';

    #[ORM\Column(name: 'tax_rate', type: 'decimal', precision: 6, scale: 2)]
    private string $taxRate = '0.00';

    public function __construct(string $orderId, string $title, string $qty, string $unitPrice, string $taxRate = '0')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->orderId = $orderId;
        $this->title = $title;
        $this->qty = number_format((float) $qty, 4, '.', '');
        $this->unitPrice = number_format((float) $unitPrice, 2, '.', '');
        $this->taxRate = number_format((float) $taxRate, 2, '.', '');
    }

    public function id(): string { return $this->id; }
    public function orderId(): string { return $this->orderId; }
    public function productId(): ?string { return $this->productId; }
    public function variantId(): ?string { return $this->variantId; }
    public function sku(): ?string { return $this->sku; }
    public function title(): string { return $this->title; }
    public function qty(): string { return $this->qty; }
    public function unitPrice(): string { return $this->unitPrice; }
    public function taxRate(): string { return $this->taxRate; }

    public function setProductId(?string $productId): void { $this->productId = $productId !== '' ? $productId : null; }
    public function setVariantId(?string $variantId): void { $this->variantId = $variantId !== '' ? $variantId : null; }
    public function setSku(?string $sku): void { $this->sku = $sku !== null && $sku !== '' ? strtoupper($sku) : null; }
    public function setTitle(string $title): void { $this->title = $title; }
    public function setQty(string $qty): void { $this->qty = number_format((float) $qty, 4, '.', ''); }
    public function setUnitPrice(string $unitPrice): void { $this->unitPrice = number_format((float) $unitPrice, 2, '.', ''); }
    public function setTaxRate(string $taxRate): void { $this->taxRate = number_format((float) $taxRate, 2, '.', ''); }

    public function lineSubtotal(): string
    {
        return number_format((float) $this->qty * (float) $this->unitPrice, 2, '.', '');
    }

    public function lineTax(): string
    {
        return number_format((float) $this->lineSubtotal() * ((float) $this->taxRate / 100), 2, '.', '');
    }

    public function lineTotal(): string
    {
        return number_format((float) $this->lineSubtotal() + (float) $this->lineTax(), 2, '.', '');
    }
}
