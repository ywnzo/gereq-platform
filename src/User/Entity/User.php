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

    #[ORM\Column(name: 'first_name', length: 120, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(name: 'last_name', length: 120, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'birth_date', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $birthDate = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(name: 'avatar_file_id', length: 36, nullable: true)]
    private ?string $avatarFileId = null;

    #[ORM\Column(name: 'banner_file_id', length: 36, nullable: true)]
    private ?string $bannerFileId = null;

    #[ORM\Column(name: 'billing_street', length: 190, nullable: true)]
    private ?string $billingStreet = null;

    #[ORM\Column(name: 'billing_city', length: 120, nullable: true)]
    private ?string $billingCity = null;

    #[ORM\Column(name: 'billing_zip', length: 32, nullable: true)]
    private ?string $billingZip = null;

    #[ORM\Column(name: 'billing_country', length: 2, nullable: true)]
    private ?string $billingCountry = null;

    #[ORM\Column(name: 'shipping_street', length: 190, nullable: true)]
    private ?string $shippingStreet = null;

    #[ORM\Column(name: 'shipping_city', length: 120, nullable: true)]
    private ?string $shippingCity = null;

    #[ORM\Column(name: 'shipping_zip', length: 32, nullable: true)]
    private ?string $shippingZip = null;

    #[ORM\Column(name: 'shipping_country', length: 2, nullable: true)]
    private ?string $shippingCountry = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash;

    #[ORM\Column(length: 32)]
    private string $status = 'active';

    /** User preferred locale; null = application default locale. */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $locale = null;

    /**
     * Per-account admin UI state (nav sections, module expansions, …).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'ui_preferences', type: 'json', nullable: true)]
    private ?array $uiPreferences = null;

    #[ORM\Column(name: 'totp_secret', length: 255, nullable: true)]
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
        $this->splitNameIntoParts($name);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function firstName(): ?string
    {
        return $this->firstName;
    }

    public function lastName(): ?string
    {
        return $this->lastName;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function birthDate(): ?\DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function age(): ?int
    {
        if ($this->birthDate === null) {
            return null;
        }

        return $this->birthDate->diff(new \DateTimeImmutable('today'))->y;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function avatarFileId(): ?string
    {
        return $this->avatarFileId;
    }

    public function bannerFileId(): ?string
    {
        return $this->bannerFileId;
    }

    public function billingStreet(): ?string
    {
        return $this->billingStreet;
    }

    public function billingCity(): ?string
    {
        return $this->billingCity;
    }

    public function billingZip(): ?string
    {
        return $this->billingZip;
    }

    public function billingCountry(): ?string
    {
        return $this->billingCountry;
    }

    public function shippingStreet(): ?string
    {
        return $this->shippingStreet;
    }

    public function shippingCity(): ?string
    {
        return $this->shippingCity;
    }

    public function shippingZip(): ?string
    {
        return $this->shippingZip;
    }

    public function shippingCountry(): ?string
    {
        return $this->shippingCountry;
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

    public function passkeyEnabled(): bool
    {
        return $this->passkeyEnabled;
    }

    public function oauthProvider(): ?string
    {
        return $this->oauthProvider;
    }

    public function oauthId(): ?string
    {
        return $this->oauthId;
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
        $this->name = trim($name);
        $this->splitNameIntoParts($this->name);
        $this->touch();
    }

    public function setFirstName(?string $firstName): void
    {
        $this->firstName = self::nullable($firstName);
        $this->syncDisplayName();
    }

    public function setLastName(?string $lastName): void
    {
        $this->lastName = self::nullable($lastName);
        $this->syncDisplayName();
    }

    public function setDescription(?string $description): void
    {
        $this->description = self::nullable($description);
        $this->touch();
    }

    public function setBirthDate(?\DateTimeImmutable $birthDate): void
    {
        $this->birthDate = $birthDate;
        $this->touch();
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = self::nullable($phone);
        $this->touch();
    }

    public function setAvatarFileId(?string $avatarFileId): void
    {
        $this->avatarFileId = self::nullable($avatarFileId);
        $this->touch();
    }

    public function setBannerFileId(?string $bannerFileId): void
    {
        $this->bannerFileId = self::nullable($bannerFileId);
        $this->touch();
    }

    public function setBillingAddress(?string $street, ?string $city, ?string $zip, ?string $country): void
    {
        $this->billingStreet = self::nullable($street);
        $this->billingCity = self::nullable($city);
        $this->billingZip = self::nullable($zip);
        $country = self::nullable($country);
        $this->billingCountry = $country !== null ? strtoupper($country) : null;
        $this->touch();
    }

    public function setShippingAddress(?string $street, ?string $city, ?string $zip, ?string $country): void
    {
        $this->shippingStreet = self::nullable($street);
        $this->shippingCity = self::nullable($city);
        $this->shippingZip = self::nullable($zip);
        $country = self::nullable($country);
        $this->shippingCountry = $country !== null ? strtoupper($country) : null;
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

    /**
     * @return array<string, mixed>
     */
    public function uiPreferences(): array
    {
        return is_array($this->uiPreferences) ? $this->uiPreferences : [];
    }

    /**
     * Deep-merge allowed UI preference keys into the stored document.
     *
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function mergeUiPreferences(array $patch): array
    {
        $current = $this->uiPreferences();
        foreach (['nav_groups', 'nav_trees'] as $mapKey) {
            if (!isset($patch[$mapKey]) || !is_array($patch[$mapKey])) {
                continue;
            }
            $base = isset($current[$mapKey]) && is_array($current[$mapKey]) ? $current[$mapKey] : [];
            $changed = false;
            foreach ($patch[$mapKey] as $id => $open) {
                if (!is_string($id) || $id === '' || !is_bool($open)) {
                    continue;
                }
                $base[$id] = $open;
                $changed = true;
            }
            if ($changed) {
                $current[$mapKey] = $base;
            }
        }
        if (isset($patch['modules_expanded']) && is_array($patch['modules_expanded'])) {
            $ids = [];
            foreach ($patch['modules_expanded'] as $id) {
                if (is_string($id) && $id !== '') {
                    $ids[$id] = true;
                }
            }
            $current['modules_expanded'] = array_keys($ids);
        }
        $this->uiPreferences = $current;
        $this->touch();

        return $current;
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
        $this->recoveryCodes = array_values($recoveryCodes);
        $this->touch();
    }

    public function disableTotp(): void
    {
        $this->totpSecret = null;
        $this->totpEnabled = false;
        $this->recoveryCodes = null;
        $this->touch();
    }

    /** @return list<string>|null Hashed recovery codes. */
    public function recoveryCodes(): ?array
    {
        return $this->recoveryCodes;
    }

    /** @param list<string> $hashedCodes */
    public function setRecoveryCodes(array $hashedCodes): void
    {
        $this->recoveryCodes = array_values($hashedCodes);
        $this->touch();
    }

    public function consumeRecoveryCode(string $plain): bool
    {
        if ($this->recoveryCodes === null || $this->recoveryCodes === []) {
            return false;
        }

        $plain = strtoupper(trim($plain));
        foreach ($this->recoveryCodes as $index => $hash) {
            if (is_string($hash) && password_verify($plain, $hash)) {
                unset($this->recoveryCodes[$index]);
                $this->recoveryCodes = array_values($this->recoveryCodes);
                if ($this->recoveryCodes === []) {
                    $this->recoveryCodes = null;
                }
                $this->touch();

                return true;
            }
        }

        return false;
    }

    public function setPasskeyEnabled(bool $enabled): void
    {
        $this->passkeyEnabled = $enabled;
        $this->touch();
    }

    public function hasMfa(): bool
    {
        return $this->totpEnabled || $this->passkeyEnabled;
    }

    public function resetMfa(): void
    {
        $this->disableTotp();
        $this->passkeyEnabled = false;
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
        foreach ($this->roles as $role) {
            if ($role->isPrivileged()) {
                return true;
            }
        }

        return false;
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
        $first = trim((string) $this->firstName);
        $last = trim((string) $this->lastName);
        if ($first !== '' || $last !== '') {
            $letters = '';
            if ($first !== '') {
                $letters .= mb_strtoupper(mb_substr($first, 0, 1));
            }
            if ($last !== '') {
                $letters .= mb_strtoupper(mb_substr($last, 0, 1));
            }

            return $letters !== '' ? $letters : 'G';
        }

        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $letters = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $letters !== '' ? $letters : 'G';
    }

    private function syncDisplayName(): void
    {
        $full = trim(implode(' ', array_filter([
            $this->firstName,
            $this->lastName,
        ], static fn (?string $part): bool => $part !== null && trim($part) !== '')));
        if ($full !== '') {
            $this->name = $full;
        }
        $this->touch();
    }

    private function splitNameIntoParts(string $name): void
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if ($parts === [] || ($parts[0] ?? '') === '') {
            return;
        }
        if ($this->firstName === null && $this->lastName === null) {
            $this->firstName = array_shift($parts) ?: null;
            $this->lastName = $parts !== [] ? implode(' ', $parts) : null;
        }
    }

    private static function nullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
