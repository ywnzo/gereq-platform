<?php

declare(strict_types=1);

namespace Gereq\Media\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'media_links')]
#[ORM\Index(name: 'idx_media_link_entity', columns: ['entity_type', 'entity_id'])]
#[ORM\Index(name: 'idx_media_link_asset', columns: ['media_asset_id'])]
class MediaLink
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'media_asset_id', length: 36)]
    private string $mediaAssetId;

    #[ORM\Column(name: 'entity_type', length: 80)]
    private string $entityType;

    #[ORM\Column(name: 'entity_id', length: 36)]
    private string $entityId;

    /** Optional phase / role label (e.g. before, after, orv). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $mediaAssetId, string $entityType, string $entityId, ?string $label = null, int $sortOrder = 0)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->mediaAssetId = $mediaAssetId;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->label = $label !== null && $label !== '' ? $label : null;
        $this->sortOrder = $sortOrder;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function mediaAssetId(): string
    {
        return $this->mediaAssetId;
    }

    public function entityType(): string
    {
        return $this->entityType;
    }

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function label(): ?string
    {
        return $this->label;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setLabel(?string $label): void
    {
        $this->label = $label !== null && $label !== '' ? $label : null;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }
}
