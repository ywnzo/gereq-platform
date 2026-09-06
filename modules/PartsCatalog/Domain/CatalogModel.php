<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'partscatalog_models')]
#[ORM\UniqueConstraint(name: 'uniq_pc_model_brand_slug', columns: ['brand_id', 'slug'])]
#[ORM\Index(name: 'idx_pc_model_brand', columns: ['brand_id'])]
class CatalogModel
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'brand_id', length: 36)]
    private string $brandId;

    #[ORM\Column(length: 180)]
    private string $slug;

    #[ORM\Column(length: 220)]
    private string $name;

    #[ORM\Column(name: 'synced_at', nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $brandId, string $slug, string $name)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->brandId = $brandId;
        $this->slug = $slug;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function brandId(): string
    {
        return $this->brandId;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function syncedAt(): ?\DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function markSynced(): void
    {
        $this->syncedAt = new \DateTimeImmutable();
    }
}
