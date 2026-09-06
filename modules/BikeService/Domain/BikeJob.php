<?php

declare(strict_types=1);

namespace Modules\BikeService\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'bikeservice_jobs')]
#[ORM\Index(name: 'idx_bikeservice_jobs_order', columns: ['order_id'])]
class BikeJob
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'order_id', length: 36)]
    private string $orderId;

    #[ORM\Column(length: 64)]
    private string $type = 'work';

    #[ORM\Column(name: 'employee_user_id', length: 36, nullable: true)]
    private ?string $employeeUserId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'started_at')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'finished_at', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(string $orderId, string $type = 'work')
    {
        $this->id = Uuid::uuid7()->toString();
        $this->orderId = $orderId;
        $this->type = $type !== '' ? $type : 'work';
        $this->startedAt = new \DateTimeImmutable();
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

    public function employeeUserId(): ?string
    {
        return $this->employeeUserId;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function startedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function isOpen(): bool
    {
        return $this->finishedAt === null;
    }

    public function setEmployeeUserId(?string $userId): void
    {
        $this->employeeUserId = $userId !== null && $userId !== '' ? $userId : null;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note !== null && trim($note) !== '' ? trim($note) : null;
    }

    public function complete(): void
    {
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function durationMinutes(): ?int
    {
        if ($this->finishedAt === null) {
            return null;
        }

        return (int) max(0, (int) (($this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp()) / 60));
    }
}
