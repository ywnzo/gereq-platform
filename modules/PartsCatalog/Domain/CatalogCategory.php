<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'partscatalog_categories')]
#[ORM\UniqueConstraint(name: 'uniq_pc_category_vehicle_path', columns: ['vehicle_id', 'path'])]
#[ORM\Index(name: 'idx_pc_category_vehicle', columns: ['vehicle_id'])]
#[ORM\Index(name: 'idx_pc_category_parent', columns: ['parent_id'])]
class CatalogCategory
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'vehicle_id', length: 36)]
    private string $vehicleId;

    #[ORM\Column(name: 'parent_id', length: 36, nullable: true)]
    private ?string $parentId;

    #[ORM\Column(length: 400)]
    private string $path;

    #[ORM\Column(length: 220)]
    private string $name;

    #[ORM\Column(name: 'has_children')]
    private bool $hasChildren = false;

    #[ORM\Column(name: 'has_products')]
    private bool $hasProducts = false;

    #[ORM\Column(name: 'synced_at', nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $vehicleId,
        string $path,
        string $name,
        ?string $parentId = null,
        bool $hasChildren = false,
        bool $hasProducts = false,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->vehicleId = $vehicleId;
        $this->parentId = $parentId;
        $this->path = mb_substr($path, 0, 400);
        $this->name = mb_substr($name, 0, 220);
        $this->hasChildren = $hasChildren;
        $this->hasProducts = $hasProducts;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function vehicleId(): string
    {
        return $this->vehicleId;
    }

    public function parentId(): ?string
    {
        return $this->parentId;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function hasChildren(): bool
    {
        return $this->hasChildren;
    }

    public function hasProducts(): bool
    {
        return $this->hasProducts;
    }

    public function updateFlags(string $name, bool $hasChildren, bool $hasProducts): void
    {
        $this->name = mb_substr($name, 0, 220);
        $this->hasChildren = $hasChildren;
        $this->hasProducts = $hasProducts;
        $this->syncedAt = new \DateTimeImmutable();
    }
}
