<?php

declare(strict_types=1);

namespace Gereq\Auth\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gereq\User\Entity\User;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'webauthn_credentials')]
#[ORM\UniqueConstraint(name: 'uniq_webauthn_credential_id', columns: ['credential_id'])]
class WebAuthnCredential
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Base64-encoded credential ID (binary). */
    #[ORM\Column(name: 'credential_id', length: 1024)]
    private string $credentialId;

    #[ORM\Column(name: 'public_key', type: 'text')]
    private string $publicKey;

    #[ORM\Column(name: 'sign_count')]
    private int $signCount = 0;

    #[ORM\Column(length: 120)]
    private string $name;

    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $transports = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $aaguid = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_used_at', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(
        User $user,
        string $credentialId,
        string $publicKey,
        string $name,
        int $signCount = 0,
        ?array $transports = null,
        ?string $aaguid = null,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->user = $user;
        $this->credentialId = $credentialId;
        $this->publicKey = $publicKey;
        $this->name = trim($name) !== '' ? trim($name) : 'Passkey';
        $this->signCount = max(0, $signCount);
        $this->transports = $transports;
        $this->aaguid = $aaguid;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function credentialId(): string
    {
        return $this->credentialId;
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    public function signCount(): int
    {
        return $this->signCount;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<string>|null */
    public function transports(): ?array
    {
        return $this->transports;
    }

    public function aaguid(): ?string
    {
        return $this->aaguid;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setName(string $name): void
    {
        $this->name = trim($name) !== '' ? trim($name) : $this->name;
    }

    public function markUsed(int $signCount): void
    {
        $this->signCount = max(0, $signCount);
        $this->lastUsedAt = new \DateTimeImmutable();
    }
}
