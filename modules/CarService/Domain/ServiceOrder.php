<?php

declare(strict_types=1);

namespace Modules\CarService\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'carservice_orders')]
#[ORM\Index(name: 'idx_carservice_orders_vehicle', columns: ['vehicle_id'])]
#[ORM\Index(name: 'idx_carservice_orders_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_carservice_orders_status', columns: ['status'])]
#[ORM\Index(name: 'idx_carservice_orders_team', columns: ['team'])]
#[ORM\UniqueConstraint(name: 'uniq_carservice_orders_tracker', columns: ['tracker_token'])]
class ServiceOrder
{
    public const TYPES = ['internal_fleet', 'external_customer', 'linked'];
    public const STATUSES = ['intake', 'in_progress', 'waiting', 'ready', 'done', 'cancelled'];
    public const TEAMS = ['body_shop', 'paint_shop'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 32)]
    private string $type = 'external_customer';

    #[ORM\Column(length: 32)]
    private string $status = 'intake';

    #[ORM\Column(length: 32)]
    private string $team = 'body_shop';

    #[ORM\Column(name: 'vehicle_id', length: 36)]
    private string $vehicleId;

    #[ORM\Column(name: 'customer_id', length: 36, nullable: true)]
    private ?string $customerId = null;

    #[ORM\Column(name: 'branch_id', length: 36, nullable: true)]
    private ?string $branchId = null;

    #[ORM\Column(name: 'tracker_token', length: 64)]
    private string $trackerToken;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'finished_at', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'intake_at', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $intakeAt = null;

    #[ORM\Column(name: 'handover_at', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $handoverAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $vehicleId, string $type = 'external_customer')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->vehicleId = $vehicleId;
        $this->setType($type);
        $this->trackerToken = bin2hex(random_bytes(16));
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function team(): string
    {
        return $this->team;
    }

    public function vehicleId(): string
    {
        return $this->vehicleId;
    }

    public function customerId(): ?string
    {
        return $this->customerId;
    }

    public function branchId(): ?string
    {
        return $this->branchId;
    }

    public function trackerToken(): string
    {
        return $this->trackerToken;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function intakeAt(): ?\DateTimeImmutable
    {
        return $this->intakeAt;
    }

    public function handoverAt(): ?\DateTimeImmutable
    {
        return $this->handoverAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isOpen(): bool
    {
        return !in_array($this->status, ['done', 'cancelled'], true);
    }

    public function statusLabel(): string
    {
        return 'carservice.status.' . $this->status;
    }

    public function teamLabel(): string
    {
        return 'carservice.team.' . $this->team;
    }

    public function typeLabel(): string
    {
        return 'carservice.type.' . $this->type;
    }

    public function setType(string $v): void
    {
        if (!in_array($v, self::TYPES, true)) {
            throw new \InvalidArgumentException('carservice.error.invalid_type');
        }
        $this->type = $v;
        $this->touch();
    }

    public function setStatus(string $v): void
    {
        if (!in_array($v, self::STATUSES, true)) {
            throw new \InvalidArgumentException('carservice.error.invalid_status');
        }
        $this->status = $v;
        if ($v === 'done' && $this->finishedAt === null) {
            $this->finishedAt = new \DateTimeImmutable();
        }
        $this->touch();
    }

    public function setTeam(string $v): void
    {
        if (!in_array($v, self::TEAMS, true)) {
            throw new \InvalidArgumentException('carservice.error.invalid_team');
        }
        $this->team = $v;
        $this->touch();
    }

    public function setVehicleId(string $v): void
    {
        $this->vehicleId = $v;
        $this->touch();
    }

    public function setCustomerId(?string $v): void
    {
        $this->customerId = $v !== null && $v !== '' ? $v : null;
        $this->touch();
    }

    public function setBranchId(?string $v): void
    {
        $this->branchId = $v !== null && $v !== '' ? $v : null;
        $this->touch();
    }

    public function setNote(?string $v): void
    {
        $this->note = $v !== null && $v !== '' ? $v : null;
        $this->touch();
    }

    public function setIntakeAt(?\DateTimeImmutable $v): void
    {
        $this->intakeAt = $v;
        $this->touch();
    }

    public function setHandoverAt(?\DateTimeImmutable $v): void
    {
        $this->handoverAt = $v;
        $this->touch();
    }

    public function regenerateTrackerToken(): void
    {
        $this->trackerToken = bin2hex(random_bytes(16));
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
