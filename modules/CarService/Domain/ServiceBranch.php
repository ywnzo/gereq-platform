<?php

declare(strict_types=1);

namespace Modules\CarService\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'carservice_branches')]
#[ORM\Index(name: 'idx_carservice_branches_active', columns: ['is_active'])]
class ServiceBranch
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $zip = null;

    #[ORM\Column(length: 2)]
    private string $country = 'CZ';

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(name: 'employee_count', type: 'integer')]
    private int $employeeCount = 0;

    #[ORM\Column(name: 'logo_file_id', length: 36, nullable: true)]
    private ?string $logoFileId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = trim($name);
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

    public function street(): ?string
    {
        return $this->street;
    }

    public function city(): ?string
    {
        return $this->city;
    }

    public function zip(): ?string
    {
        return $this->zip;
    }

    public function country(): string
    {
        return $this->country;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function employeeCount(): int
    {
        return $this->employeeCount;
    }

    public function logoFileId(): ?string
    {
        return $this->logoFileId;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function addressLabel(): string
    {
        $parts = array_filter([$this->street, trim(($this->zip ?? '') . ' ' . ($this->city ?? '')), $this->country !== 'CZ' ? $this->country : null]);

        return implode(', ', $parts);
    }

    public function selectLabel(): string
    {
        $city = trim((string) $this->city);
        if ($city !== '' && strcasecmp($city, $this->name) !== 0) {
            return $this->name . ' (' . $city . ')';
        }

        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = trim($name);
        $this->touch();
    }

    public function setStreet(?string $street): void
    {
        $v = $street !== null ? trim($street) : '';
        $this->street = $v !== '' ? $v : null;
        $this->touch();
    }

    public function setCity(?string $city): void
    {
        $v = $city !== null ? trim($city) : '';
        $this->city = $v !== '' ? $v : null;
        $this->touch();
    }

    public function setZip(?string $zip): void
    {
        $v = $zip !== null ? trim($zip) : '';
        $this->zip = $v !== '' ? $v : null;
        $this->touch();
    }

    public function setCountry(string $country): void
    {
        $c = strtoupper(trim($country));
        $this->country = $c !== '' ? substr($c, 0, 2) : 'CZ';
        $this->touch();
    }

    public function setPhone(?string $phone): void
    {
        $v = $phone !== null ? trim($phone) : '';
        $this->phone = $v !== '' ? $v : null;
        $this->touch();
    }

    public function setEmail(?string $email): void
    {
        $v = $email !== null ? trim($email) : '';
        $this->email = $v !== '' ? $v : null;
        $this->touch();
    }

    public function setEmployeeCount(int $count): void
    {
        $this->employeeCount = max(0, $count);
        $this->touch();
    }

    public function setLogoFileId(?string $fileId): void
    {
        $v = $fileId !== null ? trim($fileId) : '';
        $this->logoFileId = $v !== '' ? $v : null;
        $this->touch();
    }

    public function setNote(?string $note): void
    {
        $v = $note !== null ? trim($note) : '';
        $this->note = $v !== '' ? $v : null;
        $this->touch();
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
