<?php

declare(strict_types=1);

namespace Modules\Shop\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'shop_shipping_methods')]
#[ORM\UniqueConstraint(name: 'uniq_shop_shipping_code', columns: ['code'])]
class ShippingMethod
{
    public const TYPES = [
        'pickup',
        'courier',
        'parcel_shop',
        'post',
        'express',
    ];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $carrier = null;

    #[ORM\Column(length: 32, options: ['default' => 'courier'])]
    private string $type = 'courier';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    private string $price = '0.00';

    #[ORM\Column(length: 3)]
    private string $currency = 'CZK';

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'estimated_days', type: 'integer', nullable: true)]
    private ?int $estimatedDays = null;

    #[ORM\Column(name: 'sort_order', type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code, string $name, string $price = '0', string $currency = 'CZK')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->code = strtoupper(trim($code));
        $this->name = $name;
        $this->price = number_format((float) $price, 2, '.', '');
        $this->currency = strtoupper($currency);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function carrier(): ?string
    {
        return $this->carrier;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function price(): string
    {
        return $this->price;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function estimatedDays(): ?int
    {
        return $this->estimatedDays;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function typeLabel(): string
    {
        return 'shop.shipping.type.' . $this->type;
    }

    public function setCode(string $code): void
    {
        $this->code = strtoupper(trim($code));
        $this->touch();
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function setCarrier(?string $carrier): void
    {
        $carrier = $carrier !== null ? trim($carrier) : null;
        $this->carrier = $carrier === '' ? null : $carrier;
        $this->touch();
    }

    public function setType(string $type): void
    {
        $type = strtolower(trim($type));
        $this->type = in_array($type, self::TYPES, true) ? $type : 'courier';
        $this->touch();
    }

    public function setDescription(?string $description): void
    {
        $description = $description !== null ? trim($description) : null;
        $this->description = $description === '' ? null : $description;
        $this->touch();
    }

    public function setPrice(string $price): void
    {
        $this->price = number_format((float) $price, 2, '.', '');
        $this->touch();
    }

    public function setCurrency(string $currency): void
    {
        $this->currency = strtoupper($currency);
        $this->touch();
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    public function setEstimatedDays(?int $days): void
    {
        $this->estimatedDays = $days;
        $this->touch();
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
