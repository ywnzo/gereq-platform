<?php

declare(strict_types=1);

namespace Gereq\Notifications\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'notification_stream_tokens')]
#[ORM\UniqueConstraint(name: 'uniq_notification_stream_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_notification_stream_account', columns: ['account_id'])]
class NotificationStreamToken
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'account_id', length: 36)]
    private string $accountId;

    #[ORM\Column(name: 'token_hash', length: 64)]
    private string $tokenHash;

    #[ORM\Column(name: 'expires_at')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $accountId, string $tokenHash, \DateTimeImmutable $expiresAt)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->accountId = $accountId;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function accountId(): string
    {
        return $this->accountId;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }
}
