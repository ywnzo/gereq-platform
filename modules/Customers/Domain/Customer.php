<?php

declare(strict_types=1);

namespace Modules\Customers\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Resource\AdminField;
use Gereq\Admin\Resource\AdminResource;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'customers')]
#[AdminResource(
    name: 'customers',
    label: 'Zákazníci',
    permission: 'customers',
    icon: 'users',
    singular: 'Zákazník',
    controller: \Modules\Customers\Http\CustomerController::class,
)]
class Customer
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 180)]
    #[AdminField(type: 'text', label: 'Jméno', searchable: true, sortable: true, required: true)]
    private string $name;

    #[ORM\Column(length: 180)]
    #[AdminField(type: 'email', label: 'E-mail', searchable: true, sortable: true, required: true)]
    private string $email;

    #[ORM\Column(length: 40, nullable: true)]
    #[AdminField(type: 'text', label: 'Telefon', searchable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[AdminField(type: 'text', label: 'Firma', searchable: true, sortable: true)]
    private ?string $company = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[AdminField(type: 'textarea', label: 'Poznámka', showOnIndex: false)]
    private ?string $note = null;

    #[ORM\Column(length: 32)]
    #[AdminField(type: 'select', label: 'Stav', sortable: true, filterable: true)]
    private string $status = 'active';

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $email)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = $name;
        $this->email = strtolower($email);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function company(): ?string
    {
        return $this->company;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function status(): string
    {
        return $this->status;
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

    public function setEmail(string $email): void
    {
        $this->email = strtolower($email);
        $this->touch();
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone !== '' ? $phone : null;
        $this->touch();
    }

    public function setCompany(?string $company): void
    {
        $this->company = $company !== '' ? $company : null;
        $this->touch();
    }

    public function setNote(?string $note): void
    {
        $this->note = $note !== '' ? $note : null;
        $this->touch();
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'active' => 'Aktivní',
            'inactive' => 'Neaktivní',
            'lead' => 'Lead',
            default => $this->status,
        };
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $letters = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $letters !== '' ? $letters : 'Z';
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
