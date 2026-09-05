<?php

declare(strict_types=1);

namespace Modules\Products\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'product_option_values')]
#[ORM\Index(name: 'idx_product_option_values_group', columns: ['group_id'])]
#[ORM\UniqueConstraint(name: 'uniq_product_option_values_code', columns: ['group_id', 'code'])]
class ProductOptionValue
{
    public const ADJUST_NONE = 'none';
    public const ADJUST_AMOUNT = 'amount';
    public const ADJUST_PERCENT = 'percent';
    public const ADJUST_KINDS = [self::ADJUST_NONE, self::ADJUST_AMOUNT, self::ADJUST_PERCENT];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'group_id', length: 36)]
    private string $groupId;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder = 0;

    /** none | amount | percent — signed value raises/lowers base price */
    #[ORM\Column(name: 'price_adjust_kind', length: 16, options: ['default' => 'none'])]
    private string $priceAdjustKind = self::ADJUST_NONE;

    #[ORM\Column(name: 'price_adjust_value', type: 'decimal', precision: 14, scale: 2, options: ['default' => '0.00'])]
    private string $priceAdjustValue = '0.00';

    public function __construct(string $groupId, string $code, string $name)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->groupId = $groupId;
        $this->code = strtolower(trim($code));
        $this->name = $name;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function groupId(): string
    {
        return $this->groupId;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function priceAdjustKind(): string
    {
        return $this->priceAdjustKind;
    }

    public function priceAdjustValue(): string
    {
        return $this->priceAdjustValue;
    }

    public function hasPriceAdjust(): bool
    {
        return $this->priceAdjustKind !== self::ADJUST_NONE && (float) $this->priceAdjustValue != 0.0;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setCode(string $code): void
    {
        $this->code = strtolower(trim($code));
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    public function setPriceAdjust(string $kind, string|float|int $value): void
    {
        $kind = strtolower(trim($kind));
        if (!in_array($kind, self::ADJUST_KINDS, true)) {
            $kind = self::ADJUST_NONE;
        }
        $this->priceAdjustKind = $kind;
        $this->priceAdjustValue = number_format((float) $value, 2, '.', '');
        if ($kind === self::ADJUST_NONE) {
            $this->priceAdjustValue = '0.00';
        }
    }

    /** Apply this modifier to a base price (can raise or lower). */
    public function applyToPrice(float $base): float
    {
        if (!$this->hasPriceAdjust()) {
            return $base;
        }
        $v = (float) $this->priceAdjustValue;
        if ($this->priceAdjustKind === self::ADJUST_PERCENT) {
            return max(0, round($base * (1 + $v / 100) * 100) / 100);
        }

        return max(0, round(($base + $v) * 100) / 100);
    }
}
