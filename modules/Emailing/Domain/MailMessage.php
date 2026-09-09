<?php

declare(strict_types=1);

namespace Modules\Emailing\Domain;

use Doctrine\ORM\Mapping as ORM;
use Modules\Emailing\Application\ImapKeyword;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'emailing_messages')]
#[ORM\UniqueConstraint(name: 'uniq_emailing_msg_uid', columns: ['folder_id', 'uid'])]
#[ORM\Index(name: 'idx_emailing_messages_folder', columns: ['folder_id'])]
#[ORM\Index(name: 'idx_emailing_messages_account', columns: ['account_id'])]
#[ORM\Index(name: 'idx_emailing_messages_date', columns: ['date_at'])]
#[ORM\Index(name: 'idx_emailing_messages_thread', columns: ['account_id', 'thread_id'])]
#[ORM\Index(name: 'idx_emailing_messages_msgid', columns: ['account_id', 'message_id'])]
class MailMessage
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(name: 'account_id', length: 36)]
    private string $accountId;

    #[ORM\Column(name: 'folder_id', length: 36)]
    private string $folderId;

    #[ORM\Column]
    private int $uid;

    #[ORM\Column(name: 'message_id', length: 255, nullable: true)]
    private ?string $messageId = null;

    #[ORM\Column(name: 'in_reply_to', length: 255, nullable: true)]
    private ?string $inReplyTo = null;

    #[ORM\Column(name: 'references_raw', type: 'text', nullable: true)]
    private ?string $referencesRaw = null;

    #[ORM\Column(name: 'thread_id', length: 120, nullable: true)]
    private ?string $threadId = null;

    #[ORM\Column(name: 'thread_subject', length: 500, nullable: true)]
    private ?string $threadSubject = null;

    #[ORM\Column(name: 'from_address', length: 255, nullable: true)]
    private ?string $fromAddress = null;

    #[ORM\Column(name: 'from_name', length: 190, nullable: true)]
    private ?string $fromName = null;

    #[ORM\Column(name: 'to_addresses', type: 'text', nullable: true)]
    private ?string $toAddresses = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $snippet = null;

    #[ORM\Column(name: 'date_at', nullable: true)]
    private ?\DateTimeImmutable $dateAt = null;

    #[ORM\Column(name: 'is_seen')]
    private bool $isSeen = false;

    #[ORM\Column(name: 'is_flagged')]
    private bool $isFlagged = false;

    #[ORM\Column(name: 'has_attachments')]
    private bool $hasAttachments = false;

    #[ORM\Column(name: 'has_body')]
    private bool $hasBody = false;

    /** @var list<string>|null IMAP KEYWORDS (bez systémových flagů). */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $keywords = null;

    #[ORM\Column(name: 'is_pinned', options: ['default' => false])]
    private bool $isPinned = false;

    #[ORM\Column(name: 'size_bytes', nullable: true)]
    private ?int $sizeBytes = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $accountId, string $folderId, int $uid)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->accountId = $accountId;
        $this->folderId = $folderId;
        $this->uid = $uid;
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

    public function folderId(): string
    {
        return $this->folderId;
    }

    public function uid(): int
    {
        return $this->uid;
    }

    public function messageId(): ?string
    {
        return $this->messageId;
    }

    public function inReplyTo(): ?string
    {
        return $this->inReplyTo;
    }

    public function referencesRaw(): ?string
    {
        return $this->referencesRaw;
    }

    public function threadId(): ?string
    {
        return $this->threadId;
    }

    public function threadSubject(): ?string
    {
        return $this->threadSubject;
    }

    public function fromAddress(): ?string
    {
        return $this->fromAddress;
    }

    public function fromName(): ?string
    {
        return $this->fromName;
    }

    public function toAddresses(): ?string
    {
        return $this->toAddresses;
    }

    public function subject(): ?string
    {
        return $this->subject;
    }

    public function snippet(): ?string
    {
        return $this->snippet;
    }

    public function dateAt(): ?\DateTimeImmutable
    {
        return $this->dateAt;
    }

    public function isSeen(): bool
    {
        return $this->isSeen;
    }

    public function isFlagged(): bool
    {
        return $this->isFlagged;
    }

    public function hasAttachments(): bool
    {
        return $this->hasAttachments;
    }

    public function hasBody(): bool
    {
        return $this->hasBody;
    }

    /** @return list<string> */
    public function keywords(): array
    {
        return $this->keywords ?? [];
    }

    public function isPinned(): bool
    {
        return $this->isPinned;
    }

    public function sizeBytes(): ?int
    {
        return $this->sizeBytes;
    }

    public function applyHeaders(
        ?string $messageId,
        ?string $fromAddress,
        ?string $fromName,
        ?string $toAddresses,
        ?string $subject,
        ?string $snippet,
        ?\DateTimeImmutable $dateAt,
        bool $isSeen,
        bool $isFlagged,
        bool $hasAttachments,
        ?int $sizeBytes,
    ): void {
        $this->messageId = $messageId;
        $this->fromAddress = $fromAddress;
        $this->fromName = $fromName;
        $this->toAddresses = $toAddresses;
        $this->subject = $subject;
        $this->snippet = $snippet;
        $this->dateAt = $dateAt;
        $this->isSeen = $isSeen;
        $this->isFlagged = $isFlagged;
        $this->hasAttachments = $hasAttachments;
        $this->sizeBytes = $sizeBytes;
        $this->touch();
    }

    public function applyThread(
        ?string $inReplyTo,
        ?string $referencesRaw,
        string $threadId,
        ?string $threadSubject,
    ): void {
        $this->inReplyTo = $inReplyTo;
        $this->referencesRaw = $referencesRaw;
        $this->threadId = strlen($threadId) <= 120 ? $threadId : ('x:' . substr(hash('sha256', $threadId), 0, 40));
        $this->threadSubject = $threadSubject;
        $this->touch();
    }

    public function markBodyCached(): void
    {
        $this->hasBody = true;
        $this->touch();
    }

    public function clearBodyCache(): void
    {
        $this->hasBody = false;
        $this->touch();
    }

    public function setSeen(bool $seen): void
    {
        $this->isSeen = $seen;
        $this->touch();
    }

    public function setFlagged(bool $flagged): void
    {
        $this->isFlagged = $flagged;
        $this->touch();
    }

    /** @param list<string> $keywords */
    public function setKeywords(array $keywords): void
    {
        $normalized = [];
        foreach ($keywords as $kw) {
            $n = ImapKeyword::normalize((string) $kw);
            if ($n !== null) {
                $normalized[] = $n;
            }
        }
        $normalized = array_values(array_unique($normalized));
        $this->keywords = $normalized !== [] ? $normalized : null;
        $this->isPinned = in_array(ImapKeyword::PINNED, $normalized, true);
        $this->touch();
    }

    public function setPinned(bool $pinned): void
    {
        $keywords = $this->keywords();
        if ($pinned && !in_array(ImapKeyword::PINNED, $keywords, true)) {
            $keywords[] = ImapKeyword::PINNED;
        }
        if (!$pinned) {
            $keywords = array_values(array_filter(
                $keywords,
                static fn (string $k): bool => $k !== ImapKeyword::PINNED,
            ));
        }
        $this->setKeywords($keywords);
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
