<?php

declare(strict_types=1);

namespace Gereq\Customers\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'customer_relations')]
#[ORM\Index(name: 'idx_customer_relations_from', columns: ['from_customer_id'])]
#[ORM\Index(name: 'idx_customer_relations_to', columns: ['to_customer_id'])]
class CustomerRelation
{
    public const TYPES = ['parent', 'child', 'partner', 'other'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'from_customer_id', length: 36)]
    private string $fromCustomerId;

    #[ORM\Column(name: 'to_customer_id', length: 36)]
    private string $toCustomerId;

    #[ORM\Column(length: 32)]
    private string $type = 'other';

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $fromCustomerId, string $toCustomerId, string $type = 'other')
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Neplatný typ vztahu.');
        }
        if ($fromCustomerId === $toCustomerId) {
            throw new \InvalidArgumentException('Vztah nemůže být sám na sebe.');
        }

        $this->id = Uuid::uuid7()->toString();
        $this->fromCustomerId = $fromCustomerId;
        $this->toCustomerId = $toCustomerId;
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function fromCustomerId(): string
    {
        return $this->fromCustomerId;
    }

    public function toCustomerId(): string
    {
        return $this->toCustomerId;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Neplatný typ vztahu.');
        }
        $this->type = $type;
    }
}
