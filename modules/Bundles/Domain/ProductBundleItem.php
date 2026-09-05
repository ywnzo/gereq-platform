<?php

declare(strict_types=1);

namespace Modules\Bundles\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'product_bundle_items')]
#[ORM\Index(name: 'idx_product_bundle_items_bundle', columns: ['bundle_id'])]
class ProductBundleItem
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'bundle_id', length: 36)]
    private string $bundleId;

    #[ORM\Column(name: 'product_id', length: 36)]
    private string $productId;

    #[ORM\Column(length: 190)]
    private string $title;

    #[ORM\Column(type: 'integer')]
    private int $qty = 1;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder = 0;

    public function __construct(string $bundleId, string $productId, string $title, int $qty = 1, int $sortOrder = 0)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->bundleId = $bundleId;
        $this->productId = $productId;
        $this->title = $title;
        $this->qty = max(1, $qty);
        $this->sortOrder = $sortOrder;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function bundleId(): string
    {
        return $this->bundleId;
    }

    public function productId(): string
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

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setProductId(string $productId): void
    {
        $this->productId = $productId;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function setQty(int $qty): void
    {
        $this->qty = max(1, $qty);
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }
}
