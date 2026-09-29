<?php

declare(strict_types=1);

namespace Gereq\Customers\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'customer_notes')]
#[ORM\Index(name: 'idx_customer_notes_customer', columns: ['customer_id'])]
class CustomerNote
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'customer_id', length: 36)]
    private string $customerId;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(name: 'created_by_user_id', length: 36, nullable: true)]
    private ?string $createdByUserId = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $customerId, string $body, ?string $createdByUserId = null)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->customerId = $customerId;
        $this->body = $body;
        $this->createdByUserId = $createdByUserId !== null && $createdByUserId !== '' ? $createdByUserId : null;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function createdByUserId(): ?string
    {
        return $this->createdByUserId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
