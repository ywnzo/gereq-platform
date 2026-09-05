<?php
declare(strict_types=1);
namespace Modules\Projects\Domain;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'project_tasks')]
#[ORM\Index(name: 'idx_project_tasks_project', columns: ['project_id'])]
class ProjectTask
{
    public const STATUSES = ['todo', 'doing', 'done'];
    #[ORM\Id] #[ORM\Column(length: 36)] private string $id;
    #[ORM\Column(name: 'project_id', length: 36)] private string $projectId;
    #[ORM\Column(length: 190)] private string $title;
    #[ORM\Column(length: 32)] private string $status = 'todo';
    #[ORM\Column(name: 'assignee_user_id', length: 36, nullable: true)] private ?string $assigneeUserId = null;
    #[ORM\Column(name: 'due_at', type: 'date_immutable', nullable: true)] private ?\DateTimeImmutable $dueAt = null;
    #[ORM\Column(name: 'created_at')] private \DateTimeImmutable $createdAt;
    #[ORM\Column(name: 'updated_at')] private \DateTimeImmutable $updatedAt;

    public function __construct(string $projectId, string $title) {
        $this->id = Uuid::uuid7()->toString();
        $this->projectId = $projectId;
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }
    public function id(): string { return $this->id; }
    public function projectId(): string { return $this->projectId; }
    public function title(): string { return $this->title; }
    public function status(): string { return $this->status; }
    public function assigneeUserId(): ?string { return $this->assigneeUserId; }
    public function dueAt(): ?\DateTimeImmutable { return $this->dueAt; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setTitle(string $v): void { $this->title = $v; $this->touch(); }
    public function setStatus(string $v): void {
        if (!in_array($v, self::STATUSES, true)) throw new \InvalidArgumentException('projects.error.invalid_task_status');
        $this->status = $v; $this->touch();
    }
    public function setAssigneeUserId(?string $v): void { $this->assigneeUserId = $v !== '' ? $v : null; $this->touch(); }
    public function setDueAt(?\DateTimeImmutable $v): void { $this->dueAt = $v; $this->touch(); }
    public function statusLabel(): string { return 'projects.task.status.' . $this->status; }
    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}