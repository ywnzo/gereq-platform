<?php

declare(strict_types=1);

namespace Modules\Fleet\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'fleet_vehicle_models')]
#[ORM\Index(name: 'idx_fleet_models_brand', columns: ['brand_id'])]
#[ORM\UniqueConstraint(name: 'uniq_fleet_models_brand_name', columns: ['brand_id', 'name'])]
class VehicleModel
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'brand_id', length: 36)]
    private string $brandId;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $brandId, string $name)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->brandId = $brandId;
        $this->name = trim($name);
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

    public function name(): string
    {
        return $this->name;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setName(string $name): void
    {
        $this->name = trim($name);
    }
}
