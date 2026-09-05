<?php

declare(strict_types=1);

namespace Modules\Customers\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'customer_contacts')]
#[ORM\Index(name: 'idx_customer_contacts_customer', columns: ['customer_id'])]
class CustomerContact
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'customer_id', length: 36)]
    private string $customerId;

    #[ORM\Column(length: 180)]
    private string $name;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $role = null;

    #[ORM\Column(name: 'is_primary', type: 'boolean')]
    private bool $isPrimary = false;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $customerId, string $name)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->customerId = $customerId;
        $this->name = $name;
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

    public function name(): string
    {
        return $this->name;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function role(): ?string
    {
        return $this->role;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email !== null && $email !== '' ? strtolower($email) : null;
        $this->touch();
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone !== null && $phone !== '' ? $phone : null;
        $this->touch();
    }

    public function setRole(?string $role): void
    {
        $this->role = $role !== null && $role !== '' ? $role : null;
        $this->touch();
    }

    public function setIsPrimary(bool $isPrimary): void
    {
        $this->isPrimary = $isPrimary;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
