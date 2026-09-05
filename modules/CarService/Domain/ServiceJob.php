<?php

declare(strict_types=1);

namespace Modules\CarService\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'carservice_jobs')]
#[ORM\Index(name: 'idx_carservice_jobs_order', columns: ['order_id'])]
class ServiceJob
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'order_id', length: 36)]
    private string $orderId;

    #[ORM\Column(length: 64)]
    private string $type = 'work';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'employee_user_id', length: 36, nullable: true)]
    private ?string $employeeUserId = null;

    #[ORM\Column(name: 'started_at')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'ended_at', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $orderId, string $type = 'work', ?string $employeeUserId = null)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->orderId = $orderId;
        $this->type = $type !== '' ? $type : 'work';
        $this->employeeUserId = $employeeUserId !== null && $employeeUserId !== '' ? $employeeUserId : null;
        $this->startedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function employeeUserId(): ?string
    {
        return $this->employeeUserId;
    }

    public function startedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function endedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isRunning(): bool
    {
        return $this->endedAt === null;
    }

    public function durationSeconds(): ?int
    {
        $end = $this->endedAt ?? new \DateTimeImmutable();

        return max(0, $end->getTimestamp() - $this->startedAt->getTimestamp());
    }

    public function setType(string $v): void
    {
        $this->type = $v !== '' ? $v : 'work';
        $this->touch();
    }

    public function setNote(?string $v): void
    {
        $this->note = $v !== null && $v !== '' ? $v : null;
        $this->touch();
    }

    public function setEmployeeUserId(?string $v): void
    {
        $this->employeeUserId = $v !== null && $v !== '' ? $v : null;
        $this->touch();
    }

    public function complete(?\DateTimeImmutable $at = null): void
    {
        if ($this->endedAt !== null) {
            return;
        }
        $this->endedAt = $at ?? new \DateTimeImmutable();
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
