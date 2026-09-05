<?php
declare(strict_types=1);
namespace Modules\Projects\Domain;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'projects')]
#[ORM\Index(name: 'idx_projects_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_projects_status', columns: ['status'])]
class Project
{
    public const STATUSES = ['planning', 'active', 'done', 'cancelled'];
    #[ORM\Id] #[ORM\Column(length: 36)] private string $id;
    #[ORM\Column(name: 'customer_id', length: 36)] private string $customerId;
    #[ORM\Column(length: 190)] private string $name;
    #[ORM\Column(length: 32)] private string $status = 'planning';
    #[ORM\Column(name: 'start_at', type: 'date_immutable', nullable: true)] private ?\DateTimeImmutable $startAt = null;
    #[ORM\Column(name: 'due_at', type: 'date_immutable', nullable: true)] private ?\DateTimeImmutable $dueAt = null;
    #[ORM\Column(name: 'owner_user_id', length: 36, nullable: true)] private ?string $ownerUserId = null;
    #[ORM\Column(name: 'deal_id', length: 36, nullable: true)] private ?string $dealId = null;
    #[ORM\Column(name: 'created_at')] private \DateTimeImmutable $createdAt;
    #[ORM\Column(name: 'updated_at')] private \DateTimeImmutable $updatedAt;

    public function __construct(string $customerId, string $name) {
        $this->id = Uuid::uuid7()->toString();
        $this->customerId = $customerId;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }
    public function id(): string { return $this->id; }
    public function customerId(): string { return $this->customerId; }
    public function name(): string { return $this->name; }
    public function status(): string { return $this->status; }
    public function startAt(): ?\DateTimeImmutable { return $this->startAt; }
    public function dueAt(): ?\DateTimeImmutable { return $this->dueAt; }
    public function ownerUserId(): ?string { return $this->ownerUserId; }
    public function dealId(): ?string { return $this->dealId; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setCustomerId(string $v): void { $this->customerId = $v; $this->touch(); }
    public function setName(string $v): void { $this->name = $v; $this->touch(); }
    public function setStatus(string $v): void {
        if (!in_array($v, self::STATUSES, true)) throw new \InvalidArgumentException('projects.error.invalid_status');
        $this->status = $v; $this->touch();
    }
    public function setStartAt(?\DateTimeImmutable $v): void { $this->startAt = $v; $this->touch(); }
    public function setDueAt(?\DateTimeImmutable $v): void { $this->dueAt = $v; $this->touch(); }
    public function setOwnerUserId(?string $v): void { $this->ownerUserId = $v !== '' ? $v : null; $this->touch(); }
    public function setDealId(?string $v): void { $this->dealId = $v !== '' ? $v : null; $this->touch(); }
    public function statusLabel(): string { return 'projects.status.' . $this->status; }
    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}