<?php

declare(strict_types=1);

namespace Gereq\Notifications\Channel;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(name: 'notifications_actor_idx', columns: ['actor_type', 'user_id'])]
class DatabaseNotification
{
    public const ACTOR_USER = 'user';
    public const ACTOR_CUSTOMER = 'customer';

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'user_id', length: 36)]
    private string $userId;

    /** Discriminator: admin User vs storefront CustomerAccount sharing user_id space. */
    #[ORM\Column(name: 'actor_type', length: 16, options: ['default' => 'user'])]
    private string $actorType;

    #[ORM\Column(length: 120)]
    private string $type;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $data;

    #[ORM\Column(name: 'read_at', nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $data */
    public function __construct(
        string $userId,
        string $type,
        array $data,
        string $actorType = self::ACTOR_USER,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->userId = $userId;
        $this->actorType = $actorType === self::ACTOR_CUSTOMER
            ? self::ACTOR_CUSTOMER
            : self::ACTOR_USER;
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

    public function actorType(): string
    {
        return $this->actorType;
    }

    public function type(): string
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
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
