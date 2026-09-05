<?php

declare(strict_types=1);

namespace Gereq\User\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 180)]
    private string $name;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash;

    #[ORM\Column(length: 32)]
    private string $status = 'active';

    /** Preferovaný jazyk uživatele; null = výchozí jazyk aplikace. */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $locale = null;

    #[ORM\Column(name: 'totp_secret', length: 64, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(name: 'totp_enabled')]
    private bool $totpEnabled = false;

    /** @var list<string>|null */
    #[ORM\Column(name: 'recovery_codes', type: 'json', nullable: true)]
    private ?array $recoveryCodes = null;

    #[ORM\Column(name: 'oauth_provider', length: 40, nullable: true)]
    private ?string $oauthProvider = null;

    #[ORM\Column(name: 'oauth_id', length: 180, nullable: true)]
    private ?string $oauthId = null;

    #[ORM\Column(name: 'passkey_enabled')]
    private bool $passkeyEnabled = false;

    #[ORM\Column(name: 'last_login_at', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, Role> */
    #[ORM\ManyToMany(targetEntity: Role::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'user_roles')]
    private Collection $roles;

    public function __construct(string $name, string $email, string $passwordHash)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = $name;
        $this->email = strtolower($email);
        $this->passwordHash = $passwordHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->roles = new ArrayCollection();
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

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function locale(): ?string
    {
        return $this->locale;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function totpEnabled(): bool
    {
        return $this->totpEnabled;
    }

    public function totpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function lastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
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

    public function setPasswordHash(string $hash): void
    {
        $this->passwordHash = $hash;
        $this->touch();
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function setLocale(?string $locale): void
    {
        $this->locale = $locale !== null && $locale !== '' ? $locale : null;
        $this->touch();
    }

    public function markLoggedIn(): void
    {
        $this->lastLoginAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function enableTotp(string $secret, array $recoveryCodes): void
    {
        $this->totpSecret = $secret;
        $this->totpEnabled = true;
        $this->recoveryCodes = $recoveryCodes;
        $this->touch();
    }

    /** @return Collection<int, Role> */
    public function roles(): Collection
    {
        return $this->roles;
    }

    public function assignRole(Role $role): void
    {
        if (!$this->roles->contains($role)) {
            $this->roles->add($role);
        }
    }

    public function removeRole(Role $role): void
    {
        $this->roles->removeElement($role);
    }

    public function hasRole(string $name): bool
    {
        foreach ($this->roles as $role) {
            if ($role->name() === $name) {
                return true;
            }
        }

        return false;
    }

    public function isAdministrator(): bool
    {
        return $this->hasRole('administrator');
    }

    public function can(string $permission): bool
    {
        if ($this->isAdministrator()) {
            return true;
        }

        foreach ($this->roles as $role) {
            if ($role->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function permissionNames(): array
    {
        if ($this->isAdministrator()) {
            return ['*'];
        }

        $names = [];
        foreach ($this->roles as $role) {
            foreach ($role->permissions() as $permission) {
                $names[$permission->name()] = true;
            }
        }

        return array_keys($names);
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $letters = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $letters !== '' ? $letters : 'G';
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
