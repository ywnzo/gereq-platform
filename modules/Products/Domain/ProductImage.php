<?php

declare(strict_types=1);

namespace Modules\Products\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'product_images')]
#[ORM\Index(name: 'idx_product_images_product', columns: ['product_id'])]
#[ORM\Index(name: 'idx_product_images_media', columns: ['media_asset_id'])]
class ProductImage
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'product_id', length: 36)]
    private string $productId;

    #[ORM\Column(name: 'media_asset_id', length: 36)]
    private string $mediaAssetId;

    #[ORM\Column(name: 'sort_order')]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'is_primary')]
    private bool $isPrimary = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $alt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $productId, string $mediaAssetId, int $sortOrder = 0)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->productId = $productId;
        $this->mediaAssetId = $mediaAssetId;
        $this->sortOrder = $sortOrder;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function productId(): string
    {
        return $this->productId;
    }

    public function mediaAssetId(): string
    {
        return $this->mediaAssetId;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function alt(): ?string
    {
        return $this->alt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    public function setPrimary(bool $primary): void
    {
        $this->isPrimary = $primary;
    }

    public function setAlt(?string $alt): void
    {
        $this->alt = $alt !== null && $alt !== '' ? $alt : null;
    }
}
