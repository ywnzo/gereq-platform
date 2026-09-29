<?php

declare(strict_types=1);

namespace Gereq\Customers\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'customer_addresses')]
#[ORM\Index(name: 'idx_customer_addresses_customer', columns: ['customer_id'])]
class CustomerAddress
{
    public const TYPES = ['billing', 'shipping', 'other'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'customer_id', length: 36)]
    private string $customerId;

    #[ORM\Column(length: 32)]
    private string $type = 'other';

    #[ORM\Column(length: 190)]
    private string $line1;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $line2 = null;

    #[ORM\Column(length: 120)]
    private string $city;

    #[ORM\Column(length: 32)]
    private string $zip;

    #[ORM\Column(length: 2)]
    private string $country;

    #[ORM\Column(name: 'is_primary', type: 'boolean')]
    private bool $isPrimary = false;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $customerId, string $type, string $line1, string $city, string $zip, string $country)
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Neplatný typ adresy.');
        }

        $this->id = Uuid::uuid7()->toString();
        $this->customerId = $customerId;
        $this->type = $type;
        $this->line1 = $line1;
        $this->city = $city;
        $this->zip = $zip;
        $this->country = strtoupper($country);
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

    public function type(): string
    {
        return $this->type;
    }

    public function line1(): string
    {
        return $this->line1;
    }

    public function line2(): ?string
    {
        return $this->line2;
    }

    public function city(): string
    {
        return $this->city;
    }

    public function zip(): string
    {
        return $this->zip;
    }

    public function country(): string
    {
        return $this->country;
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

    public function setType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Neplatný typ adresy.');
        }
        $this->type = $type;
        $this->touch();
    }

    public function setLine1(string $line1): void
    {
        $this->line1 = $line1;
        $this->touch();
    }

    public function setLine2(?string $line2): void
    {
        $this->line2 = $line2 !== '' && $line2 !== null ? $line2 : null;
        $this->touch();
    }

    public function setCity(string $city): void
    {
        $this->city = $city;
        $this->touch();
    }

    public function setZip(string $zip): void
    {
        $this->zip = $zip;
        $this->touch();
    }

    public function setCountry(string $country): void
    {
        $this->country = strtoupper($country);
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
