<?php

declare(strict_types=1);

namespace Modules\Portals\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'portal_publications')]
#[ORM\UniqueConstraint(name: 'uniq_portal_pub_account_listing', columns: ['portal_account_id', 'listing_id'])]
#[ORM\Index(name: 'idx_portal_pub_status', columns: ['status'])]
#[ORM\Index(name: 'idx_portal_pub_listing', columns: ['listing_id'])]
class PortalPublication
{
    public const STATUSES = ['draft', 'queued', 'published', 'error', 'unpublished'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'portal_account_id', length: 36)]
    private string $portalAccountId;

    #[ORM\Column(name: 'listing_id', length: 36)]
    private string $listingId;

    #[ORM\Column(name: 'external_id', length: 190, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(length: 32)]
    private string $status = 'draft';

    #[ORM\Column(name: 'last_error', type: 'text', nullable: true)]
    private ?string $lastError = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'payload_snapshot', type: 'json', nullable: true)]
    private ?array $payloadSnapshot = null;

    #[ORM\Column(name: 'last_synced_at', nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $portalAccountId, string $listingId)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->portalAccountId = $portalAccountId;
        $this->listingId = $listingId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function portalAccountId(): string
    {
        return $this->portalAccountId;
    }

    public function listingId(): string
    {
        return $this->listingId;
    }

    public function externalId(): ?string
    {
        return $this->externalId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** @return array<string, mixed>|null */
    public function payloadSnapshot(): ?array
    {
        return $this->payloadSnapshot;
    }

    public function lastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setExternalId(?string $externalId): void
    {
        $this->externalId = $externalId !== null && $externalId !== '' ? $externalId : null;
        $this->touch();
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('portals.error.invalid_status');
        }
        $this->status = $status;
        $this->touch();
    }

    public function setLastError(?string $lastError): void
    {
        $this->lastError = $lastError !== null && $lastError !== '' ? $lastError : null;
        $this->touch();
    }

    /** @param array<string, mixed>|null $payload */
    public function setPayloadSnapshot(?array $payload): void
    {
        $this->payloadSnapshot = $payload;
        $this->touch();
    }

    public function markSynced(?string $externalId = null): void
    {
        if ($externalId !== null) {
            $this->setExternalId($externalId);
        }
        $this->status = 'published';
        $this->lastError = null;
        $this->lastSyncedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function markQueued(): void
    {
        $this->status = 'queued';
        $this->lastError = null;
        $this->touch();
    }

    public function markError(string $message): void
    {
        $this->status = 'error';
        $this->lastError = $message;
        $this->touch();
    }

    public function statusLabel(): string
    {
        return 'portals.pub.status.' . $this->status;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
