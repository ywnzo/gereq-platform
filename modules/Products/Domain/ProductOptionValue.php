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
