<?php

declare(strict_types=1);

namespace Modules\Emailing\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'emailing_outbox')]
#[ORM\Index(name: 'idx_emailing_outbox_status', columns: ['status'])]
class OutboxMessage
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'account_id', length: 36)]
    private string $accountId;

    #[ORM\Column(name: 'to_addresses', type: 'text')]
    private string $toAddresses;

    #[ORM\Column(name: 'cc_addresses', type: 'text', nullable: true)]
    private ?string $ccAddresses = null;

    #[ORM\Column(length: 500)]
    private string $subject;

    #[ORM\Column(name: 'body_html', type: 'text', nullable: true)]
    private ?string $bodyHtml = null;

    #[ORM\Column(name: 'body_text', type: 'text', nullable: true)]
    private ?string $bodyText = null;

    #[ORM\Column(name: 'in_reply_to', length: 255, nullable: true)]
    private ?string $inReplyTo = null;

    #[ORM\Column(name: 'references_raw', type: 'text', nullable: true)]
    private ?string $referencesRaw = null;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'sent_at', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $accountId, string $toAddresses, string $subject)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->accountId = $accountId;
        $this->toAddresses = $toAddresses;
        $this->subject = $subject;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function accountId(): string
    {
        return $this->accountId;
    }

    public function toAddresses(): string
    {
        return $this->toAddresses;
    }

    public function ccAddresses(): ?string
    {
        return $this->ccAddresses;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function bodyHtml(): ?string
    {
        return $this->bodyHtml;
    }

    public function bodyText(): ?string
    {
        return $this->bodyText;
    }

    public function inReplyTo(): ?string
    {
        return $this->inReplyTo;
    }

    public function referencesRaw(): ?string
    {
        return $this->referencesRaw;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function sentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setBody(?string $html, ?string $text): void
    {
        $this->bodyHtml = $html;
        $this->bodyText = $text;
        $this->touch();
    }

    public function setCc(?string $cc): void
    {
        $this->ccAddresses = $cc !== '' ? $cc : null;
        $this->touch();
    }

    public function setThreadHeaders(?string $inReplyTo, ?string $referencesRaw): void
    {
        $this->inReplyTo = $inReplyTo !== null && $inReplyTo !== '' ? $inReplyTo : null;
        $this->referencesRaw = $referencesRaw !== null && $referencesRaw !== '' ? $referencesRaw : null;
        $this->touch();
    }

    public function markSending(): void
    {
        $this->status = self::STATUS_SENDING;
        $this->touch();
    }

    public function markSent(): void
    {
        $this->status = self::STATUS_SENT;
        $this->sentAt = new \DateTimeImmutable();
        $this->errorMessage = null;
        $this->touch();
    }

    public function markFailed(string $error): void
    {
        $this->status = self::STATUS_FAILED;
        $this->errorMessage = $error;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
