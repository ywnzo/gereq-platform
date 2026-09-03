<?php

declare(strict_types=1);

namespace Gereq\Notification;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'notifications')]
class DatabaseNotification
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'user_id', length: 36)]
    private string $userId;

    #[ORM\Column(length: 120)]
    private string $type;

    #[ORM\Column(type: 'json')]
    private array $data;

    #[ORM\Column(name: 'read_at', nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $userId, string $type, array $data)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->userId = $userId;
        $this->type = $type;
        $this->data = $data;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function data(): array
    {
        return $this->data;
    }

    public function readAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function markRead(): void
    {
        $this->readAt = new \DateTimeImmutable();
    }
}
