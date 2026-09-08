<?php

declare(strict_types=1);

namespace Modules\ProductDiscounts\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

/**
 * Catalog sale on a product or category (percent or fixed amount).
 * Optional stock conditions (e.g. remaining qty ≤ N).
 */
#[ORM\Entity]
#[ORM\Table(name: 'product_discounts')]
#[ORM\Index(name: 'idx_product_discounts_scope', columns: ['scope_type', 'scope_id'])]
#[ORM\Index(name: 'idx_product_discounts_active_window', columns: ['is_active', 'starts_at', 'ends_at'])]
#[FormEntity('product_discounts.save')]
#[FormGenerator(
    exclude: ['id', 'productId', 'scopeType', 'scopeId', 'createdAt', 'updatedAt'],
    form: 'product_discounts.save',
    cols: 2,
)]
class ProductDiscount
{
    public const KINDS = ['percent', 'amount'];

    public const SCOPE_PRODUCT = 'product';
    public const SCOPE_CATEGORY = 'category';
    public const SCOPES = [self::SCOPE_PRODUCT, self::SCOPE_CATEGORY];

    public const CONDITION_NONE = 'none';
    public const CONDITION_STOCK_LTE = 'stock_lte';
    public const CONDITION_STOCK_GTE = 'stock_gte';
    public const CONDITIONS = [
        self::CONDITION_NONE,
        self::CONDITION_STOCK_LTE,
        self::CONDITION_STOCK_GTE,
    ];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    /** Legacy mirror of product scope_id (nullable for category discounts). */
    #[ORM\Column(name: 'product_id', length: 36, nullable: true)]
    private ?string $productId = null;

    #[ORM\Column(name: 'scope_type', length: 16, options: ['default' => 'product'])]
    private string $scopeType = self::SCOPE_PRODUCT;

    #[ORM\Column(name: 'scope_id', length: 36)]
    private string $scopeId;

    #[ORM\Column(length: 16)]
    #[FormField(
        required: true,
        label: 'product_discounts.field.kind',
        type: 'select',
        choicesFrom: 'KINDS',
        choiceLabel: 'product_discounts.kind.',
        order: 10,
    )]
    private string $kind = 'percent';

    #[ORM\Column(type: 'decimal', precision: 14, scale: 2)]
    #[FormField(required: true, label: 'product_discounts.field.value', order: 20)]
    private string $value = '0.00';

    #[ORM\Column(length: 190, nullable: true)]
    #[FormField(label: 'product_discounts.field.description', order: 50)]
    private ?string $description = null;

    #[ORM\Column(name: 'condition_type', length: 32, options: ['default' => 'none'])]
    #[FormField(
        label: 'product_discounts.field.condition',
        type: 'select',
        name: 'condition_type',
        choicesFrom: 'CONDITIONS',
        choiceLabel: 'product_discounts.condition.',
        order: 30,
    )]
    private string $conditionType = self::CONDITION_NONE;

    #[ORM\Column(name: 'condition_value', type: 'integer', nullable: true)]
    #[FormField(
        label: 'product_discounts.field.condition_value',
        type: 'number',
        name: 'condition_value',
        hint: 'product_discounts.field.condition_value.hint',
        order: 40,
    )]
    private ?int $conditionValue = null;

    #[ORM\Column(name: 'starts_at', nullable: true)]
    #[FormField(label: 'product_discounts.field.starts_at', type: 'datetime-local', order: 60)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(name: 'ends_at', nullable: true)]
    #[FormField(label: 'product_discounts.field.ends_at', type: 'datetime-local', order: 70)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    #[FormField(name: 'active', label: 'product_discounts.field.active', type: 'switch', order: 80)]
    private bool $active = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $scopeType,
        string $scopeId,
        string $kind = 'percent',
        string $value = '0',
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->scopeType = $this->normalizeScope($scopeType);
        $this->scopeId = $scopeId;
        $this->productId = $this->scopeType === self::SCOPE_PRODUCT ? $scopeId : null;
        $this->kind = $this->normalizeKind($kind);
        $this->value = number_format((float) $value, 2, '.', '');
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public static function forProduct(string $productId, string $kind = 'percent', string $value = '0'): self
    {
        return new self(self::SCOPE_PRODUCT, $productId, $kind, $value);
    }

    public static function forCategory(string $categoryId, string $kind = 'percent', string $value = '0'): self
    {
        return new self(self::SCOPE_CATEGORY, $categoryId, $kind, $value);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function productId(): ?string
    {
        return $this->productId ?? ($this->scopeType === self::SCOPE_PRODUCT ? $this->scopeId : null);
    }

    public function scopeType(): string
    {
        return $this->scopeType;
    }

    public function scopeId(): string
    {
        return $this->scopeId;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function conditionType(): string
    {
        return $this->conditionType;
    }

    public function conditionValue(): ?int
    {
        return $this->conditionValue;
    }

    public function startsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function endsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
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

    public function isCurrentlyActive(?\DateTimeImmutable $now = null): bool
    {
        if (!$this->active) {
            return false;
        }
        $now ??= new \DateTimeImmutable();
        if ($this->startsAt !== null && $this->startsAt > $now) {
            return false;
        }
        if ($this->endsAt !== null && $this->endsAt < $now) {
            return false;
        }

        return true;
    }

    public function matchesStockCondition(?int $stockQty): bool
    {
        return match ($this->conditionType) {
            self::CONDITION_NONE => true,
            self::CONDITION_STOCK_LTE => $stockQty !== null
                && $this->conditionValue !== null
                && $stockQty <= $this->conditionValue,
            self::CONDITION_STOCK_GTE => $stockQty !== null
                && $this->conditionValue !== null
                && $stockQty >= $this->conditionValue,
            default => false,
        };
    }

    public function setKind(string $kind): void
    {
        $this->kind = $this->normalizeKind($kind);
        $this->touch();
    }

    public function setValue(string $value): void
    {
        $this->value = number_format((float) $value, 2, '.', '');
        $this->touch();
    }

    public function setDescription(?string $description): void
    {
        $description = $description !== null ? trim($description) : null;
        $this->description = $description !== '' ? $description : null;
        $this->touch();
    }

    public function setCondition(string $type, ?int $value): void
    {
        $type = strtolower(trim($type));
        if (!in_array($type, self::CONDITIONS, true)) {
            $type = self::CONDITION_NONE;
        }
        $this->conditionType = $type;
        $this->conditionValue = $type === self::CONDITION_NONE ? null : $value;
        $this->touch();
    }

    public function setStartsAt(?\DateTimeImmutable $startsAt): void
    {
        $this->startsAt = $startsAt;
        $this->touch();
    }

    public function setEndsAt(?\DateTimeImmutable $endsAt): void
    {
        $this->endsAt = $endsAt;
        $this->touch();
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    private function normalizeKind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        if ($kind === 'fixed') {
            return 'amount';
        }

        return in_array($kind, self::KINDS, true) ? $kind : 'percent';
    }

    private function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));

        return in_array($scope, self::SCOPES, true) ? $scope : self::SCOPE_PRODUCT;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
