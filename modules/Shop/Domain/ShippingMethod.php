<?php

declare(strict_types=1);

namespace Modules\Shop\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'shop_shipping_methods')]
#[ORM\UniqueConstraint(name: 'uniq_shop_shipping_code', columns: ['code'])]
#[FormEntity('shop.shipping.save')]
#[FormGenerator(
    exclude: ['id', 'createdAt', 'updatedAt'],
    form: 'shop.shipping.save',
    cols: 2,
)]
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
    #[FormField(required: true, label: 'shop.field.code', order: 10)]
    private string $code;

    #[ORM\Column(length: 190)]
    #[FormField(required: true, label: 'shop.field.name', order: 20)]
    private string $name;

    #[ORM\Column(length: 64, nullable: true)]
    #[FormField(
        label: 'shop.field.carrier',
        hint: 'shop.field.carrier.hint',
        order: 30,
    )]
    private ?string $carrier = null;

    #[ORM\Column(length: 32, options: ['default' => 'courier'])]
    #[FormField(
        label: 'shop.field.shipping_type',
        type: 'select',
        choicesFrom: 'TYPES',
        choiceLabel: 'shop.shipping.type.',
        order: 40,
    )]
    private string $type = 'courier';

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    #[FormField(required: true, label: 'shop.field.price', type: 'number', options: ['step' => '0.01'], order: 50)]
    private string $price = '0.00';

    #[ORM\Column(length: 3)]
    #[FormField(label: 'shop.field.currency', options: ['maxlength' => 3], order: 60)]
    private string $currency = 'CZK';

    #[ORM\Column(name: 'estimated_days', type: 'integer', nullable: true)]
    #[FormField(label: 'shop.field.estimated_days', type: 'number', order: 70)]
    private ?int $estimatedDays = null;

    #[ORM\Column(name: 'sort_order', type: 'integer', options: ['default' => 0])]
    #[FormField(label: 'shop.field.sort_order', type: 'number', order: 80)]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    #[FormField(label: 'shop.field.description', type: 'textarea', extra: 'field--full', order: 90)]
    private ?string $description = null;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    #[FormField(name: 'active', label: 'shop.field.active', type: 'switch', order: 100)]
    private bool $active = true;

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
