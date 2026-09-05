<?php

declare(strict_types=1);

namespace Modules\Listings\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'brokers')]
#[ORM\Index(name: 'idx_brokers_email', columns: ['email'])]
#[ORM\Index(name: 'idx_brokers_active', columns: ['active'])]
class Broker
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'first_name', length: 120)]
    private string $firstName;

    #[ORM\Column(name: 'last_name', length: 120)]
    private string $lastName;

    #[ORM\Column(length: 190)]
    private string $email;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $position = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $skills = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'photo_url', length: 500, nullable: true)]
    private ?string $photoUrl = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(name: 'sort_order')]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $firstName, string $lastName, string $email)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->email = strtolower($email);
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): string { return $this->id; }
    public function firstName(): string { return $this->firstName; }
    public function lastName(): string { return $this->lastName; }
    public function fullName(): string { return trim($this->firstName . ' ' . $this->lastName); }
    public function email(): string { return $this->email; }
    public function phone(): ?string { return $this->phone; }
    public function position(): ?string { return $this->position; }
    public function skills(): ?string { return $this->skills; }
    public function description(): ?string { return $this->description; }
    public function photoUrl(): ?string { return $this->photoUrl; }
    public function active(): bool { return $this->active; }
    public function sortOrder(): int { return $this->sortOrder; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setFirstName(string $v): void { $this->firstName = $v; $this->touch(); }
    public function setLastName(string $v): void { $this->lastName = $v; $this->touch(); }
    public function setEmail(string $v): void { $this->email = strtolower($v); $this->touch(); }
    public function setPhone(?string $v): void { $this->phone = $v !== null && $v !== '' ? $v : null; $this->touch(); }
    public function setPosition(?string $v): void { $this->position = $v !== null && $v !== '' ? $v : null; $this->touch(); }
    public function setSkills(?string $v): void { $this->skills = $v !== null && $v !== '' ? $v : null; $this->touch(); }
    public function setDescription(?string $v): void { $this->description = $v !== null && $v !== '' ? $v : null; $this->touch(); }
    public function setPhotoUrl(?string $v): void { $this->photoUrl = $v !== null && $v !== '' ? $v : null; $this->touch(); }
    public function setActive(bool $v): void { $this->active = $v; $this->touch(); }
    public function setSortOrder(int $v): void { $this->sortOrder = $v; $this->touch(); }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
