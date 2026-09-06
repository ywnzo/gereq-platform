<?php

declare(strict_types=1);

namespace Modules\PartsCatalog\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'partscatalog_vehicle_parts')]
#[ORM\UniqueConstraint(name: 'uniq_pc_vehicle_part', columns: ['vehicle_id', 'category_id', 'part_id'])]
#[ORM\Index(name: 'idx_pc_vp_vehicle', columns: ['vehicle_id'])]
#[ORM\Index(name: 'idx_pc_vp_part', columns: ['part_id'])]
#[ORM\Index(name: 'idx_pc_vp_category', columns: ['category_id'])]
class CatalogVehiclePart
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'vehicle_id', length: 36)]
    private string $vehicleId;

    #[ORM\Column(name: 'category_id', length: 36)]
    private string $categoryId;

    #[ORM\Column(name: 'part_id', length: 36)]
    private string $partId;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $vehicleId, string $categoryId, string $partId)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->vehicleId = $vehicleId;
        $this->categoryId = $categoryId;
        $this->partId = $partId;
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

    public function categoryId(): string
    {
        return $this->categoryId;
    }

    public function partId(): string
    {
        return $this->partId;
    }
}
