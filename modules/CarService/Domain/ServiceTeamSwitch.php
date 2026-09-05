<?php

declare(strict_types=1);

namespace Modules\CarService\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'carservice_team_switches')]
#[ORM\Index(name: 'idx_carservice_team_switches_order', columns: ['order_id'])]
class ServiceTeamSwitch
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'order_id', length: 36)]
    private string $orderId;

    #[ORM\Column(name: 'from_team', length: 32, nullable: true)]
    private ?string $fromTeam;

    #[ORM\Column(name: 'to_team', length: 32)]
    private string $toTeam;

    #[ORM\Column(name: 'user_id', length: 36, nullable: true)]
    private ?string $userId = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $orderId, ?string $fromTeam, string $toTeam, ?string $userId = null)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->orderId = $orderId;
        $this->fromTeam = $fromTeam;
        $this->toTeam = $toTeam;
        $this->userId = $userId !== null && $userId !== '' ? $userId : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function fromTeam(): ?string
    {
        return $this->fromTeam;
    }

    public function toTeam(): string
    {
        return $this->toTeam;
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
