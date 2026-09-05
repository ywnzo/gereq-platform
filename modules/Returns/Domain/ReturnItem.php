<?php

declare(strict_types=1);

namespace Modules\Returns\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'return_items')]
#[ORM\Index(name: 'idx_return_items_return', columns: ['return_id'])]
class ReturnItem
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'return_id', length: 36)]
    private string $returnId;

    #[ORM\Column(name: 'product_id', length: 36, nullable: true)]
    private ?string $productId = null;

    #[ORM\Column(length: 190)]
    private string $title;

    #[ORM\Column(type: 'integer')]
    private int $qty = 1;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    public function __construct(string $returnId, string $title, int $qty = 1)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->returnId = $returnId;
        $this->title = $title;
        $this->qty = max(1, $qty);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function returnId(): string
    {
        return $this->returnId;
    }

    public function productId(): ?string
    {
        return $this->productId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function qty(): int
    {
        return $this->qty;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function setProductId(?string $productId): void
    {
        $this->productId = $productId !== '' ? $productId : null;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function setQty(int $qty): void
    {
        $this->qty = max(1, $qty);
    }

    public function setReason(?string $reason): void
    {
        $this->reason = $reason !== '' ? $reason : null;
    }
}
