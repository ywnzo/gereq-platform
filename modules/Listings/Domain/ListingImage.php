<?php

declare(strict_types=1);

namespace Modules\Listings\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'listing_images')]
#[ORM\Index(name: 'idx_listing_images_listing', columns: ['listing_id'])]
#[ORM\Index(name: 'idx_listing_images_media', columns: ['media_asset_id'])]
class ListingImage
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'listing_id', length: 36)]
    private string $listingId;

    #[ORM\Column(name: 'media_asset_id', length: 36, nullable: true)]
    private ?string $mediaAssetId = null;

    #[ORM\Column(name: 'external_url', length: 500, nullable: true)]
    private ?string $externalUrl = null;

    #[ORM\Column(name: 'sort_order')]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'is_primary')]
    private bool $isPrimary = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $alt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $listingId, int $sortOrder = 0)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->listingId = $listingId;
        $this->sortOrder = $sortOrder;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function fromMedia(string $listingId, string $mediaAssetId, int $sortOrder = 0): self
    {
        $image = new self($listingId, $sortOrder);
        $image->mediaAssetId = $mediaAssetId;

        return $image;
    }

    public static function fromUrl(string $listingId, string $url, int $sortOrder = 0): self
    {
        $image = new self($listingId, $sortOrder);
        $image->externalUrl = $url;

        return $image;
    }

    public function id(): string { return $this->id; }
    public function listingId(): string { return $this->listingId; }
    public function mediaAssetId(): ?string { return $this->mediaAssetId; }
    public function externalUrl(): ?string { return $this->externalUrl; }
    public function sortOrder(): int { return $this->sortOrder; }
    public function isPrimary(): bool { return $this->isPrimary; }
    public function alt(): ?string { return $this->alt; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }

    public function setSortOrder(int $sortOrder): void { $this->sortOrder = $sortOrder; }
    public function setPrimary(bool $primary): void { $this->isPrimary = $primary; }
    public function setAlt(?string $alt): void { $this->alt = $alt !== null && $alt !== '' ? $alt : null; }
    public function setExternalUrl(?string $url): void { $this->externalUrl = $url !== null && $url !== '' ? $url : null; }
    public function setMediaAssetId(?string $mediaAssetId): void { $this->mediaAssetId = $mediaAssetId; }

    public function isExternal(): bool
    {
        return $this->externalUrl !== null && $this->externalUrl !== '';
    }
}
