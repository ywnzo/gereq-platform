<?php

declare(strict_types=1);

namespace Modules\Listings\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'brokers')]
#[ORM\Index(name: 'idx_brokers_email', columns: ['email'])]
#[ORM\Index(name: 'idx_brokers_active', columns: ['active'])]
#[FormEntity('listings.brokers.save')]
#[FormGenerator(
    exclude: ['id', 'createdAt', 'updatedAt'],
    form: 'listings.brokers.save',
    cols: 2,
)]
class Broker
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'first_name', length: 120)]
    #[FormField(required: true, name: 'first_name', label: 'listings.brokers.field.first_name', order: 10)]
    private string $firstName;

    #[ORM\Column(name: 'last_name', length: 120)]
    #[FormField(required: true, name: 'last_name', label: 'listings.brokers.field.last_name', order: 20)]
    private string $lastName;

    #[ORM\Column(length: 190)]
    #[FormField(required: true, label: 'listings.brokers.field.email', type: 'email', order: 30)]
    private string $email;

    #[ORM\Column(length: 64, nullable: true)]
    #[FormField(label: 'listings.brokers.field.phone', order: 40)]
    private ?string $phone = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[FormField(label: 'listings.brokers.field.position', order: 50)]
    private ?string $position = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[FormField(label: 'listings.brokers.field.skills', type: 'textarea', extra: 'field--full', order: 80)]
    private ?string $skills = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[FormField(label: 'listings.brokers.field.description', type: 'editor', extra: 'field--full', order: 90)]
    private ?string $description = null;

    #[ORM\Column(name: 'photo_url', length: 500, nullable: true)]
    #[FormField(label: 'listings.brokers.field.photo_url', name: 'photo_url', order: 60)]
    private ?string $photoUrl = null;

    #[ORM\Column]
    #[FormField(label: 'listings.brokers.field.active', type: 'switch', order: 100)]
    private bool $active = true;

    #[ORM\Column(name: 'sort_order')]
    #[FormField(label: 'listings.brokers.field.sort_order', type: 'number', name: 'sort_order', order: 70)]
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
