<?php

declare(strict_types=1);

namespace Gereq\Audit;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'audit_logs')]
class AuditLog
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'user_id', length: 36, nullable: true)]
    private ?string $userId = null;

    #[ORM\Column(name: 'user_name', length: 180, nullable: true)]
    private ?string $userName = null;

    #[ORM\Column(length: 80)]
    private string $action;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $resource = null;

    #[ORM\Column(name: 'resource_id', length: 36, nullable: true)]
    private ?string $resourceId = null;

    #[ORM\Column(name: 'old_values', type: 'json', nullable: true)]
    private ?array $oldValues = null;

    #[ORM\Column(name: 'new_values', type: 'json', nullable: true)]
    private ?array $newValues = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(name: 'user_agent', length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $action,
        ?string $userId,
        ?string $userName,
        ?string $resource,
        ?string $resourceId,
        ?array $oldValues,
        ?array $newValues,
        ?string $ip,
        ?string $userAgent,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->action = $action;
        $this->userId = $userId;
        $this->userName = $userName;
        $this->resource = $resource;
        $this->resourceId = $resourceId;
        $this->oldValues = $oldValues;
        $this->newValues = $newValues;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userName(): ?string
    {
        return $this->userName;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function resource(): ?string
    {
        return $this->resource;
    }

    public function resourceId(): ?string
    {
        return $this->resourceId;
    }

    public function oldValues(): ?array
    {
        return $this->oldValues;
    }

    public function newValues(): ?array
    {
        return $this->newValues;
    }

    public function ip(): ?string
    {
        return $this->ip;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function summary(): string
    {
        $who = $this->userName ?? 'Systém';
        $what = $this->action;

        return trim($who . ' · ' . $what);
    }
}
