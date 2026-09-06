<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'partscatalog_brands')]
#[ORM\UniqueConstraint(name: 'uniq_pc_brand_provider_slug', columns: ['provider', 'slug'])]
#[ORM\Index(name: 'idx_pc_brand_provider', columns: ['provider'])]
class CatalogBrand
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 32)]
    private string $provider;

    #[ORM\Column(length: 120)]
    private string $slug;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(name: 'synced_at', nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $provider, string $slug, string $name)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->provider = $provider;
        $this->slug = $slug;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function provider(): string
    {
        return $this->provider;
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
