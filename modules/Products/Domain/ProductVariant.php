<?php

declare(strict_types=1);

namespace Modules\Products\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * Concrete sellable SKU for a product (option combination).
 * attributesJson: {"size":"m","color":"blue"} — generic, any option codes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_variants')]
#[ORM\Index(name: 'idx_product_variants_product', columns: ['product_id'])]
#[ORM\UniqueConstraint(name: 'uniq_product_variants_sku', columns: ['sku'])]
class ProductVariant
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'product_id', length: 36)]
    private string $productId;

    #[ORM\Column(length: 64)]
    private string $sku;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $name = null;

    /** @var string JSON object of option_code => value_code */
    #[ORM\Column(name: 'attributes_json', type: 'text')]
    private string $attributesJson = '{}';

    #[ORM\Column(name: 'price_amount', type: 'decimal', precision: 14, scale: 2, nullable: true)]
    private ?string $priceAmount = null;

    #[ORM\Column(name: 'price_currency', length: 3, nullable: true)]
    private ?string $priceCurrency = null;

    #[ORM\Column(name: 'stock_qty', type: 'integer', nullable: true)]
    private ?int $stockQty = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $ean = null;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $productId, string $sku)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->productId = $productId;
        $this->sku = strtoupper(trim($sku));
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function productId(): string
    {
        return $this->productId;
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $decoded = json_decode($this->attributesJson, true);

        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }

    public function priceAmount(): ?string
    {
        return $this->priceAmount;
    }

    public function priceCurrency(): ?string
    {
        return $this->priceCurrency;
    }

    public function stockQty(): ?int
    {
        return $this->stockQty;
    }

    public function ean(): ?string
    {
        return $this->ean;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setSku(string $sku): void
    {
        $this->sku = strtoupper(trim($sku));
        $this->touch();
    }

    public function setName(?string $name): void
    {
        $this->name = $name !== null && $name !== '' ? $name : null;
        $this->touch();
    }

    /** @param array<string, string> $attributes */
    public function setAttributes(array $attributes): void
    {
        ksort($attributes);
        $this->attributesJson = json_encode($attributes, JSON_UNESCAPED_UNICODE) ?: '{}';
        $this->touch();
    }

    public function setPrice(?string $amount, ?string $currency = 'CZK'): void
    {
        if ($amount === null || $amount === '') {
            $this->priceAmount = null;
            $this->priceCurrency = null;
        } else {
            $this->priceAmount = number_format((float) $amount, 2, '.', '');
            $this->priceCurrency = $currency !== null ? strtoupper($currency) : 'CZK';
        }
        $this->touch();
    }

    public function setStockQty(?int $qty): void
    {
        $this->stockQty = $qty;
        $this->touch();
    }

    public function setEan(?string $ean): void
    {
        $this->ean = $ean !== null && $ean !== '' ? $ean : null;
        $this->touch();
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
