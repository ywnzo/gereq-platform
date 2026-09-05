<?php

declare(strict_types=1);

namespace Modules\Fleet\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'fleet_vehicle_status_logs')]
#[ORM\Index(name: 'idx_fleet_status_logs_vehicle', columns: ['vehicle_id'])]
class VehicleStatusLog
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'vehicle_id', length: 36)]
    private string $vehicleId;

    #[ORM\Column(name: 'from_status', length: 32, nullable: true)]
    private ?string $fromStatus;

    #[ORM\Column(name: 'to_status', length: 32)]
    private string $toStatus;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(name: 'user_id', length: 36, nullable: true)]
    private ?string $userId = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $vehicleId, ?string $fromStatus, string $toStatus, ?string $reason = null, ?string $userId = null)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->vehicleId = $vehicleId;
        $this->fromStatus = $fromStatus;
        $this->toStatus = $toStatus;
        $this->reason = $reason !== null && $reason !== '' ? $reason : null;
        $this->userId = $userId !== null && $userId !== '' ? $userId : null;
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

    public function fromStatus(): ?string
    {
        return $this->fromStatus;
    }

    public function toStatus(): string
    {
        return $this->toStatus;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
