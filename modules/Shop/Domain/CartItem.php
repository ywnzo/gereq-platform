<?php

declare(strict_types=1);

namespace Modules\Shop\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'shop_cart_items')]
#[ORM\Index(name: 'idx_shop_cart_items_cart', columns: ['cart_id'])]
#[ORM\Index(name: 'idx_shop_cart_items_product', columns: ['product_id'])]
#[ORM\UniqueConstraint(name: 'uniq_shop_cart_item_variant', columns: ['cart_id', 'product_id', 'variant_id'])]
class CartItem
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'cart_id', length: 36)]
    private string $cartId;

    #[ORM\Column(name: 'product_id', length: 36)]
    private string $productId;

    #[ORM\Column(name: 'variant_id', length: 36, options: ['default' => ''])]
    private string $variantId = '';

    #[ORM\Column(length: 190)]
    private string $title;

    #[ORM\Column(length: 64)]
    private string $sku;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 4)]
    private string $qty = '1.0000';

    #[ORM\Column(name: 'unit_price', type: 'decimal', precision: 14, scale: 2)]
    private string $unitPrice = '0.00';

    #[ORM\Column(length: 3)]
    private string $currency = 'CZK';

    public function __construct(
        string $cartId,
        string $productId,
        string $title,
        string $sku,
        string $qty,
        string $unitPrice,
        string $currency = 'CZK',
        ?string $variantId = null,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->cartId = $cartId;
        $this->productId = $productId;
        $this->variantId = $variantId !== null && $variantId !== '' ? $variantId : '';
        $this->title = $title;
        $this->sku = strtoupper($sku);
        $this->qty = number_format((float) $qty, 4, '.', '');
        $this->unitPrice = number_format((float) $unitPrice, 2, '.', '');
        $this->currency = strtoupper($currency);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function cartId(): string
    {
        return $this->cartId;
    }

    public function productId(): string
    {
        return $this->productId;
    }

    public function variantId(): ?string
    {
        return $this->variantId !== '' ? $this->variantId : null;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function qty(): string
    {
        return $this->qty;
    }

    public function unitPrice(): string
    {
        return $this->unitPrice;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function setSku(string $sku): void
    {
        $this->sku = strtoupper($sku);
    }

    public function setQty(string $qty): void
    {
        $this->qty = number_format((float) $qty, 4, '.', '');
    }

    public function setUnitPrice(string $unitPrice): void
    {
        $this->unitPrice = number_format((float) $unitPrice, 2, '.', '');
    }

    public function setCurrency(string $currency): void
    {
        $this->currency = strtoupper($currency);
    }

    public function lineTotal(): string
    {
        return number_format((float) $this->qty * (float) $this->unitPrice, 2, '.', '');
    }
}
