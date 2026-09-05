<?php

declare(strict_types=1);

namespace Modules\Products\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'product_option_groups')]
#[ORM\Index(name: 'idx_product_option_groups_scope', columns: ['scope_type', 'scope_id'])]
#[ORM\UniqueConstraint(name: 'uniq_product_option_groups_scope_code', columns: ['scope_type', 'scope_id', 'code'])]
class ProductOptionGroup
{
    public const SCOPE_PRODUCT = 'product';
    public const SCOPE_CATEGORY = 'category';

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'scope_type', length: 16)]
    private string $scopeType;

    #[ORM\Column(name: 'scope_id', length: 36)]
    private string $scopeId;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'is_required', type: 'boolean', options: ['default' => true])]
    private bool $required = true;

    public function __construct(string $scopeType, string $scopeId, string $code, string $name)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->scopeType = in_array($scopeType, [self::SCOPE_PRODUCT, self::SCOPE_CATEGORY], true)
            ? $scopeType
            : self::SCOPE_PRODUCT;
        $this->scopeId = $scopeId;
        $this->code = strtolower(trim($code));
        $this->name = $name;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function scopeType(): string
    {
        return $this->scopeType;
    }

    public function scopeId(): string
    {
        return $this->scopeId;
    }

    public function isCategoryScoped(): bool
    {
        return $this->scopeType === self::SCOPE_CATEGORY;
    }

    /** @deprecated use scopeId() when scope is product */
    public function productId(): string
    {
        return $this->scopeType === self::SCOPE_PRODUCT ? $this->scopeId : '';
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

    public function isRequired(): bool
    {
        return $this->required;
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

    public function setRequired(bool $required): void
    {
        $this->required = $required;
    }
}
