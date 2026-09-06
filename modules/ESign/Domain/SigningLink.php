<?php

declare(strict_types=1);

namespace Modules\ESign\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'esign_signing_links')]
#[ORM\Index(name: 'idx_esign_signing_links_document', columns: ['document_id'])]
#[ORM\Index(name: 'idx_esign_signing_links_token_hash', columns: ['token_hash'])]
class SigningLink
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'document_id', length: 36)]
    private string $documentId;

    #[ORM\Column(name: 'token_hash', length: 64)]
    private string $tokenHash;

    #[ORM\Column(name: 'token_encrypted', type: 'text', nullable: true)]
    private ?string $tokenEncrypted = null;

    #[ORM\Column(length: 190)]
    private string $email;

    #[ORM\Column(name: 'expires_at')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'sent_at', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(name: 'used_at', nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(name: 'revoked_at', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(
        string $documentId,
        string $tokenHash,
        string $email,
        \DateTimeImmutable $expiresAt,
        ?string $tokenEncrypted = null,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->documentId = $documentId;
        $this->tokenHash = $tokenHash;
        $this->email = strtolower(trim($email));
        $this->expiresAt = $expiresAt;
        $this->tokenEncrypted = $tokenEncrypted;
        $this->sentAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function documentId(): string
    {
        return $this->documentId;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function tokenEncrypted(): ?string
    {
        return $this->tokenEncrypted;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function sentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function usedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isUsable(): bool
    {
        if ($this->usedAt !== null || $this->revokedAt !== null) {
            return false;
        }

        return $this->expiresAt > new \DateTimeImmutable();
    }

    public function markUsed(): void
    {
        $this->usedAt = new \DateTimeImmutable();
    }

    public function revoke(): void
    {
        if ($this->revokedAt === null && $this->usedAt === null) {
            $this->revokedAt = new \DateTimeImmutable();
        }
    }
}
