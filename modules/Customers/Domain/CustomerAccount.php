<?php

declare(strict_types=1);

namespace Modules\Customers\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

/**
 * Storefront login for a Customer (separate from admin User).
 * Soft registration: passwordHash null + isActive false until password set.
 */
#[ORM\Entity]
#[ORM\Table(name: 'customer_accounts')]
#[ORM\UniqueConstraint(name: 'uniq_customer_accounts_customer', columns: ['customer_id'])]
#[ORM\UniqueConstraint(name: 'uniq_customer_accounts_email', columns: ['email'])]
class CustomerAccount
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'customer_id', length: 36)]
    private string $customerId;

    #[ORM\Column(length: 190)]
    private string $email;

    #[ORM\Column(name: 'password_hash', length: 255, nullable: true)]
    private ?string $passwordHash = null;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $active = false;

    #[ORM\Column(name: 'email_verified_at', nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(name: 'token_version', type: 'integer')]
    private int $tokenVersion = 1;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $customerId, string $email)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->customerId = $customerId;
        $this->email = strtolower(trim($email));
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function passwordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isSoft(): bool
    {
        return $this->passwordHash === null;
    }

    public function emailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function tokenVersion(): int
    {
        return $this->tokenVersion;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setEmail(string $email): void
    {
        $this->email = strtolower(trim($email));
        $this->touch();
    }

    public function setPasswordHash(?string $hash): void
    {
        $this->passwordHash = $hash;
        $this->touch();
    }

    public function activate(): void
    {
        $this->active = true;
        $this->touch();
    }

    public function deactivate(): void
    {
        $this->active = false;
        $this->touch();
    }

    public function markEmailVerified(?\DateTimeImmutable $at = null): void
    {
        $this->emailVerifiedAt = $at ?? new \DateTimeImmutable();
        $this->touch();
    }

    public function bumpTokenVersion(): void
    {
        $this->tokenVersion++;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
