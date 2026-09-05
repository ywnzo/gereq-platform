<?php

declare(strict_types=1);

namespace Gereq\Api\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'api_tokens')]
class ApiToken
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(name: 'token_prefix', length: 24)]
    private string $tokenPrefix;

    #[ORM\Column(name: 'token_hash', length: 64)]
    private string $tokenHash;

    #[ORM\Column(name: 'user_id', length: 36)]
    private string $userId;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $scopes = [];

    #[ORM\Column(name: 'last_used_at', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'expires_at', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'revoked_at', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string> $scopes
     */
    public function __construct(string $name, string $userId, string $tokenPrefix, string $tokenHash, array $scopes = [])
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = $name;
        $this->userId = $userId;
        $this->tokenPrefix = $tokenPrefix;
        $this->tokenHash = $tokenHash;
        $this->scopes = array_values($scopes);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function tokenPrefix(): string
    {
        return $this->tokenPrefix;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function lastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isActive(): bool
    {
        if ($this->revokedAt !== null) {
            return false;
        }
        if ($this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable()) {
            return false;
        }

        return true;
    }

    public function hasScope(string $scope): bool
    {
        if (in_array('*', $this->scopes, true) || in_array('full', $this->scopes, true)) {
            return true;
        }
        if (in_array($scope, $this->scopes, true)) {
            return true;
        }
        // customers.read covers when asking customers.read
        $parts = explode('.', $scope);
        if (count($parts) === 2) {
            $wildcard = $parts[0] . '.*';
            if (in_array($wildcard, $this->scopes, true)) {
                return true;
            }
        }

        return false;
    }

    public function hasAnyScope(array $scopes): bool
    {
        if ($scopes === []) {
            return true;
        }
        foreach ($scopes as $scope) {
            if ($this->hasScope((string) $scope)) {
                return true;
            }
        }

        return false;
    }

    public function markUsed(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }

    public function revoke(): void
    {
        $this->revokedAt = new \DateTimeImmutable();
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    /** @param list<string> $scopes */
    public function setScopes(array $scopes): void
    {
        $this->scopes = array_values($scopes);
    }
}
