<?php

declare(strict_types=1);

namespace Modules\Events\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'kdeaco_event_contacts')]
#[ORM\Index(name: 'idx_kdeaco_event_contacts_created', columns: ['created_at'])]
class EventContact
{
    public const TOPICS = ['tip', 'data', 'other'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(length: 190)]
    private string $email;

    #[ORM\Column(length: 32)]
    private string $topic;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, string $email, string $topic, string $message)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = trim($name);
        $this->email = strtolower(trim($email));
        $this->topic = $topic !== '' ? $topic : 'other';
        $this->message = trim($message);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function topic(): string
    {
        return $this->topic;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
