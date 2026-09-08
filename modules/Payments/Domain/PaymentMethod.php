<?php

declare(strict_types=1);

namespace Modules\Payments\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'payment_methods')]
#[ORM\UniqueConstraint(name: 'uniq_payment_methods_code', columns: ['code'])]
#[FormEntity('payments.method.save')]
#[FormGenerator(
    exclude: ['id', 'createdAt', 'updatedAt'],
    form: 'payments.method.save',
    cols: 2,
)]
class PaymentMethod
{
    public const TYPES = [
        'bank_transfer',
        'card',
        'cod',
        'online',
        'wallet',
    ];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 64)]
    #[FormField(required: true, label: 'payments.field.code', order: 10)]
    private string $code;

    #[ORM\Column(length: 190)]
    #[FormField(required: true, label: 'payments.field.name', order: 20)]
    private string $name;

    #[ORM\Column(length: 32)]
    #[FormField(
        required: true,
        label: 'payments.field.type',
        type: 'select',
        choicesFrom: 'TYPES',
        choiceLabel: 'payments.type.',
        order: 30,
    )]
    private string $type = 'bank_transfer';

    #[ORM\Column(length: 64, nullable: true)]
    #[FormField(
        label: 'payments.field.provider',
        hint: 'payments.field.provider.hint',
        order: 40,
    )]
    private ?string $provider = null;

    #[ORM\Column(name: 'fee_fixed', type: 'decimal', precision: 14, scale: 2)]
    #[FormField(label: 'payments.field.fee_fixed', type: 'number', options: ['step' => '0.01'], order: 50)]
    private string $feeFixed = '0.00';

    #[ORM\Column(name: 'fee_percent', type: 'decimal', precision: 8, scale: 4)]
    #[FormField(label: 'payments.field.fee_percent', type: 'number', options: ['step' => '0.0001'], order: 60)]
    private string $feePercent = '0.0000';

    #[ORM\Column(length: 3)]
    #[FormField(label: 'payments.field.currency', options: ['maxlength' => 3], order: 70)]
    private string $currency = 'CZK';

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    #[FormField(label: 'payments.field.sort_order', type: 'number', order: 80)]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    #[FormField(label: 'payments.field.instructions', type: 'textarea', extra: 'field--full', order: 90)]
    private ?string $instructions = null;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    #[FormField(name: 'active', label: 'payments.field.active', type: 'switch', order: 100)]
    private bool $active = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code, string $name, string $type = 'bank_transfer')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->code = strtoupper(trim($code));
        $this->name = $name;
        $this->type = $this->normalizeType($type);
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

    public function type(): string
    {
        return $this->type;
    }

    public function provider(): ?string
    {
        return $this->provider;
    }

    public function feeFixed(): string
    {
        return $this->feeFixed;
    }

    public function feePercent(): string
    {
        return $this->feePercent;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function instructions(): ?string
    {
        return $this->instructions;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
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

    public function setType(string $type): void
    {
        $this->type = $this->normalizeType($type);
        $this->touch();
    }

    public function setProvider(?string $provider): void
    {
        $provider = $provider !== null ? trim($provider) : null;
        $this->provider = $provider === '' ? null : $provider;
        $this->touch();
    }

    public function setFeeFixed(string $feeFixed): void
    {
        $this->feeFixed = number_format((float) $feeFixed, 2, '.', '');
        $this->touch();
    }

    public function setFeePercent(string $feePercent): void
    {
        $this->feePercent = number_format((float) $feePercent, 4, '.', '');
        $this->touch();
    }

    public function setCurrency(string $currency): void
    {
        $this->currency = strtoupper(trim($currency)) ?: 'CZK';
        $this->touch();
    }

    public function setInstructions(?string $instructions): void
    {
        $instructions = $instructions !== null ? trim($instructions) : null;
        $this->instructions = $instructions === '' ? null : $instructions;
        $this->touch();
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
        $this->touch();
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    public function typeLabel(): string
    {
        return 'payments.type.' . $this->type;
    }

    /** Poplatek za platbu z částky objednávky (bez dopravy/poplatku). */
    public function feeForAmount(string $amount): string
    {
        $fee = (float) $this->feeFixed + ((float) $amount * (float) $this->feePercent / 100.0);

        return number_format(max(0, $fee), 2, '.', '');
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, self::TYPES, true)) {
            return 'bank_transfer';
        }

        return $type;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
