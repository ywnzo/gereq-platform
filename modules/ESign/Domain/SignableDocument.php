<?php

declare(strict_types=1);

namespace Modules\ESign\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'esign_documents')]
#[ORM\Index(name: 'idx_esign_documents_status', columns: ['status'])]
#[ORM\Index(name: 'idx_esign_documents_number', columns: ['number'])]
#[ORM\Index(name: 'idx_esign_documents_source', columns: ['source_type', 'source_id'])]
class SignableDocument
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENT,
        self::STATUS_SIGNED,
        self::STATUS_CANCELLED,
    ];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 64)]
    private string $number;

    #[ORM\Column(length: 190)]
    private string $title;

    #[ORM\Column(name: 'body_html', type: 'text')]
    private string $bodyHtml;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(name: 'counterparty_name', length: 190)]
    private string $counterpartyName;

    #[ORM\Column(name: 'counterparty_email', length: 190, nullable: true)]
    private ?string $counterpartyEmail = null;

    #[ORM\Column(name: 'counterparty_company', length: 190, nullable: true)]
    private ?string $counterpartyCompany = null;

    #[ORM\Column(name: 'provider_signature_path', length: 255, nullable: true)]
    private ?string $providerSignaturePath = null;

    #[ORM\Column(name: 'signed_by', length: 190, nullable: true)]
    private ?string $signedBy = null;

    #[ORM\Column(name: 'signature_hash', length: 64, nullable: true)]
    private ?string $signatureHash = null;

    #[ORM\Column(name: 'locked_at', nullable: true)]
    private ?\DateTimeImmutable $lockedAt = null;

    #[ORM\Column(name: 'locked_hash', length: 64, nullable: true)]
    private ?string $lockedHash = null;

    #[ORM\Column(name: 'locked_html', type: 'text', nullable: true)]
    private ?string $lockedHtml = null;

    #[ORM\Column(name: 'executed_html', type: 'text', nullable: true)]
    private ?string $executedHtml = null;

    #[ORM\Column(name: 'source_type', length: 64, nullable: true)]
    private ?string $sourceType = null;

    #[ORM\Column(name: 'source_id', length: 36, nullable: true)]
    private ?string $sourceId = null;

    #[ORM\Column(name: 'signed_at', nullable: true)]
    private ?\DateTimeImmutable $signedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $number, string $title, string $bodyHtml, string $counterpartyName)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->number = $number;
        $this->title = $title;
        $this->bodyHtml = $bodyHtml;
        $this->counterpartyName = $counterpartyName;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function number(): string
    {
        return $this->number;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function bodyHtml(): string
    {
        return $this->bodyHtml;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function counterpartyName(): string
    {
        return $this->counterpartyName;
    }

    public function counterpartyEmail(): ?string
    {
        return $this->counterpartyEmail;
    }

    public function counterpartyCompany(): ?string
    {
        return $this->counterpartyCompany;
    }

    public function providerSignaturePath(): ?string
    {
        return $this->providerSignaturePath;
    }

    public function signedBy(): ?string
    {
        return $this->signedBy;
    }

    public function signatureHash(): ?string
    {
        return $this->signatureHash;
    }

    public function lockedAt(): ?\DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function lockedHash(): ?string
    {
        return $this->lockedHash;
    }

    public function lockedHtml(): ?string
    {
        return $this->lockedHtml;
    }

    public function executedHtml(): ?string
    {
        return $this->executedHtml;
    }

    public function sourceType(): ?string
    {
        return $this->sourceType;
    }

    public function sourceId(): ?string
    {
        return $this->sourceId;
    }

    public function signedAt(): ?\DateTimeImmutable
    {
        return $this->signedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isLocked(): bool
    {
        return $this->lockedAt !== null;
    }

    public function isFullySigned(): bool
    {
        return $this->status === self::STATUS_SIGNED;
    }

    public function statusLabel(): string
    {
        return 'esign.status.' . $this->status;
    }

    public function setTitle(string $title): void
    {
        $this->assertEditable();
        $this->title = $title;
        $this->touch();
    }

    public function setBodyHtml(string $bodyHtml): void
    {
        $this->assertEditable();
        $this->bodyHtml = $bodyHtml;
        $this->touch();
    }

    public function setCounterpartyName(string $name): void
    {
        $this->assertEditable();
        $this->counterpartyName = $name;
        $this->touch();
    }

    public function setCounterpartyEmail(?string $email): void
    {
        $this->counterpartyEmail = $email !== null && $email !== '' ? strtolower(trim($email)) : null;
        $this->touch();
    }

    public function setCounterpartyCompany(?string $company): void
    {
        $this->assertEditable();
        $this->counterpartyCompany = $company !== null && trim($company) !== '' ? trim($company) : null;
        $this->touch();
    }

    public function setSource(?string $type, ?string $id): void
    {
        $type = $type !== null ? trim($type) : '';
        $id = $id !== null ? trim($id) : '';
        $this->sourceType = $type !== '' ? $type : null;
        $this->sourceId = $id !== '' ? $id : null;
        $this->touch();
    }

    public function applyProviderSignature(?string $path, string $signedBy, string $hash): void
    {
        $this->providerSignaturePath = $path;
        $this->signedBy = $signedBy;
        $this->signatureHash = $hash;
        $this->touch();
    }

    public function lock(string $html, string $hash): void
    {
        $this->lockedAt = new \DateTimeImmutable();
        $this->lockedHtml = $html;
        $this->lockedHash = $hash;
        $this->signatureHash = $hash;
        $this->status = self::STATUS_SENT;
        $this->touch();
    }

    public function markSigned(string $executedHtml): void
    {
        $this->executedHtml = $executedHtml;
        $this->signedAt = new \DateTimeImmutable();
        $this->status = self::STATUS_SIGNED;
        $this->touch();
    }

    public function cancelSigning(): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->lockedAt = null;
        $this->lockedHash = null;
        $this->lockedHtml = null;
        $this->executedHtml = null;
        $this->signedAt = null;
        $this->touch();
    }

    public function reopenDraft(): void
    {
        $this->status = self::STATUS_DRAFT;
        $this->touch();
    }

    private function assertEditable(): void
    {
        if ($this->isLocked() || $this->isFullySigned()) {
            throw new \RuntimeException('esign.error.locked');
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
