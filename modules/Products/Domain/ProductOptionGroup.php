<?php

declare(strict_types=1);

namespace Modules\Products\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'product_option_groups')]
#[ORM\Index(name: 'idx_product_option_groups_product', columns: ['product_id'])]
#[ORM\UniqueConstraint(name: 'uniq_product_option_groups_code', columns: ['product_id', 'code'])]
class ProductOptionGroup
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'product_id', length: 36)]
    private string $productId;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder = 0;

    public function __construct(string $productId, string $code, string $name)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->productId = $productId;
        $this->code = strtolower(trim($code));
        $this->name = $name;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function productId(): string
    {
        return $this->productId;
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
}
