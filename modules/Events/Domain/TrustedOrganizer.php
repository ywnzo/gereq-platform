<?php

declare(strict_types=1);

namespace Modules\Events\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'kdeaco_trusted_organizers')]
#[ORM\UniqueConstraint(name: 'uniq_kdeaco_trusted_organizer', columns: ['customer_id'])]
class TrustedOrganizer
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'customer_id', length: 36)]
    private string $customerId;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $customerId)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->customerId = $customerId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
