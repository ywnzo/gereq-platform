<?php

declare(strict_types=1);

namespace Modules\Customers\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
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
#[FormEntity('customers.save')]
#[FormGenerator(
    exclude: ['id', 'isCompany', 'company', 'ico', 'dic', 'companyAddress', 'createdAt', 'updatedAt'],
    form: 'customers.save',
    cols: 2,
)]
class Customer
{
    public const STATUSES = ['active', 'lead', 'inactive', 'archived'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 180)]
    #[AdminField(type: 'text', label: 'Jméno', searchable: true, sortable: true, required: true)]
    #[FormField(required: true, label: 'customers.field.name', group: 'profile', attrs: 'autocomplete="name"', order: 10)]
    private string $name;

    #[ORM\Column(length: 180)]
    #[AdminField(type: 'email', label: 'E-mail', searchable: true, sortable: true, required: true)]
    #[FormField(required: true, label: 'customers.field.email', type: 'email', group: 'profile', attrs: 'autocomplete="email"', order: 20)]
    private string $email;

    #[ORM\Column(length: 40, nullable: true)]
    #[AdminField(type: 'text', label: 'Telefon', searchable: true)]
    #[FormField(label: 'customers.field.phone', type: 'tel', group: 'profile', attrs: 'autocomplete="tel"', options: ['placeholder' => '+420 777 123 456'], order: 30)]
    private ?string $phone = null;

    /** Osoba vs. firma — ruční Twig (switch + ARES). */
    #[ORM\Column(name: 'is_company', type: 'boolean', options: ['default' => false])]
    #[AdminField(type: 'boolean', label: 'Firma', sortable: true, filterable: true)]
    private bool $isCompany = false;

    /** Obchodní jméno / zaměstnavatel — ARES widget. */
    #[ORM\Column(length: 180, nullable: true)]
    #[AdminField(type: 'text', label: 'Firma', searchable: true, sortable: true)]
    private ?string $company = null;

    #[ORM\Column(length: 12, nullable: true)]
    #[AdminField(type: 'text', label: 'IČ', searchable: true)]
    private ?string $ico = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[AdminField(type: 'text', label: 'DIČ', searchable: true)]
    private ?string $dic = null;

    #[ORM\Column(name: 'company_address', length: 255, nullable: true)]
    private ?string $companyAddress = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[AdminField(type: 'textarea', label: 'Poznámka', showOnIndex: false)]
    #[FormField(label: 'customers.field.note', type: 'editor', group: 'note', options: ['placeholder' => 'customers.note.placeholder'], order: 10)]
    private ?string $note = null;

    /** Status picker — ruční Twig. */
    #[ORM\Column(length: 32)]
    #[AdminField(type: 'select', label: 'Stav', sortable: true, filterable: true)]
    #[FormField(required: true)]
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

    public function isCompany(): bool
    {
        return $this->isCompany;
    }

    public function company(): ?string
    {
        return $this->company;
    }

    public function ico(): ?string
    {
        return $this->ico;
    }

    public function dic(): ?string
    {
        return $this->dic;
    }

    public function companyAddress(): ?string
    {
        return $this->companyAddress;
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

    public function setIsCompany(bool $isCompany): void
    {
        $this->isCompany = $isCompany;
        if (!$isCompany) {
            $this->ico = null;
            $this->dic = null;
            $this->companyAddress = null;
        }
        $this->touch();
    }

    public function setCompany(?string $company): void
    {
        $this->company = $company !== '' ? $company : null;
        $this->touch();
    }

    public function setIco(?string $ico): void
    {
        $ico = $ico !== null ? preg_replace('/\s+/', '', $ico) : null;
        $this->ico = $ico !== null && $ico !== '' ? $ico : null;
        $this->touch();
    }

    public function setDic(?string $dic): void
    {
        $dic = $dic !== null ? preg_replace('/\s+/', '', $dic) : null;
        $this->dic = $dic !== null && $dic !== '' ? $dic : null;
        $this->touch();
    }

    public function setCompanyAddress(?string $companyAddress): void
    {
        $this->companyAddress = $companyAddress !== null && $companyAddress !== '' ? $companyAddress : null;
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

    public function archive(): void
    {
        $this->status = 'archived';
        $this->touch();
    }

    public function restore(string $status = 'active'): void
    {
        if (!in_array($status, ['active', 'inactive', 'lead'], true)) {
            $status = 'active';
        }
        $this->status = $status;
        $this->touch();
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'active' => 'customers.status.active',
            'inactive' => 'customers.status.inactive',
            'lead' => 'customers.status.lead',
            'archived' => 'customers.status.archived',
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
